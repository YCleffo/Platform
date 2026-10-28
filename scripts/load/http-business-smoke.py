"""Exercise real local HTTP, SMTP/Mailpit, MFA and a free purchase.

Only compose.load.yaml URLs are used. Never prints tokens, passwords or secrets.
Run after seeding and when k6 is stopped; rate limits remain enabled.
"""
import base64
import hashlib
import hmac
import json
from pathlib import Path
import re
import struct
import subprocess
import time
import urllib.error
import urllib.request
import uuid

ROOT=Path(__file__).resolve().parents[2]
API='http://127.0.0.1:18082'
MAIL='http://127.0.0.1:18025'
steps=[]


def require(condition,label):
    if not condition:
        raise RuntimeError(label)
    steps.append(label)


def request(method,path,body=None,token=None,key=None,status=200):
    headers={'Content-Type':'application/json'}
    if token:
        headers['Authorization']='Bearer '+token
    if key:
        headers['Idempotency-Key']=key
    req=urllib.request.Request(API+path,data=None if body is None else json.dumps(body).encode(),method=method,headers=headers)
    try:
        response=urllib.request.urlopen(req,timeout=30)
    except urllib.error.HTTPError as error:
        response=error
    with response:
        raw=response.read()
        result=json.loads(raw) if raw else None
        actual=response.status
        require(actual==status,method+' '+path+' status '+str(status)+' (actual '+str(actual)+')')
        if path.startswith('/api/control/auth'):
            require('no-store' in response.headers.get('Cache-Control',''),'sensitive auth response has no-store')
        return result


def process_mail():
    result=subprocess.run(['docker','compose','-f','compose.load.yaml','exec','-T','control','php','bin/console','control:outbox:process-email','--limit=20'],cwd=ROOT,capture_output=True,timeout=60)
    require(result.returncode==0,'actual email worker succeeded')


def messages(email):
    with urllib.request.urlopen(MAIL+'/api/v1/messages',timeout=10) as response:
        rows=json.load(response)['messages']
    return [row for row in rows if any(to.get('Address','').lower()==email for to in row.get('To',[]))]


def mail_token(email,prefix):
    candidates=[]
    for row in messages(email):
        with urllib.request.urlopen(MAIL+'/api/v1/message/'+row['ID'],timeout=10) as response:
            message=json.load(response)
        found=re.search(re.escape(prefix)+r'\.[a-f0-9-]{36}\.[A-Za-z0-9_-]{43}',message.get('Text',''))
        if found:
            candidates.append(found.group())
    require(len(candidates)==1,'one SMTP-delivered '+prefix+' link')
    return candidates[0]


def main():
    suffix=uuid.uuid4().hex[:10]
    email='http-audit-'+suffix+'@example.test'
    password='Disposable initial audit password 2026'
    replacement='Disposable replacement audit password 2026'
    auth='/api/control/auth'
    registered=request('POST',auth+'/register',{'email':email,'password':password},status=202)
    request('POST',auth+'/login',{'email':email,'password':password},status=401)
    process_mail()
    verification=mail_token(email,'v1')
    before=len(messages(email))
    process_mail()
    require(len(messages(email))==before,'published email not delivered twice on worker replay')
    request('POST',auth+'/verify',{'token':verification})
    request('POST',auth+'/verify',{'token':verification},status=400)
    first=request('POST',auth+'/login',{'email':email.upper(),'password':password})
    rotated=request('POST',auth+'/refresh',{'refresh_token':first['refresh_token']})
    request('POST',auth+'/refresh',{'refresh_token':first['refresh_token']},status=401)
    request('GET',auth+'/sessions',token=rotated['access_token'],status=401)
    session=request('POST',auth+'/login',{'email':email,'password':password})
    request('POST',auth+'/password/reset/request',{'email':email},status=202)
    process_mail()
    reset=mail_token(email,'r1')
    request('POST',auth+'/password/reset/confirm',{'token':reset,'password':replacement})
    request('POST',auth+'/password/reset/confirm',{'token':reset,'password':replacement},status=400)
    request('GET',auth+'/sessions',token=session['access_token'],status=401)
    request('POST',auth+'/login',{'email':email,'password':password},status=401)
    session=request('POST',auth+'/login',{'email':email,'password':replacement})
    token=session['access_token']
    account_body={'code':'http-audit-'+suffix,'display_name':'HTTP audit'}
    account=request('POST','/api/control/accounts',account_body,token,'http-account-'+suffix,status=201)['id']
    replay=request('POST','/api/control/accounts',account_body,token,'http-account-'+suffix,status=201)
    require(replay['id']==account,'same account on idempotent HTTP replay')
    base='/api/control/accounts/'+account
    request('GET','/api/control/accounts/40000000-0000-4000-8000-000000000001',token=token,status=404)
    billing={'legal_name':'HTTP fixture','country_code':'RU','contact_email':email}
    request('PUT',base+'/billing-profile',billing,token,status=403)
    setup=request('POST',auth+'/mfa/totp/setup',{},token,status=201)
    secret=setup['secret']
    raw=base64.b32decode(secret+'='*((8-len(secret)%8)%8))
    digest=hmac.new(raw,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest()
    offset=digest[-1]&15
    code=str((int.from_bytes(digest[offset:offset+4],'big')&0x7fffffff)%1000000).zfill(6)
    codes=request('POST',auth+'/mfa/totp/confirm',{'factor_id':setup['factor_id'],'code':code},token)['recovery_codes']
    require(len(codes)==10,'ten MFA recovery codes issued')
    request('POST',auth+'/reauthenticate',{'password':replacement,'recovery_code':codes[0]},token)
    request('POST',auth+'/reauthenticate',{'password':replacement,'recovery_code':codes[0]},token,status=400)
    # Both omitted address and {} are valid object defaults. [] is not an object.
    request('PUT',base+'/billing-profile',billing,token)
    request('PUT',base+'/billing-profile',{**billing,'address':{}},token)
    request('PUT',base+'/billing-profile',{**billing,'address':[]},token,status=422)
    request('POST',base+'/onboarding/catalog-reviewed',{},token)
    catalog=request('GET','/api/control/catalog/plans')['plans']
    prices={p['code']:p['prices'][0]['id'] for p in catalog}
    subscription=request('POST',base+'/subscriptions',{'plan_price_id':prices['load.free'],'quantity':1},token,'http-sub-'+suffix,status=201)['id']
    invoice=request('POST',base+'/subscriptions/'+subscription+'/invoice',{},token,'http-invoice-'+suffix,status=201)
    payment_path=base+'/invoices/'+invoice['id']+'/payment'
    payment=request('POST',payment_path,{},token,'http-payment-'+suffix,status=201)
    replay=request('POST',payment_path,{},token,'http-payment-'+suffix,status=201)
    require(payment['id']==replay['id'] and payment['status']=='succeeded','one free settlement on repeated public HTTP payment')
    rights=request('GET',base+'/entitlements',token=token)
    require(rights['features']['load.tier']=='free','free purchase grants matching Commercial rights')
    change=request('POST',base+'/subscriptions/'+subscription+'/plan-changes',{'plan_price_id':prices['load.standard'],'quantity':1},token,'http-upgrade-'+suffix,status=201)
    require(change['status']=='payment_required','paid upgrade requires payment')
    require(request('GET',base+'/entitlements',token=token)['features']['load.tier']=='free','unpaid upgrade retains old rights')
    request('POST',base+'/plan-changes/'+change['id']+'/cancel',{},token,'http-cancel-change-'+suffix)
    request('POST',base+'/subscriptions/'+subscription+'/auto-renew/cancel',{},token,'http-auto-renew-'+suffix)
    require(request('GET',base+'/entitlements',token=token)['features']['load.tier']=='free','auto-renew cancellation retains current paid-period rights')
    request('POST',auth+'/logout',{},token,status=204)
    request('GET',auth+'/sessions',token=token,status=401)
    process_mail()
    return {'status':'PASS','checks':len(steps),'steps':steps,'mail_messages':len(messages(email))}


if __name__=='__main__':
    output=ROOT/'runtime/load-results/http-business-smoke.json'
    try:
        result=main()
    except Exception as error:
        result={'status':'FAIL','checks':len(steps),'steps':steps,'failure':str(error)}
    output.write_text(json.dumps(result,indent=2),encoding='utf-8')
    print(json.dumps({k:v for k,v in result.items() if k!='steps'}))
    raise SystemExit(0 if result['status']=='PASS' else 1)
