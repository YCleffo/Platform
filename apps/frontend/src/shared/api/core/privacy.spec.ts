import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from './adminHttp';
import { corePrivacy, responsePath, type OwnPrivacyRequest, type PrivacyRepresentation } from './privacy';
import { ApiResponseError, NetworkError } from '../errors';
vi.mock('./adminHttp',()=>({coreAdminHttp:{get:vi.fn(),post:vi.fn(),document:vi.fn()}}));
const own='01900000-0000-7000-8000-000000000001';const other='01900000-0000-7000-8000-000000000002';const id='01900000-0000-7000-8000-000000000003';const grant='01900000-0000-7000-8000-000000000004';
const row:OwnPrivacyRequest={id,subjectPersonId:own,representationId:null,requestType:'access',status:'in_progress',submittedAt:'2026-11-03',completedAt:null,resolutionCode:null,deadlines:[]};
const authority:PrivacyRepresentation={id:grant,subjectPersonId:other,subjectName:'Subject',validUntil:null,scopes:['privacy.request.submit']};
beforeEach(()=>vi.resetAllMocks());
afterEach(()=>vi.useRealTimers());
describe('Core self-service privacy contracts',()=>{
  it('accepts the legacy array envelope and passes explicit bounded pagination',async()=>{
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce([row]).mockResolvedValueOnce({items:[row]});
    expect(await corePrivacy.list(25)).toEqual([row]);expect(coreAdminHttp.get).toHaveBeenCalledWith('/auth/me/privacy/requests?limit=26&offset=25',expect.any(AbortSignal));
    await expect(corePrivacy.list()).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('sends self and representative submission bodies to their own endpoints',async()=>{
    vi.mocked(coreAdminHttp.post).mockResolvedValue({id}); await corePrivacy.submit('access');await corePrivacy.submit('export',authority);
    expect(coreAdminHttp.post).toHaveBeenCalledWith('/auth/me/privacy/requests',{requestType:'access'},expect.any(AbortSignal));
    expect(coreAdminHttp.post).toHaveBeenCalledWith(`/auth/me/privacy/representations/${grant}/requests`,{requestType:'export',subjectPersonId:other},expect.any(AbortSignal));
  });
  it('chooses self delivery by subject and fails closed when representation routing is unavailable',()=>{
    expect(responsePath({...row,representationId:grant},own)).toBe(`/auth/me/privacy/requests/${id}/data`);
    expect(responsePath({...row,subjectPersonId:other,representationId:grant,requestType:'processing_information'},own)).toBe(`/auth/me/privacy/representations/${grant}/requests/${id}/processing-information`);
    expect(()=>responsePath({...row,subjectPersonId:other},own)).toThrow(ApiResponseError);
  });
  it('validates raw schema-v1 personal data and preserves its exact downloadable string',async()=>{
    const raw=JSON.stringify({schemaVersion:'1',subjectPersonId:own,generatedAt:'2026-11-03',sections:[]})+'\n';vi.mocked(coreAdminHttp.document).mockResolvedValue(raw);
    const result=await corePrivacy.response(row,own,new AbortController().signal);expect(result.raw).toBe(raw);expect(result.document.subjectPersonId).toBe(own);
  });
  it('rejects another subject or an envelope instead of offering their payload as a download',async()=>{
    vi.mocked(coreAdminHttp.document).mockResolvedValueOnce(JSON.stringify({schemaVersion:'1',subjectPersonId:other,generatedAt:'2026-11-03',sections:[]})).mockResolvedValueOnce(JSON.stringify({status:'success',data:{schemaVersion:'1',subjectPersonId:own,sections:[]}}));
    await expect(corePrivacy.response(row,own,new AbortController().signal)).rejects.toBeInstanceOf(ApiResponseError);
    await expect(corePrivacy.response(row,own,new AbortController().signal)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('releases a stalled representation lookup and submission without another POST',async()=>{
    vi.useFakeTimers();
    const stalled = (_path:string, signal?:AbortSignal) => new Promise<never>((_resolve,reject)=>signal!.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')),{once:true}));
    vi.mocked(coreAdminHttp.get).mockImplementation(stalled);
    const read=expect(corePrivacy.representations()).rejects.toBeInstanceOf(NetworkError);
    await vi.advanceTimersByTimeAsync(15_000);await read;
    vi.mocked(coreAdminHttp.post).mockImplementation((path,_body,signal)=>stalled(path,signal));
    const submit=expect(corePrivacy.submit('access')).rejects.toBeInstanceOf(NetworkError);
    await vi.advanceTimersByTimeAsync(15_000);await submit;
    expect(coreAdminHttp.post).toHaveBeenCalledTimes(1);
  });
  it('cancels a stalled one-time document on timeout or caller cancellation with no replay',async()=>{
    vi.useFakeTimers();
    vi.mocked(coreAdminHttp.document).mockImplementation((_path,signal)=>new Promise((_resolve,reject)=>signal!.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError')),{once:true})));
    const controller=new AbortController();
    const timed=expect(corePrivacy.response(row,own,controller.signal)).rejects.toBeInstanceOf(NetworkError);
    await vi.advanceTimersByTimeAsync(60_000);await timed;expect(coreAdminHttp.document).toHaveBeenCalledTimes(1);
    const cancelled=expect(corePrivacy.response(row,own,controller.signal)).rejects.toMatchObject({name:'AbortError'});controller.abort();await cancelled;
    expect(coreAdminHttp.document).toHaveBeenCalledTimes(2);expect(vi.getTimerCount()).toBe(0);
  });
});
