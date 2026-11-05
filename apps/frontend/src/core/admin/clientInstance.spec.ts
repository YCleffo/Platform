import { afterEach, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminAuth } from '@/shared/api/core/adminAuth';
afterEach(()=>vi.unstubAllGlobals());
it('uses the same non-persistent client identity for heartbeat and logout',async()=>{
  const before={local:{...localStorage},session:{...sessionStorage}};
  const fetcher=vi.fn().mockImplementation((url:string)=>Promise.resolve(url.endsWith('/logout')?new Response(null,{status:204}):new Response(JSON.stringify({status:'success',data:{status:'online'}}))));vi.stubGlobal('fetch',fetcher);
  await coreAdminHttp.post('/auth/me/presence/heartbeat',{});await coreAdminAuth.logout('test-access');
  const headers=fetcher.mock.calls.map(call=>new Headers(call[1]?.headers));const client=headers[0]!.get('X-Client-Instance-ID');expect(client).toMatch(/^[a-f0-9-]{36}$/);expect(headers[1]!.get('X-Client-Instance-ID')).toBe(client);expect({local:{...localStorage},session:{...sessionStorage}}).toEqual(before);
});
