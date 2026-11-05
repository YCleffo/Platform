import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { coreAdminSession } from '@/core/admin/session';
import { corePrivacy, type OwnPrivacyRequest, type PrivacyResponse } from '@/shared/api/core/privacy';
import { ApiError } from '@/shared/api/errors';
import { i18n } from '@/shared/i18n';
import CorePrivacyRequestView from './CorePrivacyRequestView.vue';
vi.mock('@/core/admin/adminAuthStore',()=>({useAdminAuthStore:()=>({identity:{account:{personId:'01900000-0000-7000-8000-000000000001'}}})}));
vi.mock('@/shared/api/core/privacy',async importOriginal=>{const actual=await importOriginal<typeof import('@/shared/api/core/privacy')>();return{...actual,corePrivacy:{get:vi.fn(),representations:vi.fn(),response:vi.fn()}};});
const person='01900000-0000-7000-8000-000000000001';const id='01900000-0000-7000-8000-000000000002';
const row:OwnPrivacyRequest={id,subjectPersonId:person,representationId:null,requestType:'access',status:'in_progress',submittedAt:'2026-11-03',completedAt:null,resolutionCode:null,deadlines:[]};
const document={schemaVersion:'1',subjectPersonId:person,generatedAt:'2026-11-03',sections:[{contributorCode:'fixture',dataCategories:['identity'],records:[{name:'owned sensitive fixture'}]}]};
const payload:PrivacyResponse={raw:JSON.stringify(document),document};
function deferred<T>(){let resolve!:(v:T)=>void;const promise=new Promise<T>(yes=>{resolve=yes;});return{resolve,promise};}
let wrapper:VueWrapper|undefined;
async function setup(){const router=createRouter({history:createMemoryHistory(),routes:[{path:'/admin/profile/privacy/:id',name:'admin-profile-privacy-request',component:CorePrivacyRequestView},{path:'/admin/profile/privacy',name:'admin-profile-privacy',component:{template:'<div />'}}]});await router.push('/admin/profile/privacy/'+id);await router.isReady();wrapper=mount(CorePrivacyRequestView,{attachTo:documentBody(),global:{plugins:[router,i18n]}});await flushPromises();return router;}
function documentBody(){return globalThis.document.body;}
async function receive(){const button=wrapper!.findAll('button').find(item=>item.text()==='Receive response');expect(button).toBeDefined();await button!.trigger('click');await flushPromises();const confirm=Array.from(globalThis.document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')).find(item=>item.textContent?.trim()==='Receive response');expect(confirm).toBeDefined();confirm!.click();await flushPromises();}
beforeEach(()=>{vi.resetAllMocks();coreAdminSession.clear();i18n.global.locale.value='en';vi.mocked(corePrivacy.get).mockResolvedValue({...row});vi.mocked(corePrivacy.representations).mockResolvedValue([]);});
afterEach(()=>{wrapper?.unmount();wrapper=undefined;vi.restoreAllMocks();});
describe('privacy response component lifetime',()=>{
  it('aborts and discards a one-time response that arrives after logout',async()=>{
    const pending=deferred<PrivacyResponse>();vi.mocked(corePrivacy.response).mockReturnValue(pending.promise);await setup();await receive();
    const signal=vi.mocked(corePrivacy.response).mock.calls[0]![2];coreAdminSession.clear();pending.resolve(payload);await flushPromises();
    expect(signal.aborted).toBe(true);expect(globalThis.document.body.textContent).not.toContain('owned sensitive fixture');expect(globalThis.document.querySelector('[role="dialog"]')).toBeNull();
  });
  it('keeps an already-issued document downloadable if the metadata reload fails',async()=>{
    vi.mocked(corePrivacy.get).mockResolvedValueOnce({...row}).mockRejectedValueOnce(new ApiError(503,'unavailable','Unavailable'));vi.mocked(corePrivacy.response).mockResolvedValue(payload);
    const create=vi.spyOn(URL,'createObjectURL').mockReturnValue('blob:owned-document');vi.spyOn(URL,'revokeObjectURL').mockImplementation(()=>{});vi.spyOn(HTMLAnchorElement.prototype,'click').mockImplementation(()=>{});
    await setup();await receive();expect(globalThis.document.body.textContent).toContain('owned sensitive fixture');
    const save=Array.from(globalThis.document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')).find(item=>item.textContent?.trim()==='Save JSON file');expect(save?.disabled).toBe(false);save!.click();await flushPromises();expect(create).toHaveBeenCalledTimes(1);
  });
  it('cannot overwrite a newer request with a late detail response',async()=>{
    const pending=deferred<OwnPrivacyRequest>();const other={...row,id:'01900000-0000-7000-8000-000000000003',requestType:'export'};
    vi.mocked(corePrivacy.get).mockReturnValueOnce(pending.promise).mockResolvedValueOnce(other);const router=await setup();await router.push('/admin/profile/privacy/'+other.id);await flushPromises();pending.resolve(row);await flushPromises();
    expect(wrapper!.text()).toContain(other.id);expect(wrapper!.text()).not.toContain(row.id);expect(wrapper!.find('h2').text()).toBe('Portable data export');
  });
});
