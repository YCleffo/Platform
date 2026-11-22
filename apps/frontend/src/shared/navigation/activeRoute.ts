import type { RouteMeta } from 'vue-router';

export function activeNavigationRoute(
  route: { meta: RouteMeta; name?: string | symbol | null },
  sectionKey = 'primaryNavigation',
): string {
  const section = route.meta[sectionKey];
  return typeof section === 'string' ? section : typeof route.name === 'string' ? route.name : '';
}
