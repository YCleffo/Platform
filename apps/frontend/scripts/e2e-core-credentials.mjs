// Real WebAuthn/Core/PostgreSQL/Redis. Only the authenticator and external provider transport are synthetic.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { request as playwrightRequest } from 'playwright';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';
const env = process.env;
if (process.argv.includes('--check-guard')) { const gate = await evaluateCoreMutationGate(env); assert.equal(gate.allowed, false); console.log('Core credential mutations refuse without disposable runtime proof.'); process.exit(0); }
const proof = await playwrightRequest.newContext({ ignoreHTTPSErrors:true }); let gate;
try { gate = await evaluateCoreMutationGate(env, async url => { const response = await proof.get(url); return { status:response.status(), json:()=>response.json() }; }); } finally { await proof.dispose(); }
assert.equal(gate.allowed, true, gate.reasons.join('; '));
function fixture(command, ...args) {
  assert.ok(env.CORE_TEST_CONTAINER);
  try { return JSON.parse(execFileSync('docker', ['exec', env.CORE_TEST_CONTAINER, 'php', 'tests/e2e/core-auth-fixture.php', command, ...args], { encoding:'utf8', stdio:['ignore','pipe','pipe'] })); }
  catch { throw new Error('Disposable credential fixture failed; private output suppressed.'); }
}
const actors = fixture('seed').accounts; const actor = actors.reset;
const browser = await launchBrowser(); const context = await browser.newContext({ ignoreHTTPSErrors:true, viewport:{ width:1440,height:1100 } });
await context.addInitScript(origin => { if (location.origin !== origin) return; localStorage.setItem('platform.locale','en'); localStorage.setItem('platform.theme','dark'); }, new URL(env.APP_URL).origin);
const page = await context.newPage(); const errors = []; const states = [];
let access;
page.on('response', async response => { if (/\/api\/auth\/(login|refresh|passkeys\/authentication\/complete|external\/[^/]+\/callback)$/.test(new URL(response.url()).pathname) && response.ok()) { const body=await response.json().catch(()=>null); if (body?.data?.accessToken) access=body.data.accessToken; } });
page.on('pageerror', error=>errors.push(error.message)); context.on('page', popup=>popup.on('pageerror',error=>errors.push(error.message)));
const cdp = await context.newCDPSession(page); await cdp.send('WebAuthn.enable');
const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator',{ options:{ protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true } });
let cancelProvider = false; let conflict = false;
await context.route(/^https:\/\/(accounts\.google\.com|oauth\.telegram\.org)\//,async route=>{
  const url=new URL(route.request().url()); const provider=url.hostname==='accounts.google.com'?'google':'telegram';
  const target=new URL(url.searchParams.get('redirect_uri')); assert.equal(target.origin,new URL(env.APP_URL).origin);
  target.searchParams.set('state',url.searchParams.get('state'));
  if (cancelProvider) target.searchParams.set('error','access_denied');
  else {
    const email=conflict?actors.externalGoogle.email:actor.email;
    const result=fixture(conflict?'provider-code':'link-code',email,provider,url.searchParams.get('nonce'),url.searchParams.get('code_challenge'));
    target.searchParams.set('code',result.code);
  }
  await route.fulfill({ status:302,headers:{ location:target.href },body:'' });
});
const state=()=>fixture('credentials-state',actor.email);
async function passwordLogin(){
  await page.goto(env.APP_URL+'/admin/login?redirect=/admin/security/passkeys'); await page.locator('#admin-identifier').fill(actor.email); await page.locator('#admin-password').fill(actor.password); await page.locator('form button[type=submit]').click(); await page.waitForURL(url=>url.pathname==='/admin/security/passkeys'); await page.getByRole('button',{name:'Add a passkey',exact:true}).waitFor();
}
async function add(label){
  await page.getByRole('button',{name:'Add a passkey',exact:true}).click(); await page.locator('#core-passkey-label').fill(label);
  const completed=page.waitForResponse(response=>new URL(response.url()).pathname==='/api/auth/passkeys/registration/complete');
  await page.getByRole('dialog').getByRole('button',{name:'Continue on this device',exact:true}).click();
  const response=await completed; assert.equal(response.status(),201,'real attestation must be accepted'); await page.getByText('Passkey added.',{exact:true}).waitFor();
}
async function passkeyLogin(){
  await page.goto(env.APP_URL+'/admin/login?redirect=/admin/security/passkeys');
  const completed=page.waitForResponse(response=>new URL(response.url()).pathname==='/api/auth/passkeys/authentication/complete');
  await page.getByRole('button',{name:'Sign in with a passkey',exact:true}).click();
  const response=await completed; assert.equal(response.status(),200,'real assertion must be accepted'); assert.equal((await response.json()).data.status,'authenticated');
  await page.waitForURL(url=>url.pathname==='/admin/security/passkeys');
}
mkdirSync('screenshots',{recursive:true});
try {
  await passwordLogin(); await add('Personal laptop');
  let persisted=state(); assert.equal(persisted.passkeys.length,1); assert.equal(persisted.passkeys[0].label,'Personal laptop'); assert.equal(persisted.passkeys[0].revoked_at,null);
  await page.getByRole('button',{name:'Rename Personal laptop',exact:true}).click(); await page.locator('#core-passkey-label').fill('Work laptop'); await page.getByRole('dialog').getByRole('button',{name:'Save',exact:true}).click(); await page.getByText('Passkey renamed.',{exact:true}).waitFor(); assert.equal(state().passkeys[0].label,'Work laptop');
  await page.getByRole('button',{name:'Sign out',exact:true}).click(); await page.waitForURL(url=>url.pathname==='/admin/login'); await passkeyLogin(); assert.ok(state().passkeys[0].last_used_at);
  states.push('native registration and cryptographic passkey login, rename and durable PostgreSQL state');
  fixture('remove-password',actor.email);
  await page.getByRole('button',{name:'Remove passkey Work laptop',exact:true}).click(); const refused=page.waitForResponse(response=>response.request().method()==='DELETE'&&new URL(response.url()).pathname.includes('/auth/me/passkeys/')); await page.getByRole('dialog').getByRole('button',{name:'Remove passkey',exact:true}).click(); assert.equal((await refused).status(),409); await page.getByText('This is your last sign-in method. Add another method before removing it.',{exact:true}).waitFor(); await page.getByRole('dialog').getByRole('button',{name:'Cancel',exact:true}).click(); assert.equal(state().passkeys[0].revoked_at,null);
  states.push('server rejects revoking the only sign-in method with 409 and preserves the key');
  await navigateClientSide(page,'/admin/security/identities'); await page.getByRole('button',{name:'Connect Google',exact:true}).waitFor();
  cancelProvider=true; await page.getByRole('button',{name:'Connect Google',exact:true}).click(); await page.getByText('Provider sign-in was cancelled. You can try again.',{exact:true}).waitFor(); assert.equal(state().identities.length,0);
  cancelProvider=false; conflict=true; await page.getByRole('button',{name:'Connect Google',exact:true}).click(); await page.getByText('This provider is already connected here or belongs to another account. Refresh the list or use a different provider account.',{exact:true}).waitFor(); assert.equal(state().identities.length,0);
  conflict=false; await page.getByRole('button',{name:'Connect Google',exact:true}).click(); await page.getByText('Account connected.',{exact:true}).waitFor(); assert.deepEqual(state().identities,[{provider:'google'}]); assert.equal(new URL(page.url()).pathname,'/admin/security/identities');
  await stableScreenshot(page,'screenshots/core-credentials-real-identities.png');
  await page.getByRole('button',{name:'Sign out',exact:true}).click(); await page.waitForURL(url=>url.pathname==='/admin/login'); await page.getByRole('button',{name:'Continue with Google',exact:true}).click(); await page.waitForURL(url=>url.pathname==='/admin');
  await navigateClientSide(page,'/admin/security/identities'); await page.getByRole('button',{name:'Disconnect Google',exact:true}).waitFor();
  states.push('real link callback/PKCE/state/session, provider cancellation and ownership conflict, then fresh external login; OIDC transport explicitly synthetic');
  await navigateClientSide(page,'/admin/security/passkeys'); await page.getByRole('button',{name:'Remove passkey Work laptop',exact:true}).click(); await page.getByRole('dialog').getByRole('button',{name:'Remove passkey',exact:true}).click(); await page.getByText('Passkey removed.',{exact:true}).waitFor(); assert.ok(state().passkeys[0].revoked_at);
  const credentials=await cdp.send('WebAuthn.getCredentials',{authenticatorId}); for(const credential of credentials.credentials) await cdp.send('WebAuthn.removeCredential',{authenticatorId,credentialId:credential.credentialId});
  await navigateClientSide(page,'/admin/security/identities'); await page.getByRole('button',{name:'Disconnect Google',exact:true}).click(); await page.getByRole('dialog').getByRole('button',{name:'Disconnect',exact:true}).click(); await page.getByText('This is your last sign-in method. Add another method before removing it.',{exact:true}).waitFor(); assert.equal(state().identities.length,1); await page.getByRole('dialog').getByRole('button',{name:'Cancel',exact:true}).click();
  await navigateClientSide(page,'/admin/security/passkeys'); await add('Replacement key');
  await navigateClientSide(page,'/admin/security/identities'); await page.getByRole('button',{name:'Disconnect Google',exact:true}).click(); await page.getByRole('dialog').getByRole('button',{name:'Disconnect',exact:true}).click(); await page.getByText('Account disconnected.',{exact:true}).waitFor(); assert.equal(state().identities.length,0);
  await page.getByRole('button',{name:'Sign out',exact:true}).click(); await page.waitForURL(url=>url.pathname==='/admin/login'); await passkeyLogin();
  persisted=state(); assert.equal(persisted.passkeys.filter(key=>key.revoked_at===null).length,1); assert.equal(persisted.passkeys.filter(key=>key.revoked_at!==null).length,1);
  await stableScreenshot(page,'screenshots/core-credentials-real-passkeys.png');
  states.push('revoke with alternative provider; last-provider unlink rejected; add replacement key, unlink provider and log in again');
  const durations=[]; const activeIds=persisted.passkeys.filter(key=>key.revoked_at===null).map(key=>key.id);
  await Promise.all(Array.from({length:20},async()=>{for(let i=0;i<5;i++){
    const path=i%2===0?'/auth/me/passkeys':'/auth/me/identities'; const started=performance.now();
    const response=await context.request.get(env.CORE_API_URL+'/api'+path,{headers:{Authorization:`Bearer ${access}`},timeout:10000});
    assert.equal(response.status(),200); const data=(await response.json()).data; assert.ok(Array.isArray(data.items));
    if(path.endsWith('/passkeys')) assert.deepEqual(data.items.map(key=>key.id),activeIds); else assert.deepEqual(data.items,[]);
    durations.push(performance.now()-started);
  }}));
  durations.sort((a,b)=>a-b); const load={requests:100,concurrency:20,errors:0,p95Ms:Math.round(durations[Math.ceil(durations.length*0.95)-1])};
  states.push('100 authenticated read requests at concurrency 20 preserve credential/account isolation without retries');
  fixture('age-session',actor.email); await page.getByRole('button',{name:'Remove passkey Replacement key',exact:true}).click(); await page.getByRole('dialog').getByRole('button',{name:'Remove passkey',exact:true}).click(); await page.getByRole('button',{name:'Sign out and sign in again',exact:true}).waitFor(); assert.equal(state().passkeys.filter(key=>key.revoked_at===null).length,1);
  await page.getByRole('button',{name:'Sign out and sign in again',exact:true}).click(); await page.waitForURL(url=>url.pathname==='/admin/login'); await passkeyLogin(); states.push('expired recent authentication rejects mutation; explicit reauthentication restores the same account');
  assert.deepEqual(errors,[]); writeFileSync('screenshots/core-credentials-real-results.json',JSON.stringify({passed:true,realBackend:true,virtualAuthenticator:true,syntheticOidcTransport:true,load,states},null,2)); console.log(JSON.stringify({passed:true,realBackend:true,virtualAuthenticator:true,syntheticOidcTransport:true,load,states}));
} catch(error){await page.screenshot({path:'screenshots/core-credentials-real-failure.png'}).catch(()=>{});throw error;}
finally{await browser.close();}
