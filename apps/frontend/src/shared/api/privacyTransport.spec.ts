import { afterEach, describe, expect, it, vi } from 'vitest';
import { createHttpClient } from './http';
import { NetworkError } from './errors';
const make = () => createHttpClient({ baseUrl:'/api',getAccessToken:()=>null,getSessionEpoch:()=>1,refreshTokens:async()=>({status:'expired'}),onUnauthorized:()=>{} });
afterEach(()=>vi.unstubAllGlobals());
describe('one-time document transport',()=>{
  it('preserves the exact response bytes as text without unwrapping or pretty-printing',async()=>{
    const raw='{ "schemaVersion": "1", "sections": [] }\n'; const fetcher=vi.fn().mockResolvedValue(new Response(raw)); vi.stubGlobal('fetch',fetcher);
    expect(await make().get('/auth/me/privacy/requests/id/data',{responseType:'text',retryTransient:false})).toBe(raw);
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
  it.each([502,503,504])('does not replay a stateful GET after HTTP %s',async status=>{
    const fetcher=vi.fn().mockResolvedValue(new Response(JSON.stringify({error:{code:'http.unavailable',message:'Unavailable'}}),{status}));vi.stubGlobal('fetch',fetcher);
    await expect(make().get('/one-time',{responseType:'text',retryTransient:false})).rejects.toMatchObject({status});expect(fetcher).toHaveBeenCalledTimes(1);
  });
  it('does not replay a one-time response after a lost network reply',async()=>{
    const fetcher=vi.fn().mockRejectedValue(new TypeError('network'));vi.stubGlobal('fetch',fetcher);
    await expect(make().get('/one-time',{retryTransient:false})).rejects.toBeInstanceOf(NetworkError);expect(fetcher).toHaveBeenCalledTimes(1);
  });
});
