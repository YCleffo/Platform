import type { RouteLocationRaw } from 'vue-router';

export type CommandCategory = 'navigation' | 'action' | 'preferences' | 'entity';

export interface CommandEntry {
  id: string;
  title: string;
  subtitle?: string;
  category: CommandCategory;
  keywords?: string[];
  route?: RouteLocationRaw;
  actionCode?: string;
  dangerous?: boolean;
  confirmTitle?: string;
  confirmDescription?: string;
  permission?: string;
  module?: string;
  shortcut?: string;
  run?: () => void | Promise<void>;
}

export interface CommandRegistryContext {
  isAdminAuthenticated: boolean;
  isCommercialAuthenticated?: boolean;
  can?: (permission: string) => boolean;
  availableModules?: string[];
  educationEnabled?: boolean;
  t: (key: string) => string;
  toggleTheme: () => void;
  toggleLocale: () => void;
  openOperations: () => void;
}

export function buildStaticCommands(ctx: CommandRegistryContext): CommandEntry[] {
  const can = ctx.can ?? (() => false);
  const modules = ctx.availableModules && ctx.availableModules.length > 0 ? ctx.availableModules : null;
  const hasModule = (mod?: string): boolean => {
    if (!mod) return true;
    if (mod === 'education' && ctx.educationEnabled === false) return false;
    if (!modules) return true;
    return modules.includes(mod);
  };

  const candidates: CommandEntry[] = [
    ...(ctx.isAdminAuthenticated
      ? [
          {
            id: 'nav-workspace',
            title: ctx.t('workspace.cmdWorkspace'),
            category: 'navigation' as const,
            route: { name: 'workspace' },
            keywords: ['workspace', 'dashboard', 'home', 'рабочее', 'пространство'],
          },
          {
            id: 'nav-search',
            title: ctx.t('workspace.cmdSearch'),
            category: 'navigation' as const,
            route: { name: 'search' },
            keywords: ['search', 'find', 'поиск', 'найти'],
          },
          {
            id: 'nav-trash',
            title: ctx.t('workspace.cmdTrash'),
            category: 'navigation' as const,
            route: { name: 'trash' },
            keywords: ['trash', 'deleted', 'restore', 'корзина', 'удаленные'],
          },
          {
            id: 'nav-notifications',
            title: ctx.t('workspace.cmdNotifications'),
            category: 'navigation' as const,
            route: { name: 'universal-notifications' },
            keywords: ['notifications', 'alerts', 'inbox', 'уведомления'],
          },
          {
            id: 'nav-drive',
            title: ctx.t('workspace.cmdDrive'),
            category: 'navigation' as const,
            route: { name: 'drive' },
            module: 'storage',
            keywords: ['drive', 'files', 'storage', 'диск', 'файлы'],
          },
          {
            id: 'nav-chat',
            title: ctx.t('workspace.cmdChat'),
            category: 'navigation' as const,
            route: { name: 'universal-chat' },
            keywords: ['chat', 'messages', 'чаты', 'сообщения'],
          },
          {
            id: 'nav-activities',
            title: ctx.t('workspace.cmdActivities'),
            category: 'navigation' as const,
            route: { name: 'universal-activities' },
            keywords: ['activities', 'quiz', 'активности'],
          },
          {
            id: 'nav-admin',
            title: ctx.t('workspace.cmdAdmin'),
            category: 'navigation' as const,
            route: { name: 'admin-dashboard' },
            permission: 'core.organizations.read',
            keywords: ['admin', 'core', 'админ'],
          },
          {
            id: 'nav-education',
            title: ctx.t('workspace.cmdEducation'),
            category: 'navigation' as const,
            route: '/today',
            module: 'education',
            keywords: ['education', 'lessons', 'school', 'обучение', 'уроки'],
          },
          {
            id: 'action-operations',
            title: ctx.t('workspace.cmdOpenOperations'),
            category: 'action' as const,
            keywords: ['operations', 'uploads', 'background', 'undo', 'операции', 'загрузки'],
            run: ctx.openOperations,
          },
          {
            id: 'action-empty-trash',
            title: ctx.t('workspace.cmdEmptyTrash'),
            subtitle: ctx.t('workspace.cmdEmptyTrashDesc'),
            category: 'action' as const,
            actionCode: 'trash.empty',
            dangerous: true,
            confirmTitle: ctx.t('workspace.trashEmptyConfirmTitle'),
            confirmDescription: ctx.t('workspace.trashEmptyConfirmDesc'),
            keywords: ['trash', 'empty', 'purge', 'очистить', 'корзина'],
          },
        ]
      : []),
    {
      id: 'nav-settings',
      title: ctx.t('workspace.cmdSettings'),
      category: 'navigation',
      route: { name: 'settings' },
      keywords: ['settings', 'appearance', 'theme', 'настройки', 'оформление'],
    },
    {
      id: 'pref-theme',
      title: ctx.t('workspace.cmdToggleTheme'),
      category: 'preferences',
      keywords: ['theme', 'dark', 'light', 'тема', 'тёмная', 'светлая'],
      run: ctx.toggleTheme,
    },
    {
      id: 'pref-locale',
      title: ctx.t('workspace.cmdToggleLanguage'),
      category: 'preferences',
      keywords: ['language', 'locale', 'ru', 'en', 'язык'],
      run: ctx.toggleLocale,
    },
  ];

  return candidates.filter((entry) => {
    if (entry.permission && !can(entry.permission)) return false;
    if (!hasModule(entry.module)) return false;
    return true;
  });
}

export function filterCommands(commands: CommandEntry[], rawQuery: string): CommandEntry[] {
  const query = rawQuery.trim().toLowerCase();
  if (!query) return commands;

  const scored: Array<{ entry: CommandEntry; score: number }> = [];
  for (const entry of commands) {
    const title = entry.title.toLowerCase();
    const subtitle = (entry.subtitle ?? '').toLowerCase();
    const keywords = (entry.keywords ?? []).join(' ').toLowerCase();

    let score = 0;
    if (title === query) score = 100;
    else if (title.startsWith(query)) score = 80;
    else if (title.includes(query)) score = 60;
    else if (subtitle.includes(query)) score = 40;
    else if (keywords.includes(query)) score = 30;

    if (score > 0) {
      scored.push({ entry, score });
    }
  }

  return scored.sort((a, b) => b.score - a.score).map((item) => item.entry);
}
