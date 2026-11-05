import { coreAdminHttp } from './adminHttp';
import { bounded, isOwnPrivacyRequest, parsePrivacyResponse, responseKind, type OwnPrivacyRequest, type PrivacyResponse } from './privacy';
import { ApiResponseError } from '../errors';

export const requestStatuses = ['submitted','identity_verification','in_review','in_progress','completed','partially_completed','rejected','cancelled'] as const;
export interface PrivacyFilters { status?: string; requestType?: string; overdue?: string; subjectPersonId?: string }
export interface RequestPage { items: OwnPrivacyRequest[]; pagination: { limit: number; offset: number; total: number } }
export interface PersonOption { id: string; givenName: string; familyName: string; displayName: string | null }
export interface ContactOption { id: string; personId: string; contactType: 'email'|'phone'; value: string; label: string | null; isPrimary: boolean }
export interface ProcessingBlock { id: string; scope_type: string; purpose_code: string | null; resource_type: string | null; resource_id: string | null; reason_code: string; source_request_id: string; started_at: string; expires_at: string | null }
export interface BlockInput { scopeType: 'subject'|'purpose'|'resource'; purposeCode?: string; resourceType?: string; resourceId?: string; reasonCode: string; expiresAt?: string }
export interface IntakeInput { subjectPersonId: string; requestType: string; receivedAt: string; identityVerificationCode: string; identityEvidenceReference: string; representativeVerificationReference?: string; sourceChannel: string }
export type CorrectionChanges = Record<string,string|null|boolean>;
export type OperatorCommand =
  | {kind:'transition';toStatus:string;reasonCode?:string}
  | {kind:'extend';deadlineCode:string;reasonCode:string}
  | {kind:'confirm'}
  | {kind:'correct';contributorCode:string;resourceId:string;changes:CorrectionChanges}
  | {kind:'block';input:BlockInput}
  | {kind:'release';blockId:string;reasonCode:string}
  | {kind:'terminate'} | {kind:'response'};
export type OperatorAction = OperatorCommand['kind'];
const base='/core/privacy/requests';
const object=(v:unknown):v is Record<string,unknown>=>typeof v==='object'&&v!==null&&!Array.isArray(v);
const text=(v:unknown):v is string=>typeof v==='string'&&v.length>0;
const nullable=(v:unknown)=>v===null||typeof v==='string';
const uuid=(v:unknown):v is string=>typeof v==='string'&&/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(v);
function checked<T>(v:unknown,valid:(v:unknown)=>boolean,path:string):T { if(!valid(v))throw new ApiResponseError(path);return v as T; }
const get=(path:string)=>bounded(signal=>coreAdminHttp.get(path,signal));
const post=(path:string,body:unknown)=>bounded(signal=>coreAdminHttp.post(path,body,signal));
const requestPath=(id:string)=>`${base}/${encodeURIComponent(id)}`;
const timestamp=(value:string)=>new Date(value).toISOString().replace(/\.\d{3}Z$/,'Z');
function idReply(v:unknown):v is Record<string,unknown>&{id:string} {return object(v)&&uuid(v.id);}
export function allowedTransitions(status:string):string[] {return ({submitted:['identity_verification','in_review','cancelled'],identity_verification:['in_review','cancelled'],in_review:['in_progress','cancelled']} as Record<string,string[]>)[status]??[];}
export const privacyOperator={
  async list(filters:PrivacyFilters,offset=0):Promise<RequestPage>{const query=new URLSearchParams({limit:'25',offset:String(offset)});for(const [k,v] of Object.entries(filters))if(v)query.set(k,v);const path=base+'?'+query;return checked(await get(path),v=>object(v)&&Array.isArray(v.items)&&v.items.every(isOwnPrivacyRequest)&&object(v.pagination)&&[v.pagination.limit,v.pagination.offset,v.pagination.total].every(n=>Number.isInteger(n)&&Number(n)>=0),path);},
  async get(id:string):Promise<OwnPrivacyRequest>{const path=requestPath(id);return checked(await get(path),isOwnPrivacyRequest,path);},
  async options():Promise<string[]>{const path=base+'/options';return checked<{purposeCodes:string[]}>(await get(path),v=>object(v)&&Array.isArray(v.purposeCodes)&&v.purposeCodes.every(text),path).purposeCodes;},
  async persons(offset=0):Promise<{items:PersonOption[];pagination:{total:number}}>{const path=`/core/persons?limit=25&offset=${offset}`;return checked(await get(path),v=>object(v)&&Array.isArray(v.items)&&v.items.every(p=>object(p)&&uuid(p.id)&&text(p.givenName)&&text(p.familyName)&&nullable(p.displayName))&&object(v.pagination)&&Number.isInteger(v.pagination.total),path);},
  async contacts(personId:string):Promise<ContactOption[]>{const path=`/core/persons/${encodeURIComponent(personId)}/contacts`;return checked(await get(path),v=>Array.isArray(v)&&v.every(c=>object(c)&&uuid(c.id)&&c.personId===personId&&['email','phone'].includes(String(c.contactType))&&text(c.value)&&nullable(c.label)&&typeof c.isPrimary==='boolean'),path);},
  async intake(input:IntakeInput):Promise<string>{return checked<{id:string}>(await post(base+'/intake',{...input,receivedAt:timestamp(input.receivedAt)}),idReply,base).id;},
  async transition(id:string,toStatus:string,reasonCode?:string):Promise<void>{const path=requestPath(id)+'/transition';checked(await post(path,{toStatus,...(reasonCode?{reasonCode}:{})}),v=>idReply(v)&&object(v)&&v.id===id&&v.status===toStatus,path);},
  async extend(id:string,code:string,reasonCode:string):Promise<void>{const path=requestPath(id)+`/deadlines/${encodeURIComponent(code)}/extend`;checked(await post(path,{reasonCode}),v=>idReply(v)&&object(v)&&v.id===id&&v.deadlineCode===code&&text(v.extendedDueAt),path);},
  async confirm(id:string):Promise<void>{const path=requestPath(id)+'/correction-evidence';checked(await post(path,{}),v=>object(v)&&typeof v.confirmed==='boolean',path);},
  async correct(id:string,contributorCode:string,resourceId:string,changes:CorrectionChanges):Promise<string>{const path=requestPath(id)+'/corrections';return checked<{executionId:string}>(await post(path,{contributorCode,resourceId,changes}),v=>object(v)&&uuid(v.executionId),path).executionId;},
  async blocks(id:string):Promise<ProcessingBlock[]>{const path=requestPath(id)+'/blocks';return checked(await get(path),v=>Array.isArray(v)&&v.every(b=>object(b)&&uuid(b.id)&&b.source_request_id===id&&['subject','purpose','resource'].includes(String(b.scope_type))&&[b.purpose_code,b.resource_type,b.resource_id,b.expires_at].every(nullable)&&text(b.reason_code)&&text(b.started_at)),path);},
  async block(id:string,input:BlockInput):Promise<string>{const path=requestPath(id)+'/blocks';return checked<{id:string}>(await post(path,{...input,...(input.expiresAt?{expiresAt:timestamp(input.expiresAt)}:{})}),idReply,path).id;},
  async release(id:string,reasonCode:string):Promise<void>{const path=`/core/privacy/blocks/${encodeURIComponent(id)}/release`;checked(await post(path,{reasonCode}),v=>idReply(v)&&object(v)&&v.id===id&&v.released===true&&typeof v.changed==='boolean',path);},
  async terminate(id:string):Promise<{status:string;executionId:string}>{const path=requestPath(id)+'/processing-termination/execute';return checked(await post(path,{}),v=>object(v)&&uuid(v.executionId)&&['completed','partially_completed'].includes(String(v.status)),path);},
  async response(row:OwnPrivacyRequest,signal:AbortSignal):Promise<PrivacyResponse>{const kind=responseKind(row);if(!kind)throw new ApiResponseError(base);const path=requestPath(row.id)+'/'+kind;return parsePrivacyResponse(await bounded(active=>coreAdminHttp.document(path,active),signal,60_000),row);},
};
