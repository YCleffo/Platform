import { describe, expect, it, vi } from 'vitest';
import { buildStaticCommands, filterCommands } from './commandRegistry';

describe('commandRegistry', () => {
  const baseCtx = {
    isAdminAuthenticated: true,
    can: (perm: string) => perm === 'core.organizations.read',
    availableModules: ['storage'],
    educationEnabled: false,
    t: (k: string) => k,
    toggleTheme: vi.fn(),
    toggleLocale: vi.fn(),
    openOperations: vi.fn(),
  };

  it('filters commands by authentication, permissions, and available modules', () => {
    const cmds = buildStaticCommands(baseCtx);
    const ids = cmds.map((c) => c.id);

    expect(ids).toContain('nav-workspace');
    expect(ids).toContain('nav-admin');
    expect(ids).toContain('nav-drive');
    expect(ids).not.toContain('nav-education');

    const anonCmds = buildStaticCommands({
      ...baseCtx,
      isAdminAuthenticated: false,
    });
    expect(anonCmds.map((c) => c.id)).not.toContain('nav-workspace');
    expect(anonCmds.map((c) => c.id)).toContain('pref-theme');
  });

  it('marks destructive commands with dangerous flag and filters by query', () => {
    const cmds = buildStaticCommands(baseCtx);
    const emptyTrash = cmds.find((c) => c.id === 'action-empty-trash');
    expect(emptyTrash?.dangerous).toBe(true);
    expect(emptyTrash?.actionCode).toBe('trash.empty');

    const matches = filterCommands(cmds, 'trash');
    expect(matches.some((c) => c.id === 'nav-trash')).toBe(true);
    expect(matches.some((c) => c.id === 'action-empty-trash')).toBe(true);
  });
});
