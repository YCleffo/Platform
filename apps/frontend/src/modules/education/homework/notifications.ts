import type { Notification } from '../api';
const id = '[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}';
export function notificationTarget(item: Notification, student = false): string | null {
  if (student && item.kind === 'achievement_unlocked' && item.resourcePath === '/student/achievements') return '/student/achievements';
  const allowed = new RegExp(student ? `^/student/homework/${id}$` : `^/homework/(?:submissions/)?${id}$`, 'i');
  if (item.resourcePath && allowed.test(item.resourcePath)) return item.resourcePath;
  if (item.kind?.startsWith('homework') && item.resourceId && new RegExp(`^${id}$`, 'i').test(item.resourceId)) return `${student ? '/student' : ''}/homework/${item.resourceId}`;
  if (!student && item.threadId && new RegExp(`^${id}$`, 'i').test(item.threadId)) return `/messages/${item.threadId}`;
  return null;
}
