<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { commercialAccount, commercialBilling, commercialCatalog } from '@/core/api';
import { useAccountStore } from '@/core/account/accountStore';
import { RequestAbortedError } from '@/shared/api/errors';
import { createIdempotencyKey } from '@/shared/api/idempotency';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import { formatMoney } from '@/shared/formatting/money';
import type {
  CheckoutResult,
  EffectiveEntitlements,
  Invoice,
  Payment,
  Plan,
  PlanChange,
  PlanPrice,
  Receipt,
  RefundList,
  SavedPaymentMethod,
  Subscription,
} from '@/shared/types';
import { useSensitiveAction } from '@/modules/commercial/security/useSensitiveAction';
import ReauthenticateDialog from '@/modules/commercial/security/ReauthenticateDialog.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';

const route = useRoute();
const store = useAccountStore();
const toast = useToast();
const { t, locale } = useI18n();

const accountId = computed(() => String(route.params.accountId ?? ''));
const detail = computed(() => store.accountDetail);
const canManage = computed(() => detail.value?.permissions.manage_billing === true);
const readyForSubscription = computed(() => detail.value?.onboarding_state === 'ready_for_subscription');
const canReviewCatalog = computed(() => detail.value?.role === 'owner' || detail.value?.role === 'admin');

const loading = ref(true);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const actionBusy = ref(false);
const entitlements = ref<EffectiveEntitlements | null>(null);
const subscription = ref<Subscription | null>(null);
const methods = ref<SavedPaymentMethod[]>([]);
const catalogPlans = ref<Plan[]>([]);
const selectedPriceId = ref('');
const quantity = ref(1);
const checkoutMethod = ref<'bank_card' | 'sbp'>('bank_card');
const savePaymentMethod = ref(true);
const checkoutResult = ref<CheckoutResult | null>(null);
const lastInvoice = ref<Invoice | null>(null);
const lastPayment = ref<Payment | null>(null);
const changeTargetPriceId = ref('');
const changeQuantity = ref(1);
const planChange = ref<PlanChange | null>(null);

const profile = reactive({
  legal_name: '',
  country_code: 'RU',
  contact_name: '',
  contact_email: '',
  tax_identifier: '',
  city: '',
  line1: '',
  postal_code: '',
});
const profileError = ref<string | null>(null);
const profileKey = ref<string | null>(null);

const invoiceDoc = ref<Invoice | null>(null);
const paymentDoc = ref<Payment | null>(null);
const receiptDoc = ref<Receipt | null>(null);
const refundsDoc = ref<RefundList | null>(null);
const documentError = ref<string | null>(null);
const documentLoading = ref(false);

const confirmOpen = ref(false);
const confirmTitle = ref('');
const confirmDescription = ref('');
const confirmLabel = ref('');
const confirmAction = ref<null | (() => Promise<void>)>(null);

const { reauthOpen, runSensitive, onReauthenticated: retryPending } = useSensitiveAction((message) => {
  actionError.value = message;
});

const priceOptions = computed(() => catalogPlans.value.flatMap(plan => plan.prices.map(price => ({
  value: price.id,
  label: `${plan.name} · ${price.billing_period} · ${formatMoney(price.amount_minor, price.currency, locale.value)}`,
}))));

const selectedPrice = computed<PlanPrice | null>(() => {
  for (const plan of catalogPlans.value) {
    const price = plan.prices.find(item => item.id === selectedPriceId.value);
    if (price !== undefined) {
      return price;
    }
  }
  return null;
});

const planChangeRequested = computed(() => planChange.value !== null && planChange.value.status !== 'cancelled');
const canPayPlanChange = computed(() => planChange.value?.status === 'payment_required' && planChange.value.invoice_id !== null);

interface CheckoutFlow {
  subscriptionKey: string;
  invoiceKey: string;
  paymentKey: string;
  checkoutKey: string;
  subscriptionId: string | null;
  invoice: Invoice | null;
  payment: Payment | null;
}

interface InvoiceFlow {
  invoiceId: string;
  paymentKey: string;
  checkoutKey: string;
  payment: Payment | null;
}

const checkoutFlow = ref<CheckoutFlow | null>(null);
const invoiceFlow = ref<InvoiceFlow | null>(null);
const planChangeKey = ref<string | null>(null);

watch([selectedPriceId, quantity], () => {
  if (!actionBusy.value) {
    checkoutFlow.value = null;
    checkoutResult.value = null;
  }
});

watch(checkoutMethod, () => {
  if (checkoutFlow.value !== null && !actionBusy.value) {
    checkoutFlow.value.checkoutKey = createIdempotencyKey();
    checkoutResult.value = null;
  }
});

watch([changeTargetPriceId, changeQuantity], () => {
  planChangeKey.value = null;
});

watch(profile, () => {
  profileKey.value = null;
}, { deep: true });

function resetProfile(): void {
  profile.legal_name = detail.value?.legal_name ?? detail.value?.display_name ?? '';
  profile.contact_email = '';
  profile.contact_name = '';
  profile.tax_identifier = '';
  profile.country_code = 'RU';
  profile.city = '';
  profile.line1 = '';
  profile.postal_code = '';
}

async function load(): Promise<void> {
  const id = accountId.value;
  if (id === '') {
    return;
  }
  loading.value = true;
  loadError.value = null;
  try {
    const [nextEntitlements, catalog] = await Promise.all([
      commercialBilling.entitlements(id),
      commercialCatalog.listPlans(),
    ]);
    if (id !== accountId.value) {
      return;
    }
    entitlements.value = nextEntitlements;
    catalogPlans.value = catalog.plans;
    const requestedPlan = typeof route.query.planPrice === 'string' ? route.query.planPrice : '';
    if (requestedPlan !== '' && priceOptions.value.some(option => option.value === requestedPlan)) {
      selectedPriceId.value = requestedPlan;
    } else if (selectedPriceId.value === '') {
      selectedPriceId.value = priceOptions.value[0]?.value ?? '';
    }
    if (changeTargetPriceId.value === '') {
      changeTargetPriceId.value = selectedPriceId.value;
    }
    subscription.value = nextEntitlements.subscription === null
      ? null
      : await commercialBilling.subscription(id, nextEntitlements.subscription.id);
    if (subscription.value !== null && !priceOptions.value.some(option => option.value === changeTargetPriceId.value)) {
      changeTargetPriceId.value = subscription.value.items[0]?.price.id ?? selectedPriceId.value;
    }
    const methodList = await commercialBilling.paymentMethods(id);
    if (id !== accountId.value) {
      return;
    }
    methods.value = methodList.items;
    resetProfile();
  } catch (error) {
    if (error instanceof RequestAbortedError || id !== accountId.value) {
      return;
    }
    loadError.value = resolveErrorMessage(error);
  } finally {
    loading.value = false;
  }
}

async function perform(action: () => Promise<void>, successMessage?: string): Promise<void> {
  actionBusy.value = true;
  actionError.value = null;
  try {
    const completed = await runSensitive(action);
    if (completed && successMessage !== undefined) {
      toast.show(successMessage, 'success');
    }
  } finally {
    actionBusy.value = false;
  }
}

async function onReauthenticated(): Promise<void> {
  actionBusy.value = true;
  try {
    await retryPending();
  } finally {
    actionBusy.value = false;
  }
}

async function reviewCatalog(): Promise<void> {
  await perform(async () => {
    await commercialAccount.reviewCatalog(accountId.value);
    await store.loadAccount(accountId.value);
  }, t('billing.catalogReviewed'));
}

async function saveProfile(): Promise<void> {
  if (!canManage.value) {
    return;
  }
  profileError.value = null;
  if (profile.legal_name.trim() === '' || profile.country_code.trim().length !== 2) {
    profileError.value = t('billing.profileRequired');
    return;
  }
  await perform(async () => {
    await commercialBilling.saveBillingProfile(accountId.value, {
      legal_name: profile.legal_name.trim(),
      country_code: profile.country_code.trim().toUpperCase(),
      contact_name: profile.contact_name.trim() || null,
      contact_email: profile.contact_email.trim() || null,
      tax_identifier: profile.tax_identifier.trim() || null,
      address: {
        city: profile.city.trim(),
        line1: profile.line1.trim(),
        postal_code: profile.postal_code.trim(),
      },
    });
    await store.loadAccount(accountId.value);
  }, t('billing.profileSaved'));
}

async function startSubscription(): Promise<void> {
  if (!canManage.value || selectedPrice.value === null || quantity.value < 1) {
    return;
  }
  await perform(async () => {
    const current = checkoutFlow.value ?? {
      subscriptionKey: createIdempotencyKey(),
      invoiceKey: createIdempotencyKey(),
      paymentKey: createIdempotencyKey(),
      checkoutKey: createIdempotencyKey(),
      subscriptionId: null,
      invoice: null,
      payment: null,
    };
    checkoutFlow.value = current;

    if (current.subscriptionId === null) {
      const created = await commercialBilling.createSubscription(
        accountId.value,
        selectedPriceId.value,
        quantity.value,
        current.subscriptionKey,
      );
      current.subscriptionId = created.id;
    }
    if (current.invoice === null) {
      current.invoice = await commercialBilling.issueInvoice(accountId.value, current.subscriptionId, current.invoiceKey);
      lastInvoice.value = current.invoice;
    }
    if (current.payment === null) {
      current.payment = await commercialBilling.createPayment(accountId.value, current.invoice.id, current.paymentKey);
      lastPayment.value = current.payment;
    }
    checkoutResult.value = await commercialBilling.checkout(
      accountId.value,
      current.payment.id,
      checkoutMethod.value,
      savePaymentMethod.value,
      current.checkoutKey,
    );
    subscription.value = await commercialBilling.subscription(accountId.value, current.subscriptionId);
    entitlements.value = await commercialBilling.entitlements(accountId.value);
  }, t('billing.checkoutReady'));
}

async function payInvoiceById(invoiceId: string): Promise<void> {
  if (!canManage.value) {
    return;
  }
  await perform(async () => {
    const current = invoiceFlow.value?.invoiceId === invoiceId ? invoiceFlow.value : {
      invoiceId,
      paymentKey: createIdempotencyKey(),
      checkoutKey: createIdempotencyKey(),
      payment: null,
    };
    invoiceFlow.value = current;
    current.payment ??= await commercialBilling.createPayment(accountId.value, invoiceId, current.paymentKey);
    checkoutResult.value = await commercialBilling.checkout(
      accountId.value,
      current.payment.id,
      checkoutMethod.value,
      savePaymentMethod.value,
      current.checkoutKey,
    );
    lastInvoice.value = await commercialBilling.invoice(accountId.value, invoiceId);
    lastPayment.value = current.payment;
  }, t('billing.checkoutReady'));
}

async function payPlanChange(): Promise<void> {
  const invoiceId = planChange.value?.invoice_id;
  if (invoiceId !== null && invoiceId !== undefined) {
    await payInvoiceById(invoiceId);
  }
}

async function continuePayment(paymentId: string): Promise<void> {
  if (!canManage.value) {
    return;
  }
  await perform(async () => {
    checkoutResult.value = await commercialBilling.checkout(
      accountId.value,
      paymentId,
      checkoutMethod.value,
      savePaymentMethod.value,
      createIdempotencyKey(),
    );
    lastPayment.value = await commercialBilling.payment(accountId.value, paymentId);
  }, t('billing.checkoutReady'));
}

function onPlanChange(value: string | number | string[]): void {
  const next = Array.isArray(value) ? value[0] ?? '' : String(value);
  if (subscription.value !== null) {
    changeTargetPriceId.value = next;
  } else {
    selectedPriceId.value = next;
  }
}

function onQuantityChange(value: string | number): void {
  const parsed = Number(value);
  if (Number.isFinite(parsed) && parsed >= 1) {
    if (subscription.value !== null) {
      changeQuantity.value = parsed;
    } else {
      quantity.value = parsed;
    }
  }
}

async function requestPlanChange(): Promise<void> {
  if (!canManage.value || subscription.value === null || changeTargetPriceId.value === '') {
    return;
  }
  await perform(async () => {
    planChangeKey.value ??= createIdempotencyKey();
    const requested = await commercialBilling.requestPlanChange(
      accountId.value,
      subscription.value!.id,
      changeTargetPriceId.value,
      changeQuantity.value,
      planChangeKey.value,
    );
    planChange.value = await commercialBilling.planChange(accountId.value, requested.id);
  }, t('billing.planChangeRequested'));
}

async function cancelPlanChange(): Promise<void> {
  if (planChange.value === null) {
    return;
  }
  await perform(async () => {
    await commercialBilling.cancelPlanChange(accountId.value, planChange.value!.id, createIdempotencyKey());
    planChange.value = null;
  }, t('billing.planChangeCancelled'));
}

async function cancelAutoRenew(): Promise<void> {
  if (subscription.value === null) {
    return;
  }
  await perform(async () => {
    await commercialBilling.disableAutoRenewal(accountId.value, subscription.value!.id, createIdempotencyKey());
    subscription.value = await commercialBilling.subscription(accountId.value, subscription.value!.id);
  }, t('billing.autoRenewCancelled'));
}

async function cancelSubscription(): Promise<void> {
  if (subscription.value === null) {
    return;
  }
  await perform(async () => {
    await commercialBilling.cancelSubscription(accountId.value, subscription.value!.id, createIdempotencyKey());
    subscription.value = await commercialBilling.subscription(accountId.value, subscription.value!.id);
  }, t('billing.subscriptionCancelled'));
}

async function setDefaultMethod(method: SavedPaymentMethod): Promise<void> {
  await perform(async () => {
    await commercialBilling.setDefaultPaymentMethod(accountId.value, method.id, createIdempotencyKey());
    const list = await commercialBilling.paymentMethods(accountId.value);
    methods.value = list.items;
  }, t('billing.defaultMethodSaved'));
}

async function disableMethod(method: SavedPaymentMethod): Promise<void> {
  askConfirm(t('billing.disableMethodTitle'), t('billing.disableMethodText'), t('billing.disableMethod'), async () => {
    await commercialBilling.disablePaymentMethod(accountId.value, method.id, createIdempotencyKey());
    const list = await commercialBilling.paymentMethods(accountId.value);
    methods.value = list.items;
  });
}

function askConfirm(title: string, description: string, label: string, action: () => Promise<void>): void {
  confirmTitle.value = title;
  confirmDescription.value = description;
  confirmLabel.value = label;
  confirmAction.value = action;
  confirmOpen.value = true;
}

async function confirm(): Promise<void> {
  const action = confirmAction.value;
  if (action === null) {
    return;
  }
  confirmOpen.value = false;
  await perform(action);
}

async function loadDocument(action: () => Promise<void>): Promise<void> {
  documentLoading.value = true;
  documentError.value = null;
  try {
    await action();
  } catch (error) {
    documentError.value = resolveErrorMessage(error);
  } finally {
    documentLoading.value = false;
  }
}

function viewInvoice(id: string): void {
  void loadDocument(async () => {
    invoiceDoc.value = await commercialBilling.invoice(accountId.value, id);
    paymentDoc.value = null;
    receiptDoc.value = null;
    refundsDoc.value = null;
  });
}

function viewPayment(id: string): void {
  void loadDocument(async () => {
    const payment = await commercialBilling.payment(accountId.value, id);
    paymentDoc.value = payment;
    invoiceDoc.value = await commercialBilling.invoice(accountId.value, payment.invoice_id);
    receiptDoc.value = null;
    refundsDoc.value = null;
  });
}

function refundPayment(): void {
  const payment = paymentDoc.value;
  const invoice = invoiceDoc.value;
  if (payment === null || invoice === null) {
    return;
  }
  const total = invoice.lines.reduce((sum, line) => sum + line.total_minor, 0);
  if (total > payment.refundable_minor) {
    documentError.value = t('billing.refundTooLarge');
    return;
  }
  askConfirm(t('billing.refundTitle'), t('billing.refundText'), t('billing.refundPayment'), async () => {
    await commercialBilling.createRefund(
      accountId.value,
      payment.id,
      invoice.lines.map(line => ({
        invoice_line_id: line.id,
        quantity: line.quantity,
        unit_amount_minor: line.unit_amount_minor,
      })),
      createIdempotencyKey(),
    );
    toast.show(t('billing.refundCreated'), 'success');
    viewPayment(payment.id);
    viewRefunds(payment.id);
  });
}

function viewReceipt(id: string): void {
  void loadDocument(async () => {
    receiptDoc.value = await commercialBilling.receipt(accountId.value, id);
  });
}

function viewRefunds(id: string): void {
  void loadDocument(async () => {
    refundsDoc.value = await commercialBilling.refunds(accountId.value, id);
  });
}

function openCheckout(): void {
  const url = checkoutResult.value?.confirmation?.url;
  if (url !== undefined) {
    window.location.assign(url);
  }
}

onMounted(load);
watch(accountId, load);
</script>

<template>
  <section class="billing">
    <header class="billing__header">
      <div>
        <h2 class="billing__title">{{ $t('billing.title') }}</h2>
        <p class="billing__subtitle">{{ $t('billing.subtitle') }}</p>
      </div>
      <RouterLink :to="{ name: 'security' }">{{ $t('security.goToSecurity') }}</RouterLink>
    </header>

    <p v-if="actionError" class="billing__error" role="alert">
      {{ actionError }}
      <RouterLink :to="{ name: 'security' }">{{ $t('security.mfaRequired') }}</RouterLink>
    </p>

    <AppSkeleton v-if="loading" variant="card" :lines="5" />
    <AppErrorState v-else-if="loadError" :message="loadError">
      <AppButton variant="secondary" @click="load">{{ $t('common.retry') }}</AppButton>
    </AppErrorState>

    <template v-else>
      <AppCard v-if="subscription">
        <div class="billing__card-head">
          <div>
            <h3>{{ $t('billing.currentPlan') }}</h3>
            <p class="billing__muted">{{ $t('billing.subscriptionId') }}: <code>{{ subscription.id }}</code></p>
          </div>
          <div class="billing__badges">
            <AppBadge :variant="subscription.status === 'active' ? 'success' : 'warning'">{{ subscription.status }}</AppBadge>
            <AppBadge :variant="subscription.billing_status === 'past_due' ? 'danger' : 'neutral'">{{ subscription.billing_status }}</AppBadge>
          </div>
        </div>
        <dl class="billing__facts">
          <div><dt>{{ $t('billing.period') }}</dt><dd>{{ formatDateTime(subscription.current_period_start, locale) }} — {{ formatDateTime(subscription.current_period_end, locale) }}</dd></div>
          <div><dt>{{ $t('billing.autoRenew') }}</dt><dd>{{ subscription.auto_renew_enabled ? $t('common.enabled') : $t('common.disabled') }}</dd></div>
          <div v-if="subscription.grace_until"><dt>{{ $t('billing.graceUntil') }}</dt><dd>{{ formatDateTime(subscription.grace_until, locale) }}</dd></div>
        </dl>
        <div class="billing__items">
          <div v-for="item in subscription.items" :key="item.id" class="billing__item">
            <div>
              <strong>{{ item.plan.name }}</strong>
              <span class="billing__muted">{{ item.price.billing_period }} · ×{{ item.quantity }}</span>
            </div>
            <span>{{ formatMoney(item.price.amount_minor * item.quantity, item.price.currency, locale) }}</span>
          </div>
        </div>
        <div v-if="subscription.renewal" class="billing__renewal">
          <p class="billing__muted">{{ $t('billing.renewal') }}: {{ subscription.renewal.invoice_status }} · {{ subscription.renewal.payment_status ?? '—' }}</p>
          <div class="billing__actions">
            <AppButton size="s" variant="secondary" @click="viewInvoice(subscription.renewal.invoice_id)">{{ $t('billing.viewInvoice') }}</AppButton>
            <AppButton v-if="subscription.renewal.payment_id" size="s" variant="secondary" @click="viewPayment(subscription.renewal.payment_id)">{{ $t('billing.viewPayment') }}</AppButton>
            <AppButton
              v-if="subscription.renewal.payment_id && subscription.renewal.payment_status !== 'succeeded' && subscription.renewal.payment_status !== 'cancelled' && canManage"
              size="s"
              :loading="actionBusy"
              @click="continuePayment(subscription.renewal.payment_id)"
            >
              {{ $t('billing.continuePayment') }}
            </AppButton>
            <AppButton
              v-if="subscription.renewal.invoice_status === 'issued' && subscription.renewal.payment_id === null && canManage"
              size="s"
              :loading="actionBusy"
              @click="payInvoiceById(subscription.renewal.invoice_id)"
            >
              {{ $t('billing.payRenewal') }}
            </AppButton>
          </div>
        </div>
        <div class="billing__actions">
          <AppButton v-if="subscription.auto_renew_enabled && canManage" size="s" variant="secondary" :loading="actionBusy" @click="askConfirm($t('billing.cancelAutoRenewTitle'), $t('billing.cancelAutoRenewText'), $t('billing.cancelAutoRenew'), cancelAutoRenew)">{{ $t('billing.cancelAutoRenew') }}</AppButton>
          <AppButton v-if="subscription.status !== 'cancelled' && subscription.status !== 'expired' && canManage" size="s" variant="danger" :loading="actionBusy" @click="askConfirm($t('billing.cancelSubscriptionTitle'), $t('billing.cancelSubscriptionText'), $t('billing.cancelSubscription'), cancelSubscription)">{{ $t('billing.cancelSubscription') }}</AppButton>
        </div>
      </AppCard>

      <AppCard v-if="planChangeRequested && planChange">
        <div class="billing__card-head">
          <div>
            <h3>{{ $t('billing.planChange') }}</h3>
            <p class="billing__muted">{{ planChange.change_kind }} · {{ planChange.status }} · {{ formatMoney(planChange.amount_due_minor, planChange.currency, locale) }}</p>
          </div>
          <AppBadge :variant="planChange.status === 'payment_required' ? 'warning' : 'neutral'">{{ planChange.status }}</AppBadge>
        </div>
        <p class="billing__muted">{{ formatDateTime(planChange.period_start, locale) }} — {{ formatDateTime(planChange.period_end, locale) }}</p>
        <div class="billing__actions">
          <AppButton v-if="canPayPlanChange" size="s" :loading="actionBusy" @click="payPlanChange">{{ $t('billing.payPlanChange') }}</AppButton>
          <AppButton size="s" variant="secondary" :loading="actionBusy" @click="cancelPlanChange">{{ $t('billing.cancelPlanChange') }}</AppButton>
        </div>
      </AppCard>

      <AppCard>
        <div class="billing__card-head">
          <div>
            <h3>{{ subscription ? $t('billing.changePlan') : $t('billing.startSubscription') }}</h3>
            <p class="billing__muted">{{ readyForSubscription ? $t('billing.planHint') : $t('billing.catalogPending') }}</p>
          </div>
        </div>
        <div class="billing__form-grid">
          <AppDropdown id="billing-plan" :model-value="subscription ? changeTargetPriceId : selectedPriceId" :label="$t('billing.plan')" :options="priceOptions" :disabled="!canManage" @update:model-value="onPlanChange" />
          <AppInput id="billing-quantity" :model-value="subscription ? changeQuantity : quantity" :label="$t('billing.quantity')" type="number" min="1" :disabled="!canManage" @update:model-value="onQuantityChange" />
        </div>
        <div v-if="!subscription" class="billing__form-grid">
          <div class="billing__choice">
            <span class="billing__choice-label">{{ $t('billing.paymentMethod') }}</span>
            <label><input v-model="checkoutMethod" type="radio" value="bank_card" :disabled="!canManage" /> {{ $t('billing.bankCard') }}</label>
            <label><input v-model="checkoutMethod" type="radio" value="sbp" :disabled="!canManage" /> {{ $t('billing.sbp') }}</label>
          </div>
          <AppSwitch id="billing-save-method" v-model="savePaymentMethod" :label="$t('billing.saveMethod')" :disabled="!canManage" />
        </div>
        <div class="billing__actions">
          <AppButton v-if="subscription" :disabled="!canManage" :loading="actionBusy" @click="requestPlanChange">{{ $t('billing.requestPlanChange') }}</AppButton>
          <AppButton v-else :disabled="!canManage || !readyForSubscription" :loading="actionBusy" @click="startSubscription">{{ $t('billing.startCheckout') }}</AppButton>
          <AppButton v-if="checkoutResult?.confirmation" variant="secondary" @click="openCheckout">{{ $t('billing.openCheckout') }}</AppButton>
        </div>
        <p class="billing__muted">{{ $t('billing.enableMfaHint') }} <RouterLink :to="{ name: 'security' }">{{ $t('security.goToSecurity') }}</RouterLink></p>
        <p v-if="lastInvoice || lastPayment" class="billing__muted">
          {{ $t('billing.lastInvoice') }}: {{ lastInvoice?.id ?? '—' }} · {{ $t('billing.lastPayment') }}: {{ lastPayment?.id ?? '—' }}
        </p>
      </AppCard>

      <AppCard>
        <h3>{{ $t('billing.profileTitle') }}</h3>
        <p class="billing__muted">{{ $t('billing.profileHint') }}</p>
        <div class="billing__form-grid">
          <AppInput id="billing-legal-name" v-model="profile.legal_name" :label="$t('billing.legalName')" :disabled="!canManage" />
          <AppInput id="billing-country" v-model="profile.country_code" :label="$t('billing.country')" maxlength="2" :disabled="!canManage" />
          <AppInput id="billing-contact-name" v-model="profile.contact_name" :label="$t('billing.contactName')" :disabled="!canManage" />
          <AppInput id="billing-contact-email" v-model="profile.contact_email" :label="$t('billing.contactEmail')" type="email" :disabled="!canManage" />
          <AppInput id="billing-tax-id" v-model="profile.tax_identifier" :label="$t('billing.taxId')" :disabled="!canManage" />
          <AppInput id="billing-city" v-model="profile.city" :label="$t('billing.city')" :disabled="!canManage" />
          <AppInput id="billing-line1" v-model="profile.line1" :label="$t('billing.address')" :disabled="!canManage" />
          <AppInput id="billing-postal" v-model="profile.postal_code" :label="$t('billing.postalCode')" :disabled="!canManage" />
        </div>
        <p v-if="profileError" class="billing__error" role="alert">{{ profileError }}</p>
        <div class="billing__actions">
          <AppButton :disabled="!canManage" :loading="actionBusy" @click="saveProfile">{{ $t('billing.saveProfile') }}</AppButton>
          <AppButton v-if="canReviewCatalog && detail?.onboarding_state === 'catalog_selection_pending'" variant="secondary" :loading="actionBusy" @click="reviewCatalog">{{ $t('billing.confirmCatalog') }}</AppButton>
        </div>
      </AppCard>

      <AppCard>
        <div class="billing__card-head">
          <h3>{{ $t('billing.paymentMethods') }}</h3>
        </div>
        <AppEmptyState v-if="methods.length === 0" :title="$t('billing.noPaymentMethods')" :description="$t('billing.noPaymentMethodsText')" />
        <div v-else class="billing__methods">
          <div v-for="method in methods" :key="method.id" class="billing__method">
            <div>
              <strong>{{ method.title }}</strong>
              <p class="billing__muted">{{ method.card_brand ?? method.method_type }} · {{ method.last4 ? `•••• ${method.last4}` : '' }} <AppBadge v-if="method.is_default" variant="success">{{ $t('billing.default') }}</AppBadge></p>
            </div>
            <div class="billing__actions">
              <AppButton v-if="!method.is_default && method.status === 'active' && canManage" size="s" variant="secondary" :loading="actionBusy" @click="setDefaultMethod(method)">{{ $t('billing.makeDefault') }}</AppButton>
              <AppButton v-if="method.status === 'active' && canManage" size="s" variant="danger" :loading="actionBusy" @click="disableMethod(method)">{{ $t('billing.disableMethod') }}</AppButton>
            </div>
          </div>
        </div>
      </AppCard>

      <AppCard v-if="invoiceDoc || paymentDoc || receiptDoc || refundsDoc">
        <div class="billing__card-head">
          <h3>{{ $t('billing.documents') }}</h3>
          <AppButton v-if="documentLoading" size="s" variant="secondary" loading>{{ $t('common.loading') }}</AppButton>
        </div>
        <p v-if="documentError" class="billing__error" role="alert">{{ documentError }}</p>
        <section v-if="invoiceDoc" class="billing__document">
          <h4>{{ $t('billing.invoice') }} {{ invoiceDoc.id }}</h4>
          <p class="billing__muted">{{ invoiceDoc.status }} · {{ formatMoney(invoiceDoc.total_minor, invoiceDoc.currency, locale) }}</p>
          <div v-for="line in invoiceDoc.lines" :key="line.id" class="billing__line">
            <span>{{ line.description }} ×{{ line.quantity }}</span>
            <span>{{ formatMoney(line.total_minor, invoiceDoc.currency, locale) }}</span>
          </div>
        </section>
        <section v-if="paymentDoc" class="billing__document">
          <h4>{{ $t('billing.payment') }} {{ paymentDoc.id }}</h4>
          <p class="billing__muted">{{ paymentDoc.status }} · {{ formatMoney(paymentDoc.amount_minor, paymentDoc.currency, locale) }} · {{ $t('billing.refundable') }} {{ formatMoney(paymentDoc.refundable_minor, paymentDoc.currency, locale) }}</p>
          <div class="billing__actions">
            <AppButton size="s" variant="secondary" @click="viewReceipt(paymentDoc.id)">{{ $t('billing.receipt') }}</AppButton>
            <AppButton size="s" variant="secondary" @click="viewRefunds(paymentDoc.id)">{{ $t('billing.refunds') }}</AppButton>
            <AppButton v-if="paymentDoc.status === 'pending' || paymentDoc.status === 'waiting_for_capture'" size="s" :loading="actionBusy" @click="continuePayment(paymentDoc.id)">{{ $t('billing.continuePayment') }}</AppButton>
            <AppButton v-if="paymentDoc.refundable_minor > 0 && canManage" size="s" variant="danger" :loading="actionBusy" @click="refundPayment">{{ $t('billing.refundPayment') }}</AppButton>
          </div>
        </section>
        <section v-if="receiptDoc" class="billing__document">
          <h4>{{ $t('billing.receipt') }} {{ receiptDoc.id }}</h4>
          <p class="billing__muted">{{ receiptDoc.status }} · {{ formatMoney(receiptDoc.amount_minor, receiptDoc.currency, locale) }}</p>
          <div v-for="item in receiptDoc.items" :key="`${item.description}-${item.total_minor}`" class="billing__line"><span>{{ item.description }}</span><span>{{ formatMoney(item.total_minor, item.currency, locale) }}</span></div>
        </section>
        <section v-if="refundsDoc" class="billing__document">
          <h4>{{ $t('billing.refunds') }}</h4>
          <p v-if="refundsDoc.items.length === 0" class="billing__muted">{{ $t('billing.noRefunds') }}</p>
          <div v-for="refund in refundsDoc.items" :key="refund.id" class="billing__line"><span>{{ refund.status }} · {{ refund.id }}</span><span>{{ formatMoney(refund.amount_minor, refund.currency, locale) }}</span></div>
        </section>
      </AppCard>
    </template>

    <AppConfirmDialog v-model="confirmOpen" :title="confirmTitle" :description="confirmDescription" :confirm-label="confirmLabel" :busy="actionBusy" @confirm="confirm" />
    <ReauthenticateDialog v-model="reauthOpen" @authenticated="onReauthenticated" @cancelled="actionBusy = false" />
  </section>
</template>

<style scoped>
.billing { display: grid; gap: var(--space-16); }
.billing__header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-12); }
.billing__title { font-size: var(--type-h2-size); line-height: var(--type-h2-line); font-weight: var(--type-h2-weight); color: var(--text-1); }
.billing__subtitle, .billing__muted { margin-top: var(--space-4); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); color: var(--text-2); transition: var(--theme-transition); }
.billing__card-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: var(--space-12); }
.billing__card-head h3, .billing h3, .billing h4 { font-size: var(--type-h3-size); line-height: var(--type-h3-line); font-weight: var(--type-h3-weight); color: var(--text-1); }
.billing__badges { display: flex; flex-wrap: wrap; gap: var(--space-4); }
.billing__facts { display: grid; gap: var(--space-8); margin-top: var(--space-16); }
.billing__facts div, .billing__line { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--space-12); padding-bottom: var(--space-8); border-bottom: 1px solid var(--border); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); }
.billing__facts dt { color: var(--text-2); }
.billing__facts dd { color: var(--text-1); text-align: end; }
.billing__items { display: grid; gap: var(--space-8); margin-top: var(--space-12); }
.billing__item { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-12); padding: var(--space-12); border-radius: var(--radius-control-m); background: var(--surface-2); }
.billing__item div { display: grid; gap: var(--space-4); }
.billing__renewal { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-12); margin-top: var(--space-16); padding-top: var(--space-12); border-top: 1px solid var(--border); }
.billing__actions { display: flex; flex-wrap: wrap; gap: var(--space-8); margin-top: var(--space-12); }
.billing__form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: var(--space-12); margin-top: var(--space-12); }
.billing__choice { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-12); padding: var(--space-8) 0; }
.billing__choice-label { width: 100%; font-size: var(--type-body-small-emphasis-size); font-weight: var(--type-body-small-emphasis-weight); color: var(--text-2); }
.billing__choice label { display: inline-flex; align-items: center; gap: var(--space-6); font-size: var(--type-body-medium-size); color: var(--text-1); }
.billing__error { padding: var(--space-12); border: 1px solid var(--danger); border-radius: var(--radius-control-m); background: var(--danger-soft); color: var(--danger-text); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); }
.billing__methods { display: grid; gap: var(--space-8); margin-top: var(--space-12); }
.billing__method { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-12); padding: var(--space-12); border: 1px solid var(--border); border-radius: var(--radius-control-m); }
.billing__method .billing__muted { display: flex; align-items: center; gap: var(--space-6); margin-top: 0; }
.billing__document { margin-top: var(--space-16); display: grid; gap: var(--space-8); }
.billing__document h4 { margin-bottom: var(--space-4); }
</style>
