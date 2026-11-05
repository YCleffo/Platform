import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, disposePinia, getActivePinia, setActivePinia } from 'pinia';
import { corePrivacy, type OwnPrivacyRequest } from '@/shared/api/core/privacy';
import { coreAdminSession } from './session';
import { useCorePrivacyRequests } from './privacyRequestsStore';
vi.mock('@/shared/api/core/privacy',()=>({corePrivacy:{list:vi.fn()}}));
const row=(index:number):OwnPrivacyRequest=>({id:`01900000-0000-7000-8000-${String(index).padStart(12,'0')}`,subjectPersonId:'01900000-0000-7000-8000-000000000099',representationId:null,requestType:'access',status:'submitted',submittedAt:'2026-11-03',completedAt:null,resolutionCode:null,deadlines:[]});
function deferred<T>(){let resolve!:(v:T)=>void;let reject!:(e:unknown)=>void;const promise=new Promise<T>((yes,no)=>{resolve=yes;reject=no;});return{promise,resolve,reject};}
beforeEach(()=>{setActivePinia(createPinia());coreAdminSession.clear();vi.resetAllMocks();});afterEach(()=>{const pinia=getActivePinia();if(pinia)disposePinia(pinia);});
describe('owned privacy list and cursor lifetime',()=>{
  it('clears synchronously on logout and rejects a late page from the previous session',async()=>{
    const pending=deferred<OwnPrivacyRequest[]>();vi.mocked(corePrivacy.list).mockReturnValue(pending.promise);
    const store=useCorePrivacyRequests();const done=store.load();coreAdminSession.clear();pending.resolve([row(1)]);await done;expect(store.items).toEqual([]);expect(store.loaded).toBe(false);expect(store.loading).toBe(false);
  });
  it('keeps rows and pagination on a failed next page, retries the same offset and deduplicates IDs',async()=>{
    vi.mocked(corePrivacy.list).mockResolvedValueOnce(Array.from({length:26},(_,i)=>row(i))).mockRejectedValueOnce(new Error('temporary')).mockResolvedValueOnce([row(24),row(25)]);
    const store=useCorePrivacyRequests();await store.load();expect(store.items).toHaveLength(25);expect(store.hasMore).toBe(true);
    await store.load(true);expect(store.items).toHaveLength(25);expect(store.moreError).toBeInstanceOf(Error);
    await store.load(true);expect(store.items).toHaveLength(26);expect(store.moreError).toBeNull();expect(store.hasMore).toBe(false);
    expect(vi.mocked(corePrivacy.list).mock.calls).toEqual([[0,26],[25,26],[25,26]]);
  });
  it('a refresh invalidates late cursor errors and their finally block',async()=>{
    const pending=deferred<OwnPrivacyRequest[]>();vi.mocked(corePrivacy.list).mockResolvedValueOnce(Array.from({length:26},(_,i)=>row(i))).mockReturnValueOnce(pending.promise).mockResolvedValueOnce([row(99)]);
    const store=useCorePrivacyRequests();await store.load();const old=store.load(true);await store.load();pending.reject(new Error('old failure'));await old;
    expect(store.items.map(item=>item.id)).toEqual([row(99).id]);expect(store.moreError).toBeNull();expect(store.error).toBeNull();expect(store.loadingMore).toBe(false);
  });
});
