<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppContextMenu from '@/shared/ui/menus/AppContextMenu.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppSortableList from '@/shared/ui/fields/AppSortableList.vue';
import { LayoutGrid, Pencil, Settings } from '@/shared/ui/icons';
import type { DropdownOption, MenuItem } from '@/shared/ui/menus/types';

const { t } = useI18n();
const single = ref('ru');
const searchable = ref('en');
const multiple = ref<string[]>(['ru']);
const hierarchy = ref('');
const dialogValue = ref('ru');
const dialog = ref(false);
const message = ref('');
const selectedExamples = ref([true, false]);
const allExamples = computed({ get: () => selectedExamples.value.every(Boolean), set: (value: boolean) => { selectedExamples.value = [value, value]; } });
const someExamples = computed(() => selectedExamples.value.some(Boolean) && !allExamples.value);
const switchExample = ref(true);
const formName = ref('Changed');
const formUpdates = ref(true);
const sortableItems = ref(['Русский', 'English', 'Deutsch', 'Español', 'Français', '日本語']);
const languages = [ { value: 'ru', label: 'Русский' }, { value: 'en', label: 'English' }, { value: 'de', label: 'Deutsch' }, { value: 'es', label: 'Español' }, { value: 'fr', label: 'Français' }, { value: 'ja', label: '日本語' } ];
const options = computed<DropdownOption[]>(() => [...languages, { value: 'disabled', label: t('menus.unavailable'), disabled: true }]);
const tree = computed<DropdownOption[]>(() => [
  { value: 'tools', label: t('menus.tools'), icon: Settings, children: [
    { value: 'design', label: t('menus.design'), icon: Pencil, children: [
      { value: 'colors', label: t('menus.colors'), description: 'RGB · HEX' }, { value: 'typography', label: t('menus.typography') },
    ] },
    { value: 'development', label: t('menus.development'), children: [{ value: 'frontend', label: t('menus.frontend') }, { value: 'backend', label: t('menus.backend') }] },
  ] },
  { value: 'languages', label: t('menus.language'), icon: LayoutGrid, children: languages },
]);
const contextItems = computed<MenuItem[]>(() => [
  { id: 'group', kind: 'group', label: t('menus.context') },
  { id: 'message', label: t('menus.contextAction'), icon: Pencil },
  { id: 'separator', kind: 'separator', label: '' },
  { id: 'nested', label: t('menus.tools'), children: [{ id: 'nested-message', label: t('menus.contextAction') }] },
  { id: 'disabled', label: t('menus.unavailable'), disabled: true },
]);
</script>

<template>
  <div class="components-view">
    <header><h2>{{ $t('menus.demoTitle') }}</h2><p>{{ $t('menus.demoDescription') }}</p></header>
    <div class="components-view__grid">
      <AppCard>
        <h3>{{ $t('controls.checkboxes') }}</h3><p>{{ $t('controls.checkboxHint') }}</p>
        <AppCheckbox id="demo-check-all" v-model="allExamples" :indeterminate="someExamples" :label="$t('controls.selectAll')" />
        <div class="components-view__check-children">
          <AppCheckbox id="demo-check-first" v-model="selectedExamples[0]" :label="$t('controls.firstOption')" />
          <AppCheckbox id="demo-check-second" v-model="selectedExamples[1]" :label="$t('controls.secondOption')" />
        </div>
        <AppCheckbox id="demo-check-disabled" :model-value="true" :label="$t('controls.unavailable')" disabled />
      </AppCard>
      <AppCard>
        <h3>{{ $t('controls.switches') }}</h3><p>{{ $t('controls.switchHint') }}</p>
        <AppSwitch id="demo-switch" v-model="switchExample" :label="$t('controls.switchExample')" :description="$t('controls.switchDescription')" />
        <AppSwitch id="demo-switch-disabled" :model-value="false" :label="$t('controls.unavailable')" disabled />
      </AppCard>
      <AppCard>
        <h3>{{ $t('controls.sortable') }}</h3><p>{{ $t('controls.sortableHint') }}</p>
        <AppSortableList id="demo-sortable" v-model="sortableItems" />
      </AppCard>
      <AppCard>
        <h3>{{ $t('controls.nativeForm') }}</h3><p>{{ $t('controls.nativeFormHint') }}</p>
        <form id="demo-native-form" class="components-view__form">
          <AppInput id="demo-form-name" v-model="formName" :label="$t('controls.formName')" name="username" />
          <AppSwitch id="demo-form-updates" v-model="formUpdates" :label="$t('controls.formUpdates')" name="updates" />
          <output id="demo-form-model" class="components-view__hint" for="demo-form-name demo-form-updates">
            {{ $t('controls.formState') }}: {{ formName || '—' }} · {{ formUpdates ? 'on' : 'off' }}
          </output>
          <div class="components-view__form-actions">
            <AppButton id="demo-form-reset" type="reset" variant="secondary">{{ $t('controls.resetForm') }}</AppButton>
          </div>
        </form>
      </AppCard>
    </div>
    <div class="components-view__grid">
      <AppCard><label for="demo-single">{{ $t('menus.simple') }}</label><AppDropdown id="demo-single" v-model="single" :label="$t('menus.simple')" :options="options" /></AppCard>
      <AppCard><label for="demo-search">{{ $t('menus.searchable') }}</label><AppDropdown id="demo-search" v-model="searchable" :label="$t('menus.searchable')" :options="options" searchable clearable /></AppCard>
      <AppCard><label for="demo-multiple">{{ $t('menus.multiple') }}</label><AppDropdown id="demo-multiple" v-model="multiple" :label="$t('menus.multiple')" :options="options" searchable multiple clearable /></AppCard>
      <AppCard><label for="demo-hierarchy">{{ $t('menus.hierarchy') }}</label><AppDropdown id="demo-hierarchy" v-model="hierarchy" :label="$t('menus.hierarchy')" :options="tree" searchable clearable><template #footer><p class="components-view__hint">{{ $t('menus.footer') }}</p></template></AppDropdown></AppCard>
    </div>
    <AppContextMenu :items="contextItems" :label="$t('menus.context')" @select="message = $t('menus.contextDone')">
      <div id="demo-context" class="components-view__context" tabindex="0" role="region" :aria-label="$t('menus.context')"><h3>{{ $t('menus.context') }}</h3><p>{{ $t('menus.contextHint') }}</p><p role="status">{{ message }}</p></div>
    </AppContextMenu>
    <AppButton id="demo-dialog-open" variant="secondary" @click="dialog = true">{{ $t('menus.dialog') }}</AppButton>
    <AppDialog v-model="dialog" :title="$t('menus.dialogTitle')"><AppDropdown id="demo-dialog" v-model="dialogValue" :label="$t('menus.language')" :options="options" searchable /><AppButton @click="dialog = false">{{ $t('common.close') }}</AppButton></AppDialog>
  </div>
</template>

<style scoped>
.components-view { display: grid; gap: var(--space-24); min-width: 0; }
.components-view h2 { font-size: var(--type-h2-size); line-height: var(--type-h2-line); }
.components-view h3 { font-size: var(--type-h3-size); line-height: var(--type-h3-line); }
.components-view__check-children { padding-inline-start: var(--space-24); border-inline-start: 1px solid var(--border); transition: var(--theme-transition); }
.components-view p { color: var(--text-2); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); transition: var(--theme-transition); }
.components-view__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: var(--space-20); min-width: 0; }
.components-view__grid .app-card { display: grid; align-content: start; gap: var(--space-12); min-width: 0; }
.components-view__grid label { font-size: var(--type-body-medium-emphasis-size); font-weight: var(--type-body-medium-emphasis-weight); }
.components-view__context { border: 1px dashed var(--border-strong); border-radius: var(--radius-card-m); background: var(--surface); padding: var(--space-24); display: grid; gap: var(--space-12); transition: var(--theme-transition); }
.components-view__hint { font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); padding: var(--space-8); color: var(--text-2); transition: var(--theme-transition); }
.components-view__form { display: grid; gap: var(--space-16); min-width: 0; }
.components-view__form-actions { display: flex; justify-content: flex-end; }
</style>
