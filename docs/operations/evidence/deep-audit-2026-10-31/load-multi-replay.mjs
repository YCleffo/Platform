// Sanitized replay of the audited workload. Imports/output and diagnostic formatting are adapted for evidence storage.
// Requires a freshly migrated disposable stack, seed-load-catalog.sql, explicit mutation markers and nonce.
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { evaluateMutationGate } from '../../../../apps/frontend/scripts/e2e-guard.mjs';
import { registerAndVerify } from '../../../../apps/frontend/scripts/e2e-support.mjs';
import { runStage, summarizeSamples } from '../../../../apps/frontend/scripts/load-support.mjs';
const gate=await evaluateMutationGate(process.env,{verifyRuntime:true});
if(!gate.allowed)throw Error('Isolation rejected: '+gate.reasons.join('; '));
const project=process.env.COMPOSE_PROJECT_NAME;
if(project!=='platform-deep-audit-20261031')throw Error('Wrong Compose project');
const pg=project+'-control-postgres-test-1';
if(execFileSync('docker',['inspect','--format','{{ index .Config.Labels "com.docker.compose.project" }}',pg],{encoding:'utf8'}).trim()!==project)throw Error('Wrong database owner');
const api=process.env.CONTROL_API_URL,password=process.env.E2E_PASSWORD,prefix=`audit${Date.now()}`,baseEmail=prefix+'@example.test';
await registerAndVerify({api,mailpit:process.env.MAILPIT_URL,email:baseEmail,password});
const sql=`BEGIN; DO $$ DECLARE u uuid; a uuid; h text; email text; BEGIN
IF current_database()<>'platform_commercial_test' THEN RAISE EXCEPTION 'Wrong database'; END IF;
SELECT password_hash INTO STRICT h FROM commercial.control_users WHERE email_normalized='${baseEmail}';
FOR n IN 0..9 LOOP
 email:='${prefix}-u'||n||'@example.test';
 INSERT INTO commercial.control_users(id,email,email_normalized,password_hash,status,password_changed_at,email_verified_at) VALUES(gen_random_uuid(),email,email,h,'active',now(),now()) RETURNING id INTO u;
 FOR k IN 0..99 LOOP
  INSERT INTO commercial.commercial_accounts(code,display_name,onboarding_state) VALUES('${prefix}-u'||n||'-a'||k,'Synthetic audit account','profile_required') RETURNING id INTO a;
  INSERT INTO commercial.commercial_account_memberships(control_user_id,commercial_account_id,role) VALUES(u,a,'owner');
 END LOOP;
END LOOP; END $$; COMMIT;`;
execFileSync('docker',['exec','-i',pg,'psql','-v','ON_ERROR_STOP=1','-U',process.env.CONTROL_TEST_USER,'-d','platform_commercial_test'],{input:sql,stdio:['pipe','ignore','pipe']});
async function request(path,{token,method='GET',payload,key}={}){
 const response=await fetch(api+path,{method,signal:AbortSignal.timeout(15000),headers:{'Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{ }),...(key?{'Idempotency-Key':key}:{})},body:payload?JSON.stringify(payload):undefined});
 return {response,body:await response.json()};
}
const users=[];
for(let n=0;n<10;n++){
 const email=`${prefix}-u${n}@example.test`,login=await request('/api/control/auth/login',{method:'POST',payload:{email,password}});
 if(login.response.status!==200||!login.body.access_token)throw Error('Login failed');
 const user={email,token:login.body.access_token,index:n},list=await request('/api/control/accounts?limit=20&offset=80',user);
 if(list.response.status!==200||list.body.total!==100||list.body.items.length!==20)throw Error('Pagination fixture failed');
 user.accountId=list.body.items[0].id;user.firstKey=crypto.randomUUID();users.push(user);
}
const foreign=await request('/api/control/accounts/'+users[1].accountId,users[0]);
if(foreign.response.status!==404)throw Error('Foreign account must return 404');
const routes=['catalog','identity','accounts-page','sessions'],stages=[],writeSamples=[],writeIds=new Set(),firstResults=[];
const budget={p95:1000,errorRate:.01,minRequests:100},writeBudget={p95:1000,errorRate:.01,minRequests:80};
const writerStarted=performance.now();let writerSeconds=0;
const writer=(async()=>{
 for(let round=0;round<8;round++){
  if(round)await new Promise(resolve=>setTimeout(resolve,20000));
  await Promise.all(users.map(async user=>{
   const start=performance.now();
   try{
    const payload={code:`${prefix}-w${user.index}-${round}`,display_name:'Disposable workload create'},key=round===0?user.firstKey:crypto.randomUUID();
    const {response,body}=await request('/api/control/accounts',{...user,method:'POST',payload,key});
    const ok=response.status===201&&typeof body.id==='string'&&!writeIds.has(body.id);
    if(ok)writeIds.add(body.id);if(round===0)firstResults[user.index]={id:body.id,payload};
    writeSamples.push({ok,status:response.status,ms:performance.now()-start});
   }catch{writeSamples.push({ok:false,status:'transport',ms:performance.now()-start})}
  }));
 }
 writerSeconds=(performance.now()-writerStarted)/1000;
})();
let sequence=0;
for(const [name,concurrency,durationMs] of [['baseline',10,30000],['extended-read-write',50,120000],['burst',100,15000],['recovery',10,30000]]){
 const stage=await runStage({concurrency,durationMs,budget,request:async()=>{
  const index=sequence++,user=users[Math.floor(index/routes.length)%users.length],route=routes[index%routes.length];
  const path={catalog:'/api/control/catalog/plans',identity:'/api/control/me','accounts-page':'/api/control/accounts?limit=20&offset=80',sessions:'/api/control/auth/sessions'}[route];
  try{
   const {response,body}=await request(path,user);
   const valid=route==='catalog'?Array.isArray(body.plans)&&body.plans.length>=3:route==='identity'?body.email===user.email:route==='accounts-page'?Array.isArray(body.items)&&body.items.length===20&&body.items.every(x=>x.code.startsWith(`${prefix}-u${user.index}-`))&&body.total>=100:Array.isArray(body.items)&&body.items.some(x=>x.current);
   return {ok:response.status===200&&valid,status:response.status,route};
  }catch{return {ok:false,status:'transport',route}}
 }});stages.push({name,...stage});console.log(name,stage.passed);
}
await writer;const checks=[];
for(const user of users){
 const original=firstResults[user.index],replay=await request('/api/control/accounts',{...user,method:'POST',payload:original.payload,key:user.firstKey});
 const tenth=await request('/api/control/accounts',{...user,method:'POST',payload:{code:`${prefix}-last${user.index}`,display_name:'Final permitted create'},key:crypto.randomUUID()});
 const limited=await request('/api/control/accounts',{...user,method:'POST',payload:{code:`${prefix}-limited${user.index}`,display_name:'Must be rate limited'},key:crypto.randomUUID()});
 const list=await request('/api/control/accounts?limit=20&offset=0',user);
 checks.push({user:user.index,idempotency:replay.response.status===201&&replay.body.id===original.id,permitted:tenth.response.status===201,rateLimit:limited.response.status===429,count:list.body.total,correctCount:list.body.total===109});
}
const writes=summarizeSamples(writeSamples,writerSeconds,writeBudget),passed=stages.every(x=>x.passed)&&writes.passed&&checks.every(x=>x.idempotency&&x.permitted&&x.rateLimit&&x.correctCount);
const folder=resolve('runtime/deep-audit-replay');mkdirSync(folder,{recursive:true});
writeFileSync(resolve(folder,'load-multi.json'),JSON.stringify({generatedAt:new Date().toISOString(),users:10,initialAccounts:1000,stages,writes,checks,passed},null,2));
if(!passed)throw Error('Declared workload thresholds failed');
