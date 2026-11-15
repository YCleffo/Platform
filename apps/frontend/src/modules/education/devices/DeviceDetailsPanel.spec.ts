import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import DeviceDetailsPanel from './DeviceDetailsPanel.vue';
import type { DeviceDetail } from './api';
vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
const device: DeviceDetail = {
  id: '11111111-2222-3333-4444-555555555555', name: 'PC 1', number: '1', classroomId: null, classroomName: null, status: 'AVAILABLE', enrollmentState: 'confirmed', lastSeenAt: null, createdAt: '2026-03-08T10:00:00Z',
  stateVersion: 2, availableActions: [], sessions: [], commands: [], progress: null, lastError: null, recovery: null, online: true,
};
describe('device details permissions', () => {
  it('identifies a test session so it cannot be mistaken for verified Windows sign-in', () => {
    const wrapper = mount(DeviceDetailsPanel, { props: { device: { ...device, testAdapter: true }, busy: false } });
    expect(wrapper.text()).toContain('education.testSessionMode');
  });
  it('retains the student and failed session while recovery still owns its documents', () => {
    const wrapper = mount(DeviceDetailsPanel, { props: { device: { ...device, status: 'RECOVERY_REQUIRED', availableActions: ['retry_recovery'], sessions: [{ id: device.id, deviceId: device.id, studentId: device.id, studentPersonId: device.id, studentName: 'Student with retained documents', status: 'FAILED', desiredStatus: null, startedAt: device.createdAt }], progress: { stage: 'awaiting_windows_logon', completed: 0, total: 1 }, recovery: { required: true, reason: 'FAILED', retryAllowed: true } }, busy: false } });
    expect(wrapper.text()).toContain('Student with retained documents');
    expect(wrapper.text()).toContain('education.session_failed');
    expect(wrapper.text()).toContain('education.stage_awaiting_windows_logon');
    expect(wrapper.find('[data-action="assign"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="retry_recovery"]').exists()).toBe(true);
  });
  it('shows only server permitted actions even for an available computer', async () => {
    const wrapper = mount(DeviceDetailsPanel, { props: { device, busy: false } });
    expect(wrapper.find('[data-action="assign"]').exists()).toBe(false);
    await wrapper.setProps({ device: { ...device, status: 'RECOVERY_REQUIRED', availableActions: ['retry_recovery'], recovery: { required: true, reason: 'network', retryAllowed: true } } });
    expect(wrapper.find('[data-action="assign"]').exists()).toBe(false);
    await wrapper.get('[data-action="retry_recovery"]').trigger('click');
    expect(wrapper.emitted('action')).toEqual([['retry_recovery']]);
  });
  it('blocks repeat actions while a request is pending and displays actual progress/errors', () => {
    const wrapper = mount(DeviceDetailsPanel, { props: { device: { ...device, availableActions: ['end_session'], progress: { stage: 'sync_out', completed: 2, total: 3 }, lastError: { code: 'checksum_mismatch', message: 'The file was not accepted', at: '2026-03-08T10:00:00Z' } }, busy: true } });
    expect(wrapper.get('[data-action="end_session"]').attributes('disabled')).toBeDefined();
    expect(wrapper.get('progress').attributes('value')).toBe('2');
    expect(wrapper.text()).toContain('checksum_mismatch');
  });
});
