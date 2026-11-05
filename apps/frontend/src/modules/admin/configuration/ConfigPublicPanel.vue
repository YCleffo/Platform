<script setup lang="ts">
import { computed, ref } from 'vue';
import { coreConfiguration } from '@/shared/api/core/configuration';
import { useConfigCollection } from './useConfigCollection';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import ConfigValueView from './ConfigValueView.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
const search = ref('');
const collection = useConfigCollection(async () =>
  Object.entries(await coreConfiguration.publicSettings()).map(([key, value]) => ({ key, value })),
);
const { items, loading, ready, error } = collection;
const filtered = computed(() =>
  items.value.filter((row) =>
    row.key.toLocaleLowerCase().includes(search.value.toLocaleLowerCase()),
  ),
);
void collection.load();
</script>
<template>
  <section class="config-page">
    <header class="config-head">
      <h2>{{ $t('configuration.publicConfig') }}</h2>
      <AppButton
        variant="secondary"
        :loading="loading"
        @click="collection.load"
      >
        {{ $t('configuration.refresh') }}
      </AppButton>
    </header>
    <p class="config-note">{{ $t('configuration.publicHint') }}</p>
    <AppErrorState
      v-if="error"
      :message="resolveErrorMessage(error)"
    />
    <AppInput
      id="config-public-search"
      v-model="search"
      class="config-search"
      :label="$t('configuration.searchSettings')"
      type="search"
    />
    <p
      v-if="loading && !items.length"
      role="status"
    >
      {{ $t('common.loading') }}
    </p>
    <p
      v-else-if="!filtered.length && ready"
      class="config-note"
    >
      {{ $t('configuration.emptyPublic') }}
    </p>
    <div class="config-grid">
      <article
        v-for="row in filtered"
        :key="row.key"
        class="config-card"
      >
        <h3>{{ row.key }}</h3>
        <ConfigValueView :value="row.value" />
      </article>
    </div>
  </section>
</template>
