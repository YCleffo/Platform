import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { i18n } from '@/shared/i18n';
import { deviceApi, type Device, type DeviceCommand, type DeviceEvent } from './api';
import DeviceHistoryDialog from './DeviceHistoryDialog.vue';

vi.mock('./api', () => ({
  deviceApi: { sessions: vi.fn(), events: vi.fn(), commands: vi.fn() },
}));

const device: Device = {
  id: '11111111-2222-3333-4444-555555555555',
  number: '17',
  name: 'Computer 17',
  status: 'OFFLINE',
  classroomId: null,
  classroomName: null,
  enrollmentState: 'confirmed',
  lastSeenAt: null,
  createdAt: '2026-03-08T10:00:00Z',
};
const org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const initialPage = { items: [], limit: 50, offset: 0, nextOffset: null };

function render() {
  i18n.global.locale.value = 'en';
  vi.mocked(deviceApi.sessions).mockResolvedValue(initialPage);
  return mount(DeviceHistoryDialog, {
    props: { device, organizationId: org },
    global: {
      plugins: [i18n],
      stubs: { AppDialog: { template: '<section><slot name="toolbar" /><slot /><slot name="footer" /></section>' } },
    },
  });
}

describe('device history', () => {
  it('opens the event journal, advances by the returned offset and keeps raw payloads out of the UI', async () => {
    const event: DeviceEvent = {
      id: device.id, kind: 'agent_error', payload: { diagnostic: 'private-agent-payload' }, createdAt: device.createdAt,
    };
    vi.mocked(deviceApi.events).mockResolvedValueOnce({ ...initialPage, items: [event], nextOffset: 50 })
      .mockResolvedValueOnce({ ...initialPage, items: [{ ...event, kind: 'boot' }], offset: 50 });
    const wrapper = render();
    await flushPromises();
    await wrapper.findAll('button').find((button) => button.text() === 'Events and errors')!.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Agent error');
    expect(wrapper.text()).not.toContain('private-agent-payload');
    await wrapper.findAll('button').find((button) => button.text() === 'Load more')!.trigger('click');
    await flushPromises();
    expect(deviceApi.events).toHaveBeenLastCalledWith(org, device.id, 50, expect.any(AbortSignal));
    expect(wrapper.text()).toContain('Agent started');
    wrapper.unmount();
  });

  it('keeps the command page when a delayed event response arrives', async () => {
    let releaseEvents: (value: Awaited<ReturnType<typeof deviceApi.events>>) => void = () => undefined;
    vi.mocked(deviceApi.events).mockImplementationOnce(() => new Promise((resolve) => { releaseEvents = resolve; }));
    const command: DeviceCommand = {
      id: device.id, kind: 'end_session', payload: {}, status: 'ACKED', createdAt: device.createdAt, expiresAt: device.createdAt, acknowledgedAt: device.createdAt,
    };
    vi.mocked(deviceApi.commands).mockResolvedValue({ ...initialPage, items: [command] });
    const wrapper = render();
    await wrapper.findAll('button').find((button) => button.text() === 'Events and errors')!.trigger('click');
    const eventRequest = vi.mocked(deviceApi.events).mock.lastCall?.[3];
    await wrapper.findAll('button').find((button) => button.text() === 'Command history')!.trigger('click');
    releaseEvents({ ...initialPage, items: [{ id: device.id, kind: 'agent_error', payload: {}, createdAt: device.createdAt }] });
    await flushPromises();
    expect(eventRequest?.aborted).toBe(true);
    expect(wrapper.text()).toContain('Acknowledged');
    expect(wrapper.text()).not.toContain('Agent error');
    wrapper.unmount();
  });
});
