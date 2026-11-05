import { describe, expect, it } from 'vitest';
import { changedSettings, copySettings, resetSettings } from './profileSettings';
describe('account settings draft', () => {
  const before = { interface: { animations: true, transparency: true }, notifications: { browser: true, sound: true, email: false }, privacy: { show_online_status: false }, installedModule: { option: true } };
  it('sends only changed leaves, preserving siblings and module namespaces', () => {
    const draft = copySettings(before); draft.notifications.email = true;
    expect(before.notifications.email).toBe(false); expect(changedSettings(before, draft)).toEqual({ notifications: { email: true } });
    expect(changedSettings(before, copySettings(before))).toEqual({});
  });
  it('resets known leaves with null and never resets locale/timezone or an installed module', () => {
    expect(resetSettings).toEqual({ interface: { animations: null, transparency: null }, notifications: { browser: null, sound: null, email: null }, privacy: { show_online_status: null } });
  });
});
