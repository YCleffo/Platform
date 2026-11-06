<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import { operatorHttp, operatorIdentity } from './session';

interface CatalogPrice {
  id?: string;
  currency: string;
  amount_minor: number;
  billing_period: string;
}
interface CatalogPlan {
  id: string;
  code: string;
  version: number;
  status: string;
  name: string;
  prices: CatalogPrice[];
  features: Record<string, unknown>;
}
interface PriceRow {
  currency: string;
  amount_minor: string;
  billing_period: string;
}
interface FeatureRow {
  code: string;
  value: string;
}
const { t } = useI18n();
const plans = ref<CatalogPlan[]>([]);
const selected = ref<CatalogPlan | null>(null);
const creating = ref(false);
const busy = ref(false);
const loading = ref(false);
const error = ref('');
const form = reactive({
  code: '',
  name: '',
  prices: [{ currency: 'RUB', amount_minor: '', billing_period: 'month' }] as PriceRow[],
  features: [] as FeatureRow[],
});
const canEdit = computed(() => creating.value || selected.value?.status === 'draft');
const canPublish = computed(() => selected.value?.status === 'draft');

function resetForm(): void {
  form.code = '';
  form.name = '';
  form.prices = [{ currency: 'RUB', amount_minor: '', billing_period: 'month' }];
  form.features = [];
}

function featureText(value: unknown): string {
  if (value === undefined) return '';
  if (typeof value === 'string') return value;
  return JSON.stringify(value);
}

function featureValue(value: string): unknown {
  const trimmed = value.trim();
  if (trimmed === 'true') return true;
  if (trimmed === 'false') return false;
  if (/^-?\d+$/.test(trimmed)) return Number(trimmed);
  if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
    try {
      return JSON.parse(trimmed);
    } catch {
      throw new Error(t('operator.catalogEditor.invalidFeature'));
    }
  }
  return trimmed;
}

function pricesPayload(): CatalogPrice[] {
  return form.prices.map((price) => {
    if (price.amount_minor.trim() === '')
      throw new Error(t('operator.catalogEditor.invalidAmount'));
    const amount = Number(price.amount_minor);
    if (!Number.isSafeInteger(amount) || amount < 0) {
      throw new Error(t('operator.catalogEditor.invalidAmount'));
    }
    return {
      currency: price.currency.trim().toUpperCase(),
      amount_minor: amount,
      billing_period: price.billing_period,
    };
  });
}

function featuresPayload(): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  for (const feature of form.features) {
    const code = feature.code.trim();
    if (code === '') continue;
    result[code] = featureValue(feature.value);
  }
  return result;
}

async function load(): Promise<void> {
  if (!operatorIdentity.value?.catalog) return;
  loading.value = true;
  error.value = '';
  try {
    const result = await operatorHttp.get<{ plans: CatalogPlan[] }>('/operator/catalog/plans', {
      retryTransient: false,
    });
    plans.value = result.plans;
  } catch (e) {
    error.value = resolveErrorMessage(e);
  } finally {
    loading.value = false;
  }
}

function startCreate(): void {
  selected.value = null;
  creating.value = true;
  error.value = '';
  resetForm();
}

function startEdit(plan: CatalogPlan): void {
  selected.value = plan;
  creating.value = false;
  error.value = '';
  form.code = plan.code;
  form.name = plan.name;
  form.prices = plan.prices.map((price) => ({
    currency: price.currency,
    amount_minor: String(price.amount_minor),
    billing_period: price.billing_period,
  }));
  form.features = Object.entries(plan.features).map(([code, value]) => ({
    code,
    value: featureText(value),
  }));
}

async function save(): Promise<void> {
  if (!canEdit.value || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const payload = {
      code: form.code.trim().toLowerCase(),
      name: form.name.trim(),
      prices: pricesPayload(),
      features: featuresPayload(),
    };
    const result =
      selected.value === null || creating.value
        ? await operatorHttp.post<{ plan: CatalogPlan }>('/operator/catalog/plans', payload, {
            retryTransient: false,
          })
        : await operatorHttp.request<{ plan: CatalogPlan }>(
            `/operator/catalog/plans/${selected.value.id}`,
            {
              method: 'PATCH',
              body: payload,
              retryTransient: false,
            },
          );
    await load();
    startEdit(result.plan);
  } catch (e) {
    error.value = resolveErrorMessage(e);
  } finally {
    busy.value = false;
  }
}

async function publish(): Promise<void> {
  if (!canPublish.value || busy.value || selected.value === null) return;
  if (!window.confirm(t('operator.catalogEditor.publishConfirm'))) return;
  busy.value = true;
  error.value = '';
  try {
    const result = await operatorHttp.post<{ plan: CatalogPlan }>(
      `/operator/catalog/plans/${selected.value.id}/publish`,
      {},
      { retryTransient: false },
    );
    await load();
    startEdit(result.plan);
  } catch (e) {
    error.value = resolveErrorMessage(e);
  } finally {
    busy.value = false;
  }
}

async function newVersion(): Promise<void> {
  if (selected.value === null || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    const result = await operatorHttp.post<{ plan: CatalogPlan }>(
      `/operator/catalog/plans/${selected.value.id}/versions`,
      {},
      { retryTransient: false },
    );
    await load();
    startEdit(result.plan);
  } catch (e) {
    error.value = resolveErrorMessage(e);
  } finally {
    busy.value = false;
  }
}

function addPrice(): void {
  form.prices.push({ currency: 'RUB', amount_minor: '', billing_period: 'month' });
}
function removePrice(index: number): void {
  form.prices.splice(index, 1);
  if (form.prices.length === 0) addPrice();
}
function addFeature(): void {
  form.features.push({ code: '', value: '' });
}
function removeFeature(index: number): void {
  form.features.splice(index, 1);
}
function statusLabel(status: string): string {
  return t('operator.catalogEditor.status.' + status);
}
onMounted(load);
</script>

<template>
  <section class="catalog-editor">
    <header class="catalog-editor__header">
      <div>
        <h1 class="catalog-editor__title">{{ $t('operator.catalogEditor.title') }}</h1>
        <p class="catalog-editor__subtitle">{{ $t('operator.catalogEditor.subtitle') }}</p>
      </div>
      <div class="catalog-editor__header-actions">
        <RouterLink
          class="catalog-editor__back"
          :to="{ name: 'operator-console' }"
        >
          {{ $t('operator.catalogEditor.back') }}
        </RouterLink>
        <AppButton
          v-if="operatorIdentity?.catalog"
          :disabled="busy"
          @click="startCreate"
        >
          {{ $t('operator.catalogEditor.newPlan') }}
        </AppButton>
      </div>
    </header>

    <AppCard v-if="!operatorIdentity?.catalog">
      <AppEmptyState
        :title="$t('operator.catalogEditor.permissionTitle')"
        :description="$t('operator.catalogEditor.permissionText')"
      >
        <RouterLink
          class="catalog-editor__back"
          :to="{ name: 'operator-console' }"
        >
          {{ $t('operator.catalogEditor.signIn') }}
        </RouterLink>
      </AppEmptyState>
    </AppCard>

    <p
      v-else-if="error"
      class="catalog-editor__error"
      role="alert"
    >
      {{ error }}
    </p>

    <div
      v-else
      class="catalog-editor__layout"
    >
      <AppCard
        class="catalog-editor__list"
        padding="default"
      >
        <h2 class="catalog-editor__heading">{{ $t('operator.catalogEditor.plans') }}</h2>
        <p
          v-if="loading"
          class="catalog-editor__muted"
        >
          {{ $t('common.loading') }}
        </p>
        <p
          v-else-if="plans.length === 0"
          class="catalog-editor__muted"
        >
          {{ $t('operator.catalogEditor.empty') }}
        </p>
        <div
          v-else
          class="catalog-editor__scroll"
          role="region"
          :aria-label="$t('operator.catalogEditor.plans')"
          tabindex="0"
        >
          <table class="catalog-editor__table">
            <caption class="catalog-editor__caption">
              {{ $t('operator.catalogEditor.plans') }}
            </caption>
            <thead>
              <tr>
                <th scope="col">code</th>
                <th scope="col">{{ $t('operator.catalogEditor.name') }}</th>
                <th scope="col">v</th>
                <th scope="col">status</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="plan in plans"
                :key="plan.id"
                :class="{ 'is-selected': selected?.id === plan.id }"
              >
                <td>
                  <code>{{ plan.code }}</code>
                </td>
                <td>{{ plan.name }}</td>
                <td>{{ plan.version }}</td>
                <td>
                  <span :class="['catalog-editor__status', 'is-' + plan.status]">
                    {{ statusLabel(plan.status) }}
                  </span>
                </td>
                <td class="catalog-editor__row-actions">
                  <AppButton
                    v-if="plan.status === 'draft'"
                    variant="secondary"
                    size="s"
                    @click="startEdit(plan)"
                  >
                    {{ $t('operator.catalogEditor.edit') }}
                  </AppButton>
                  <AppButton
                    v-else
                    variant="secondary"
                    size="s"
                    @click="startEdit(plan)"
                  >
                    {{ $t('operator.catalogEditor.view') }}
                  </AppButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AppCard>

      <AppCard
        v-if="creating || selected"
        class="catalog-editor__form"
        padding="default"
      >
        <h2 class="catalog-editor__heading">
          {{
            creating
              ? $t('operator.catalogEditor.createTitle')
              : $t('operator.catalogEditor.editTitle')
          }}
        </h2>
        <p
          v-if="selected"
          class="catalog-editor__muted"
        >
          {{ selected.code }} Р’В· v{{ selected.version }} Р’В· {{ statusLabel(selected.status) }}
        </p>

        <AppInput
          id="catalog-code"
          v-model="form.code"
          :label="$t('operator.catalogEditor.code')"
          :disabled="selected !== null && !creating"
          :hint="$t('operator.catalogEditor.codeHint')"
        />
        <AppInput
          id="catalog-name"
          v-model="form.name"
          :label="$t('operator.catalogEditor.name')"
          :disabled="!canEdit || busy"
          required
        />

        <fieldset
          class="catalog-editor__fieldset"
          :disabled="!canEdit || busy"
        >
          <legend>{{ $t('operator.catalogEditor.prices') }}</legend>
          <div
            v-for="(price, index) in form.prices"
            :key="index"
            class="catalog-editor__row"
          >
            <label class="catalog-editor__field">
              <span>{{ $t('operator.catalogEditor.currency') }}</span>
              <select
                v-model="price.currency"
                class="catalog-editor__select"
              >
                <option value="RUB">RUB</option>
              </select>
            </label>
            <label class="catalog-editor__field">
              <span>{{ $t('operator.catalogEditor.amount') }}</span>
              <input
                v-model="price.amount_minor"
                class="catalog-editor__input"
                inputmode="numeric"
                min="0"
                type="number"
              />
            </label>
            <label class="catalog-editor__field">
              <span>{{ $t('operator.catalogEditor.period') }}</span>
              <select
                v-model="price.billing_period"
                class="catalog-editor__select"
              >
                <option value="month">{{ $t('catalog.period_month') }}</option>
                <option value="year">{{ $t('catalog.period_year') }}</option>
                <option value="one_time">{{ $t('catalog.period_one_time') }}</option>
              </select>
            </label>
            <AppButton
              variant="secondary"
              size="s"
              :disabled="!canEdit || busy"
              @click="removePrice(index)"
            >
              Р“вЂ”
            </AppButton>
          </div>
          <p class="catalog-editor__hint">{{ $t('operator.catalogEditor.amountHint') }}</p>
          <AppButton
            variant="secondary"
            size="s"
            :disabled="!canEdit || busy"
            @click="addPrice"
          >
            {{ $t('operator.catalogEditor.addPrice') }}
          </AppButton>
        </fieldset>

        <fieldset
          class="catalog-editor__fieldset"
          :disabled="!canEdit || busy"
        >
          <legend>{{ $t('operator.catalogEditor.features') }}</legend>
          <div
            v-for="(feature, index) in form.features"
            :key="index"
            class="catalog-editor__row"
          >
            <AppInput
              :id="'catalog-feature-code-' + index"
              v-model="feature.code"
              :label="$t('operator.catalogEditor.featureCode')"
              :disabled="!canEdit || busy"
            />
            <AppInput
              :id="'catalog-feature-value-' + index"
              v-model="feature.value"
              :label="$t('operator.catalogEditor.featureValue')"
              :disabled="!canEdit || busy"
            />
            <AppButton
              variant="secondary"
              size="s"
              :disabled="!canEdit || busy"
              @click="removeFeature(index)"
            >
              Р“вЂ”
            </AppButton>
          </div>
          <p class="catalog-editor__hint">{{ $t('operator.catalogEditor.featuresHint') }}</p>
          <AppButton
            variant="secondary"
            size="s"
            :disabled="!canEdit || busy"
            @click="addFeature"
          >
            {{ $t('operator.catalogEditor.addFeature') }}
          </AppButton>
        </fieldset>

        <div class="catalog-editor__form-actions">
          <AppButton
            v-if="canEdit"
            :loading="busy"
            @click="save"
          >
            {{ $t('operator.catalogEditor.save') }}
          </AppButton>
          <AppButton
            v-if="canPublish"
            :loading="busy"
            variant="secondary"
            @click="publish"
          >
            {{ $t('operator.catalogEditor.publish') }}
          </AppButton>
          <AppButton
            v-if="selected && selected.status !== 'draft'"
            :loading="busy"
            variant="secondary"
            @click="newVersion"
          >
            {{ $t('operator.catalogEditor.newVersion') }}
          </AppButton>
        </div>
      </AppCard>
    </div>
  </section>
</template>

<style scoped>
.catalog-editor {
  max-width: 1180px;
  margin: 0 auto;
}
.catalog-editor__header {
  display: flex;
  justify-content: space-between;
  gap: var(--space-16);
  align-items: flex-start;
  margin-bottom: var(--space-20);
}
.catalog-editor__header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  flex-wrap: wrap;
}
.catalog-editor__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
}
.catalog-editor__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
}
.catalog-editor__back {
  color: var(--accent-strong);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-decoration: underline;
  text-underline-offset: 3px;
}
.catalog-editor__layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: var(--space-16);
  align-items: start;
}
.catalog-editor__heading {
  margin-bottom: var(--space-12);
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  color: var(--text-1);
}
.catalog-editor__muted {
  margin-bottom: var(--space-8);
  color: var(--text-3);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
.catalog-editor__scroll {
  overflow-x: auto;
}
.catalog-editor__table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
.catalog-editor__caption {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}
.catalog-editor__table th,
.catalog-editor__table td {
  padding: var(--space-8);
  border-bottom: 1px solid var(--border);
  text-align: left;
  vertical-align: middle;
}
.catalog-editor__table th {
  color: var(--text-3);
  font-weight: var(--type-body-small-weight);
}
.catalog-editor__status {
  display: inline-block;
  padding: 2px 8px;
  border-radius: var(--radius-badge-pill);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}
.catalog-editor__status.is-published {
  background: var(--success-soft);
  color: var(--success-text);
}
.catalog-editor__status.is-retired {
  background: var(--danger-soft);
  color: var(--danger-text);
}
.catalog-editor__row-actions {
  text-align: right;
}
.catalog-editor__form {
  display: grid;
  gap: var(--space-12);
}
.catalog-editor__fieldset {
  display: grid;
  gap: var(--space-8);
  margin: 0;
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
}
.catalog-editor__fieldset legend {
  padding: 0 var(--space-4);
  color: var(--text-1);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}
.catalog-editor__row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
  gap: var(--space-8);
  align-items: end;
}
.catalog-editor__field {
  display: grid;
  gap: var(--space-4);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
.catalog-editor__input,
.catalog-editor__select {
  min-height: 40px;
  width: 100%;
  padding: 0 var(--space-8);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-s);
  background: var(--card);
  color: var(--text-1);
  font-family: var(--font-sans);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}
.catalog-editor__hint {
  color: var(--text-3);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}
.catalog-editor__form-actions {
  display: flex;
  gap: var(--space-8);
  flex-wrap: wrap;
}
.catalog-editor__error {
  padding: var(--space-12);
  border: 1px solid var(--danger);
  border-radius: var(--radius-card-s);
  color: var(--danger-text);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}
@media (max-width: 900px) {
  .catalog-editor__layout {
    grid-template-columns: 1fr;
  }
  .catalog-editor__row {
    grid-template-columns: 1fr;
  }
  .catalog-editor__header {
    flex-direction: column;
  }
}
</style>
