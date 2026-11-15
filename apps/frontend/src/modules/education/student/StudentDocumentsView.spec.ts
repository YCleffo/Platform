import { flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { studentSession } from './session';
import { storageApi } from './storage';
import StudentDocumentsView from './StudentDocumentsView.vue';
vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('../resources', () => ({ useEducationError: () => () => 'Request failed' }));
vi.mock('./storage', () => ({ storageApi: { space: vi.fn(), allFiles: vi.fn(), conflicts: vi.fn(), fileVersions: vi.fn(), restoreFile: vi.fn(), deleteFile: vi.fn(), renameFile: vi.fn(), resolveConflict: vi.fn() } }));
const id = '11111111-2222-3333-4444-555555555555', org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const file = { id, path: 'projects/week1/code.txt', version: 2, sizeBytes: 3, sha256: 'a'.repeat(64), createdAt: '2026-03-08T10:00:00Z' };
const stubs = { AppInput: true, AppDropdown: true, EducationState: true };
let wrapper: ReturnType<typeof mount> | null = null;
beforeEach(() => {
  vi.clearAllMocks();
  coreAdminSession.set({ accessToken: 'access', refreshToken: 'refresh', accessExpiresAt: '2099-01-01T00:00:00Z', sessionExpiresAt: '2099-01-01T00:00:00Z', sessionId: id });
  studentSession.organizationId = org;
  vi.mocked(storageApi.space).mockResolvedValue({ id, studentId: id, maxFiles: 10, maxBytes: 1000, fileCount: 1, totalBytes: 3, createdAt: file.createdAt });
  vi.mocked(storageApi.allFiles).mockResolvedValue([file]);
  vi.mocked(storageApi.conflicts).mockResolvedValue({ items: [], offset: 0, limit: 50, nextOffset: null });
});
afterEach(() => { wrapper?.unmount(); wrapper = null; coreAdminSession.clear(); studentSession.organizationId = ''; });
describe('student documents', () => {
  it('opens without a teacher workspace and browses actual nested paths', async () => {
    wrapper = mount(StudentDocumentsView, { global: { stubs } }); await flushPromises();
    expect(storageApi.space).toHaveBeenCalledWith(org, expect.any(AbortSignal));
    const folderButton = wrapper.findAll('button').find(button => button.text().endsWith(': projects'));
    expect(folderButton).toBeDefined(); await folderButton!.trigger('click');
    const child = wrapper.findAll('button').find(button => button.text().endsWith(': week1'));
    await child!.trigger('click'); expect(wrapper.text()).toContain('code.txt');
  });
  it('clears loaded documents and ignores a pending response after logout', async () => {
    let finish: (value: typeof file[]) => void = () => undefined;
    vi.mocked(storageApi.allFiles).mockImplementation(() => new Promise(resolve => { finish = resolve; }));
    wrapper = mount(StudentDocumentsView, { global: { stubs } }); await flushPromises();
    coreAdminSession.clear(); finish([file]); await flushPromises();
    expect(wrapper.text()).not.toContain('projects');
    expect(storageApi.allFiles).toHaveBeenCalledTimes(1);
  });
  it('shows API failures and permits retry without displaying a previous student list', async () => {
    vi.mocked(storageApi.allFiles).mockRejectedValueOnce(new Error('offline'));
    wrapper = mount(StudentDocumentsView, { global: { stubs } }); await flushPromises();
    expect(wrapper.findComponent({ name: 'EducationState' }).attributes('error')).toBeDefined();
    expect(wrapper.text()).not.toContain('projects');
    await wrapper.findAll('button').find(button => button.text() === 'education.refresh')!.trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('projects');
  });
});
