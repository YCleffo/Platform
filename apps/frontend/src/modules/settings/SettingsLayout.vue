<template>
  <section class="settings-layout">
    <header class="settings-layout__header">
      <h1>{{ $t('appearance.title') }}</h1>
      <p>{{ $t('appearance.subtitle') }}</p>
    </header>
    <nav class="settings-layout__tabs" :aria-label="$t('appearance.sections')">
      <RouterLink :to="{ name: 'settings' }" active-class="" exact-active-class="settings-layout__tab--active">{{ $t('appearance.appearance') }}</RouterLink>
      <RouterLink :to="{ name: 'security' }" active-class="" exact-active-class="settings-layout__tab--active">{{ $t('nav.security') }}</RouterLink>
      <RouterLink :to="{ name: 'privacy' }" active-class="" exact-active-class="settings-layout__tab--active">{{ $t('privacy.title') }}</RouterLink>
      <RouterLink :to="{ name: 'settings-components' }" active-class="" exact-active-class="settings-layout__tab--active">{{ $t('menus.components') }}</RouterLink>
    </nav>
    <RouterView />
  </section>
</template>

<style scoped>
.settings-layout { width: 100%; max-width: 1120px; margin: 0 auto; display: grid; gap: var(--space-24); }
.settings-layout__header h1 { font-size: var(--type-h1-size); line-height: var(--type-h1-line); font-weight: var(--type-h1-weight); }
.settings-layout__header p { margin-top: var(--space-6); color: var(--text-2); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); transition: var(--theme-transition); }
.settings-layout__tabs { display: flex; gap: var(--gap-s); flex-wrap: wrap; border-bottom: 1px solid var(--border); padding-bottom: var(--space-12); }
.settings-layout__tabs a { color: var(--text-2); padding: var(--space-8) var(--space-12); border-radius: var(--radius-control-m); font-size: var(--type-body-medium-emphasis-size); font-weight: var(--type-body-medium-emphasis-weight); transition: var(--theme-transition); }
.settings-layout__tabs a:hover { background: var(--surface-hover); transition: var(--theme-transition); }
.settings-layout__tabs a.settings-layout__tab--active { color: var(--accent-text); background: var(--accent-soft); transition: var(--theme-transition); }
</style>
