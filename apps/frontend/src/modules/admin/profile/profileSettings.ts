import type { CoreSettings, CoreSettingsPatch } from '@/shared/api/core/account';
export type ProfileSettings = CoreSettings['settings'];
export function copySettings(value: ProfileSettings): ProfileSettings {
  return { interface: { ...value.interface }, notifications: { ...value.notifications }, privacy: { ...value.privacy } };
}
export function changedSettings(before: ProfileSettings, after: ProfileSettings): CoreSettingsPatch {
  const patch: CoreSettingsPatch = {};
  for (const key of ['animations', 'transparency'] as const) if (before.interface[key] !== after.interface[key]) (patch.interface ??= {})[key] = after.interface[key];
  for (const key of ['browser', 'sound', 'email'] as const) if (before.notifications[key] !== after.notifications[key]) (patch.notifications ??= {})[key] = after.notifications[key];
  if (before.privacy.show_online_status !== after.privacy.show_online_status) patch.privacy = { show_online_status: after.privacy.show_online_status };
  return patch;
}
export const resetSettings: CoreSettingsPatch = { interface: { animations: null, transparency: null }, notifications: { browser: null, sound: null, email: null }, privacy: { show_online_status: null } };
