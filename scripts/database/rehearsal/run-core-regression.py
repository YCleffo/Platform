"""Run the whole Core suite from frozen Git-visible source on fresh test services.

Prerequisites: images platform-cross-plane-test:latest (locked dev vendor) and
platform-upgrade-core:local (built by upgrade-rehearsal.py). No original DB access.
"""
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import uuid

ROOT=Path(__file__).resolve().parents[3]


def main():
    run=uuid.uuid4().hex[:12]
    project='platform-upgrade-regression-'+run
    private=ROOT/'runtime/database-upgrade'/run
    private.mkdir(parents=True)
    if os.name=='nt':
        account=subprocess.run(['whoami'],capture_output=True,text=True,check=True).stdout.strip()
        subprocess.run(['icacls',str(private),'/inheritance:r','/grant:r',account+':(OI)(CI)F','*S-1-5-18:(OI)(CI)F'],capture_output=True,check=True)
    context=private/'context';context.mkdir()
    visible=subprocess.run(['git','ls-files','-z','--cached','--others','--exclude-standard'],cwd=ROOT,capture_output=True,check=True).stdout.decode('utf-8').split('\0')
    manifest=[]
    for relative in sorted(set(visible)):
        if relative.startswith('apps/backend/'):target=context/'app'/relative.removeprefix('apps/backend/')
        elif relative.startswith('contracts/'):target=context/relative
        else:continue
        source=ROOT/relative
        if not source.exists():continue
        if source.is_symlink() or ROOT not in source.resolve().parents:raise RuntimeError('Unsafe source context path')
        if source.name=='.env' or (source.name.startswith('.env.') and source.name!='.env.example'):
            raise RuntimeError('Refusing a credential file in the build context')
        target.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,target)
        manifest.append({'path':relative,'sha256':hashlib.sha256(source.read_bytes()).hexdigest()})
    for source,target in [(ROOT/'infra/postgres/migrate.sh',context/'migrate.sh'),(Path(__file__).with_name('Dockerfile.core-regression'),context/'Dockerfile')]:
        shutil.copyfile(source,target)
        manifest.append({'path':source.relative_to(ROOT).as_posix(),'sha256':hashlib.sha256(source.read_bytes()).hexdigest()})
    (private/'source-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
    password=secrets.token_hex(32)
    labels={'platform.rehearsal':'true','platform.rehearsal.run':run}
    database={'POSTGRES_DB':'platform_test','POSTGRES_USER':'regression_app','POSTGRES_PASSWORD':password}
    env={'APP_ENV':'test','APP_DEBUG':'false','TEST_ISOLATED_RUNTIME':'1','DB_HOST':'db','DB_PORT':'5432','DB_NAME':database['POSTGRES_DB'],'DB_USER':database['POSTGRES_USER'],'DB_PASSWORD':password,
         'REDIS_HOST':'redis-test','REDIS_PORT':'6379','AUTH_TOTP_ENCRYPTION_KEY':'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
         'AUTH_IDENTIFIER_HMAC_KEY':'synthetic-regression-not-production-32','DEVICE_FINGERPRINT_KEY':'synthetic-regression-not-production-32',
         'CORE_SCHEDULER_ENABLED':'true','CORE_EMAIL_WORKER_ENABLED':'true','SMTP_HOST':'mailpit','SMTP_PORT':'1025',
         'MAIL_FROM_ADDRESS':'no-reply@example.test','MAIL_FROM_NAME':'Regression','SERVER_NAME':':80'}
    config={'services':{
        'db':{'image':'postgres:18','labels':labels,'environment':database,'command':['postgres','-c','cluster_name=platform-upgrade-regression'],
              'volumes':['test_db:/var/lib/postgresql'],'healthcheck':{'test':['CMD-SHELL','pg_isready -U regression_app -d platform_test'],'interval':'2s','timeout':'2s','retries':30}},
        'redis-test':{'image':'redis:8-alpine','labels':labels,'command':['redis-server','--save','','--appendonly','no']},
        'mailpit':{'image':'axllent/mailpit:v1.31.1','labels':labels},
        'migrate':{'image':'postgres:18','labels':labels,'environment':dict(database,PGHOST='db'),
                   'volumes':[str(context/'app/migrations/sql')+':/migrations:ro',str(context/'migrate.sh')+':/migrate.sh:ro'],'entrypoint':['bash','/migrate.sh']},
        'app':{'image':'platform-upgrade-core-regression:'+run,'build':{'context':str(context)},'labels':labels,'environment':env}},
        'volumes':{'test_db':{}},'networks':{'default':{'internal':True}}}
    compose=private/'compose.json';compose.write_text(json.dumps(config,indent=2),encoding='utf-8')
    base=['docker','compose','-p',project,'-f',str(compose)]
    result={'run_id':run,'project':project,'started_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),'database':database['POSTGRES_DB'],
            'source_files':len(manifest),'runtime':'fresh isolated PostgreSQL and frozen source inside image; no source bind mount',
            'roles':'test-only bootstrap role for guarded TestReset; not least-privilege runtime evidence','status':'RUNNING'}
    print('Core regression '+run+'; private outputs '+str(private),flush=True)
    def command(label,*args,timeout=360):
        with (private/(label+'.stdout')).open('wb') as out,(private/(label+'.stderr')).open('wb') as err:
            p=subprocess.run(base+list(args),cwd=ROOT,stdout=out,stderr=err,timeout=timeout)
        if p.returncode:raise RuntimeError(label+' failed; inspect private diagnostics')
        return (private/(label+'.stdout')).read_text(encoding='utf-8',errors='replace')
    try:
        command('build','build','app')
        command('infrastructure','up','-d','--wait','db','redis-test','mailpit')
        identity=command('database-marker','exec','-T','db','psql','-X','-qAt','-U','regression_app','-d','platform_test','-c',"SELECT current_database() || ':' || current_setting('cluster_name');")
        if identity.strip()!='platform_test:platform-upgrade-regression':raise RuntimeError('Regression DB marker mismatch')
        command('migrate','run','--rm','--no-deps','migrate')
        command('application','up','-d','app')
        # The suite itself tests missing worker heartbeats; do not disable those
        # checks to make a workerless test web server report ready.
        ready="for($i=0;$i<30;$i++){if(@file_get_contents('http://127.0.0.1/health/live')!==false)exit(0);usleep(200000);}exit(1);"
        command('ready','exec','-T','app','php','-r',ready)
        output=command('core-regression','exec','-T','app','php','vendor/bin/codecept','run','--seed=2120741368','--no-colors',timeout=1800)
        summary=re.search(r'OK \((\d+) tests?, (\d+) assertions?\)',output)
        if not summary:raise RuntimeError('Missing full-suite success summary')
        result.update(status='PASS',tests=int(summary[1]),assertions=int(summary[2]),seed=2120741368)
    except Exception as error:
        result.update(status='FAIL',failure=str(error))
    finally:
        try:command('stop','stop',timeout=60)
        except Exception:result['stop_failed']=True
        result['finished_utc']=datetime.datetime.now(datetime.timezone.utc).isoformat()
        (private/'result.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(result),flush=True)
    raise SystemExit(0 if result['status']=='PASS' else 1)


if __name__=='__main__':main()
