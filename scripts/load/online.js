import http from 'k6/http';
import {check, sleep} from 'k6';
import exec from 'k6/execution';
import {Trend, Rate} from 'k6/metrics';

const target = Number(__ENV.TARGET_VUS || 10000);
const hold = Number(__ENV.HOLD_SECONDS || 60);
const pace = Number(__ENV.PACE_SECONDS || 5);
if (!Number.isInteger(target) || target < 1 || target > 10000 || !Number.isFinite(hold) || hold < 1 || !Number.isFinite(pace) || pace <= 0) {
  throw new Error('Invalid load configuration');
}
const core = __ENV.CORE_URL || 'http://core';
const control = __ENV.CONTROL_URL || 'http://control:8000';
// This fixture is deliberately restricted to the disposable Compose services.
if (core !== 'http://core' || control !== 'http://control:8000') throw new Error('Use the isolated load services');
const businessLatency = new Trend('business_latency', true);
const businessFailure = new Rate('business_failure');
const plateau = Math.min(100, target);
const middle = Math.min(1000, target);
const smoke=target<=10;
const compact=!smoke && target<10000;
const routes=['core_me','core_settings','control_account','control_entitlements','control_sessions','catalog'];
const perRouteThresholds={};
for(const name of routes) {
  perRouteThresholds[`business_latency{name:${name}}`]=['p(95)<1000','p(99)<3000'];
  perRouteThresholds[`business_failure{name:${name}}`]=['rate<0.01'];
}
export const options = {
  scenarios: {online: {executor: 'ramping-vus', startVUs: 0,
    stages: smoke ? [{duration:'5s',target},{duration:'15s',target},{duration:'5s',target:0}] : compact ? [{duration:'15s',target},{duration:`${hold}s`,target},{duration:'15s',target:0}] : [{duration:'15s',target:plateau},{duration:'15s',target:plateau},
      {duration:'20s',target:middle},{duration:'20s',target:middle},
      {duration:'40s',target},{duration:`${hold}s`,target},{duration:'20s',target:0}],
    gracefulRampDown:'15s', gracefulStop:'15s'}},
  thresholds: {
    ...perRouteThresholds,
    http_req_failed: ['rate<0.01'],
    checks: ['rate>0.99'],
    business_latency: ['p(95)<1000','p(99)<3000'],
    business_failure: ['rate<0.01'],
    'business_latency{band:peak}': ['p(95)<1000','p(99)<3000'],
    'business_failure{band:peak}': ['rate<0.01'],
  },
  summaryTrendStats: ['avg','med','p(90)','p(95)','p(99)','max'],
  // Tokens live only in synthetic fixture state and are never emitted as tags.
  systemTags: ['status','method','name','scenario','expected_response'],
};
export default function () {
  const started=Date.now();
  const n=exec.vu.idInTest;
  const tail=String(n).padStart(12,'0');
  const coreToken='load_core_access_'+String(n).padStart(26,'0');
  const controlToken='load_control_access_'+String(n).padStart(23,'0');
  const account='40000000-0000-4000-8000-'+tail;
  const expectedCore='20000000-0000-4000-8000-'+tail;
  const elapsed=exec.instance.currentTestRunDuration/1000;
  const band=smoke ? 'peak' : compact ? (elapsed<15 ? 'ramp' : elapsed<15+hold ? 'peak' : 'down') : elapsed < 30 ? 'small' : elapsed < 70 ? 'medium' : elapsed < 110 ? 'ramp' : elapsed < 110+hold ? 'peak' : 'down';
  const which=(exec.vu.iterationInScenario+n)%6;
  const paths=[['core_me',core+'/api/auth/me',coreToken],
    ['core_settings',core+'/api/auth/me/settings',coreToken],
    ['control_account',control+'/api/control/accounts/'+account,controlToken],
    ['control_entitlements',control+'/api/control/accounts/'+account+'/entitlements',controlToken],
    ['control_sessions',control+'/api/control/auth/sessions',controlToken],
    ['catalog',control+'/api/control/catalog/plans',null]];
  const [name,url,token]=paths[which];
  const r=http.get(url,{headers:token ? {Authorization:'Bearer '+token} : {},timeout:'10s',tags:{name,band}});
  let body;
  try { body=r.json(); } catch (_) { body=null; }
  let valid=r.status===200 && body!==null;
  if (valid && name==='core_me') valid=body.data && body.data.account && body.data.account.id===expectedCore;
  if (valid && name==='control_account') valid=body.id===account;
  if (valid && name==='control_entitlements') valid=body.commercial_account_id===account && body.subscription!==null && body.features['load.tier']===['free','standard','premium'][(n-1)%3];
  if (valid && name==='control_sessions') valid=Array.isArray(body.items) && body.items.length===1;
  if (valid && name==='catalog') valid=Array.isArray(body.plans) && body.plans.length===3;
  check(r,{'business response and identity correct':()=>Boolean(valid)},{name,band});
  businessFailure.add(!valid,{name,band});
  businessLatency.add(r.timings.duration,{name,band});
  sleep(Math.max(0,pace-(Date.now()-started)/1000));
}
