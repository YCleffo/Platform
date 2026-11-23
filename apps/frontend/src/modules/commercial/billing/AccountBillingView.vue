<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { commercialAccount, commercialBilling, commercialCatalog } from '@/core/api';
import { useAccountStore } from '@/core/account/accountStore';
import { RequestAbortedError, SupersededOperationError } from '@/shared/api/errors';
import { sessionEpoch } from '@/core/auth/session';
import { useBillingApi } from './useBillingApi';
import { remainingRefundLines, refundSelection } from './refundItems';
import type { BillingHistoryItem, BillingState } from '@/shared/api/commercial/billingState';
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
  Refund,
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
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';

const route = useRoute();
const store = useAccountStore();
const toast = useToast();
const { t, te, locale } = useI18n();

const accountId = computed(() => String(route.params.accountId ?? ''));
const billingApi = useBillingApi(commercialBilling, () => accountId.value);
const billingState = ref<BillingState | null>(null);
const historyQuery = ref('');
const historyBusy = ref(false);
const historyError = ref<string | null>(null);
let loadGeneration = 0;
let actionGeneration = 0;
let historyGeneration = 0;
let documentGeneration = 0;
let live = true;
onBeforeUnmount(() => {
  live = false;
  loadGeneration += 1;
  actionGeneration += 1;
  historyGeneration += 1;
  documentGeneration += 1;
});
const detail = computed(() => store.accountDetail);
const canManage = computed(() => detail.value?.permissions.manage_billing === true);
const readyForSubscription = computed(
  () =>
    detail.value?.onboarding_state === 'ready_for_subscription' ||
    (detail.value?.onboarding_state === 'completed' && detail.value?.status === 'active'),
);
const canReviewCatalog = computed(
  () => detail.value?.role === 'owner' || detail.value?.role === 'admin',
);

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
const profileErrorKey = ref<string | null>(null);
const profileError = computed(() => (profileErrorKey.value ? t(profileErrorKey.value) : null));
const profileKey = ref<string | null>(null);

const invoiceDoc = ref<Invoice | null>(null);
const paymentDoc = ref<Payment | null>(null);
const receiptDoc = ref<Receipt | null>(null);
const refundsDoc = ref<RefundList | null>(null);
const refundDoc = ref<Refund | null>(null);
const planChangeDoc = ref<PlanChange | null>(null);
const documentError = ref<string | null>(null);
const documentLoading = ref(false);
const refundOpen = ref(false);
const refundLines = ref<ReturnType<typeof remainingRefundLines>>([]);
const refundQuantities = reactive<Record<string, number>>({});
const refundError = ref<string | null>(null);
const refundTotal = computed(() =>
  refundLines.value.reduce(
    (sum, line) => sum + Number(refundQuantities[line.id] ?? 0) * line.unit_amount_minor,
    0,
  ),
);

const confirmOpen = ref(false);
const confirmTitle = ref('');
const confirmDescription = ref('');
const confirmLabel = ref('');
const confirmAction = ref<null | (() => Promise<void>)>(null);
const confirmSuccess = ref<string | undefined>();

const {
  reauthOpen,
  runSensitive,
  onReauthenticated: retryPending,
  cancelPending,
} = useSensitiveAction((message) => {
  actionError.value = message;
});

function periodLabel(period: string): string {
  const key = `catalog.period_${period}`;
  return te(key) ? t(key) : period;
}

const priceOptions = computed(() =>
  catalogPlans.value.flatMap((plan) =>
    plan.prices.map((price) => ({
      value: price.id,
      label: `${plan.name} · ${periodLabel(price.billing_period)} · ${formatMoney(price.amount_minor, price.currency, locale.value)}`,
    })),
  ),
);

const selectedPrice = computed<PlanPrice | null>(() => {
  for (const plan of catalogPlans.value) {
    const price = plan.prices.find((item) => item.id === selectedPriceId.value);
    if (price !== undefined) {
      return price;
    }
  }
  return null;
});

const planChangeRequested = computed(
  () => planChange.value !== null && planChange.value.status !== 'cancelled',
);
const canPayPlanChange = computed(
  () =>
    canManage.value &&
    planChange.value?.status === 'payment_required' &&
    planChange.value.invoice_id !== null,
);

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

watch(
  profile,
  () => {
    profileKey.value = null;
  },
  { deep: true },
);

function resetProfile(): void {
  profileErrorKey.value = null;
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
  const generation = ++loadGeneration;
  const current = () => live && generation === loadGeneration && id === accountId.value;
  if (id === '') {
    return;
  }
  loading.value = true;
  loadError.value = null;
  try {
    const [nextEntitlements, catalog, recovered, methodList] = await Promise.all([
      billingApi.entitlements(id),
      commercialCatalog.listPlans(),
      billingApi.state(id),
      billingApi.paymentMethods(id),
    ]);
    if (!current()) {
      return;
    }
    const [nextSubscription, nextInvoice, nextPayment, nextChange] = await Promise.all([
      recovered.subscription_id ? billingApi.subscription(id, recovered.subscription_id) : null,
      recovered.latest_invoice_id ? billingApi.invoice(id, recovered.latest_invoice_id) : null,
      recovered.latest_payment_id ? billingApi.payment(id, recovered.latest_payment_id) : null,
      recovered.plan_change_id ? billingApi.planChange(id, recovered.plan_change_id) : null,
    ]);
    if (!current()) return;
    billingState.value = recovered;
    subscription.value = nextSubscription;
    lastInvoice.value = nextInvoice;
    lastPayment.value = nextPayment;
    planChange.value = nextChange;
    entitlements.value = nextEntitlements;
    catalogPlans.value = catalog.plans;
    const requestedPlan = typeof route.query.planPrice === 'string' ? route.query.planPrice : '';
    if (
      requestedPlan !== '' &&
      priceOptions.value.some((option) => option.value === requestedPlan)
    ) {
      selectedPriceId.value = requestedPlan;
    } else if (selectedPriceId.value === '') {
      selectedPriceId.value = priceOptions.value[0]?.value ?? '';
    }
    if (changeTargetPriceId.value === '') {
      changeTargetPriceId.value = selectedPriceId.value;
    }
    if (
      subscription.value !== null &&
      !priceOptions.value.some((option) => option.value === changeTargetPriceId.value)
    ) {
      changeTargetPriceId.value = subscription.value.items[0]?.price.id ?? selectedPriceId.value;
    }
    methods.value = methodList.items;
    resetProfile();
    if (recovered.billing_profile) {
      const saved = recovered.billing_profile;
      profile.legal_name = saved.legal_name;
      profile.country_code = saved.country_code;
      profile.contact_name = saved.contact_name ?? '';
      profile.contact_email = saved.contact_email ?? '';
      profile.tax_identifier = saved.tax_identifier ?? '';
      for (const field of ['city', 'line1', 'postal_code'] as const)
        profile[field] = typeof saved.address[field] === 'string' ? saved.address[field] : '';
    }
  } catch (error) {
    if (
      error instanceof RequestAbortedError ||
      error instanceof SupersededOperationError ||
      !current()
    ) {
      return;
    }
    loadError.value = resolveErrorMessage(error);
  } finally {
    if (current()) loading.value = false;
  }
}

async function perform(action: () => Promise<void>, successMessage?: string): Promise<void> {
  if (actionBusy.value || reauthOpen.value) return;
  const id = accountId.value;
  const epoch = sessionEpoch.value;
  const generation = ++actionGeneration;
  const current = () =>
    live &&
    id === accountId.value &&
    epoch === sessionEpoch.value &&
    generation === actionGeneration;
  actionBusy.value = true;
  actionError.value = null;
  try {
    await runSensitive(async () => {
      if (!current()) throw new SupersededOperationError();
      await action();
      if (!current()) throw new SupersededOperationError();
      await load();
      if (current() && successMessage !== undefined) toast.show(successMessage, 'success');
    });
  } finally {
    if (current()) actionBusy.value = false;
  }
}

async function onReauthenticated(): Promise<void> {
  const generation = actionGeneration;
  actionBusy.value = true;
  try {
    await retryPending();
  } finally {
    if (live && generation === actionGeneration) actionBusy.value = false;
  }
}

async function reviewCatalog(): Promise<void> {
  await perform(async () => {
    await commercialAccount.reviewCatalog(accountId.value);
    await store.refreshAccount(accountId.value);
  }, t('billing.catalogReviewed'));
}

async function saveProfile(): Promise<void> {
  if (!canManage.value) {
    return;
  }
  profileErrorKey.value = null;
  if (profile.legal_name.trim() === '' || profile.country_code.trim().length !== 2) {
    profileErrorKey.value = 'billing.profileRequired';
    return;
  }
  await perform(async () => {
    await billingApi.saveBillingProfile(accountId.value, {
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
    await store.refreshAccount(accountId.value);
  }, t('billing.profileSaved'));
}

async function startSubscription(): Promise<void> {
  if (
    !canManage.value ||
    (subscription.value?.status !== 'draft' && selectedPrice.value === null) ||
    quantity.value < 1
  ) {
    return;
  }
  await perform(async () => {
    const current = checkoutFlow.value ?? {
      subscriptionKey: createIdempotencyKey(),
      invoiceKey: createIdempotencyKey(),
      paymentKey: createIdempotencyKey(),
      checkoutKey: createIdempotencyKey(),
      subscriptionId: subscription.value?.status === 'draft' ? subscription.value.id : null,
      invoice: null,
      payment: null,
    };
    checkoutFlow.value = current;

    if (current.subscriptionId === null) {
      const created = await billingApi.createSubscription(
        accountId.value,
        selectedPriceId.value,
        quantity.value,
        current.subscriptionKey,
      );
      current.subscriptionId = created.id;
    }
    if (current.invoice === null) {
      current.invoice = await billingApi.issueInvoice(
        accountId.value,
        current.subscriptionId,
        current.invoiceKey,
      );
      lastInvoice.value = current.invoice;
    }
    if (current.payment === null) {
      current.payment = await billingApi.createPayment(
        accountId.value,
        current.invoice.id,
        current.paymentKey,
      );
      lastPayment.value = current.payment;
    }
    checkoutResult.value =
      current.payment.status === 'succeeded'
        ? null
        : await billingApi.checkout(
            accountId.value,
            current.payment.id,
            checkoutMethod.value,
            savePaymentMethod.value,
            current.checkoutKey,
          );
    subscription.value = await billingApi.subscription(accountId.value, current.subscriptionId);
    entitlements.value = await billingApi.entitlements(accountId.value);
  }, t('billing.checkoutReady'));
}

async function payInvoiceById(invoiceId: string): Promise<void> {
  if (!canManage.value && store.detailLoading) {
    await store.loadAccount(accountId.value);
  }
  if (!canManage.value) {
    return;
  }
  await perform(async () => {
    const current =
      invoiceFlow.value?.invoiceId === invoiceId
        ? invoiceFlow.value
        : {
            invoiceId,
            paymentKey: createIdempotencyKey(),
            checkoutKey: createIdempotencyKey(),
            payment: null,
          };
    invoiceFlow.value = current;
    current.payment ??= await billingApi.createPayment(
      accountId.value,
      invoiceId,
      current.paymentKey,
    );
    checkoutResult.value =
      current.payment.status === 'succeeded'
        ? null
        : await billingApi.checkout(
            accountId.value,
            current.payment.id,
            checkoutMethod.value,
            savePaymentMethod.value,
            current.checkoutKey,
          );
    lastInvoice.value = await billingApi.invoice(accountId.value, invoiceId);
    lastPayment.value = current.payment;
  }, t('billing.checkoutReady'));
}

async function payPlanChange(): Promise<void> {
  if (!canManage.value && store.detailLoading) {
    await store.loadAccount(accountId.value);
  }
  if (!canManage.value) {
    return;
  }
  const paymentId = planChange.value?.payment_id;
  if (paymentId) {
    await continuePayment(paymentId);
    return;
  }
  const invoiceId = planChange.value?.invoice_id;
  if (invoiceId !== null && invoiceId !== undefined) {
    await payInvoiceById(invoiceId);
  }
}

async function continuePayment(paymentId: string): Promise<void> {
  if (!canManage.value && store.detailLoading) {
    await store.loadAccount(accountId.value);
  }
  if (!canManage.value) {
    return;
  }
  await perform(async () => {
    checkoutResult.value = await billingApi.checkout(
      accountId.value,
      paymentId,
      checkoutMethod.value,
      savePaymentMethod.value,
      createIdempotencyKey(),
    );
    lastPayment.value = await billingApi.payment(accountId.value, paymentId);
  }, t('billing.checkoutReady'));
}

function onPlanChange(value: string | number | string[]): void {
  const next = Array.isArray(value) ? (value[0] ?? '') : String(value);
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
    const requested = await billingApi.requestPlanChange(
      accountId.value,
      subscription.value!.id,
      changeTargetPriceId.value,
      changeQuantity.value,
      planChangeKey.value,
    );
    planChange.value = await billingApi.planChange(accountId.value, requested.id);
  }, t('billing.planChangeRequested'));
}

async function cancelPlanChange(): Promise<void> {
  if (planChange.value === null) {
    return;
  }
  await perform(async () => {
    await billingApi.cancelPlanChange(
      accountId.value,
      planChange.value!.id,
      createIdempotencyKey(),
    );
    planChange.value = null;
  }, t('billing.planChangeCancelled'));
}

async function cancelAutoRenew(): Promise<void> {
  if (subscription.value === null) {
    return;
  }
  await billingApi.disableAutoRenewal(
    accountId.value,
    subscription.value!.id,
    createIdempotencyKey(),
  );
  subscription.value = await billingApi.subscription(accountId.value, subscription.value!.id);
}

async function cancelSubscription(): Promise<void> {
  if (subscription.value === null) {
    return;
  }
  await billingApi.cancelSubscription(
    accountId.value,
    subscription.value!.id,
    createIdempotencyKey(),
  );
  subscription.value = await billingApi.subscription(accountId.value, subscription.value!.id);
}

async function setDefaultMethod(method: SavedPaymentMethod): Promise<void> {
  await perform(async () => {
    await billingApi.setDefaultPaymentMethod(accountId.value, method.id, createIdempotencyKey());
    const list = await billingApi.paymentMethods(accountId.value);
    methods.value = list.items;
  }, t('billing.defaultMethodSaved'));
}

async function disableMethod(method: SavedPaymentMethod): Promise<void> {
  askConfirm(
    t('billing.disableMethodTitle'),
    t('billing.disableMethodText'),
    t('billing.disableMethod'),
    async () => {
      await billingApi.disablePaymentMethod(accountId.value, method.id, createIdempotencyKey());
      const list = await billingApi.paymentMethods(accountId.value);
      methods.value = list.items;
    },
  );
}

function askConfirm(
  title: string,
  description: string,
  label: string,
  action: () => Promise<void>,
  successMessage?: string,
): void {
  confirmTitle.value = title;
  confirmDescription.value = description;
  confirmLabel.value = label;
  confirmAction.value = action;
  confirmSuccess.value = successMessage;
  confirmOpen.value = true;
}

async function confirm(): Promise<void> {
  const action = confirmAction.value;
  if (action === null) {
    return;
  }
  confirmOpen.value = false;
  await perform(action, confirmSuccess.value);
}

async function loadDocument(action: (current: () => boolean) => Promise<void>): Promise<void> {
  const generation = ++documentGeneration;
  const epoch = sessionEpoch.value;
  const current = () => live && generation === documentGeneration && epoch === sessionEpoch.value;
  documentLoading.value = true;
  documentError.value = null;
  try {
    await action(current);
  } catch (error) {
    if (current() && !(error instanceof SupersededOperationError))
      documentError.value = resolveErrorMessage(error);
  } finally {
    if (current()) documentLoading.value = false;
  }
}

function viewInvoice(id: string): void {
  void loadDocument(async (current) => {
    const invoice = await billingApi.invoice(accountId.value, id);
    if (!current()) return;
    invoiceDoc.value = invoice;
    paymentDoc.value = null;
    receiptDoc.value = null;
    refundsDoc.value = null;
    refundDoc.value = null;
  });
}

function viewPayment(id: string): void {
  void loadDocument(async (current) => {
    const payment = await billingApi.payment(accountId.value, id);
    const invoice = await billingApi.invoice(accountId.value, payment.invoice_id);
    if (!current()) return;
    paymentDoc.value = payment;
    invoiceDoc.value = invoice;
    receiptDoc.value = null;
    refundsDoc.value = null;
    refundDoc.value = null;
  });
}

function refundPayment(): void {
  const payment = paymentDoc.value;
  const invoice = invoiceDoc.value;
  if (payment === null || invoice === null) {
    return;
  }
  void loadDocument(async (current) => {
    const list = await billingApi.refunds(accountId.value, payment.id);
    const refunds = await Promise.all(
      list.items
        .filter((item) => item.status !== 'canceled')
        .map((item) => billingApi.refund(accountId.value, item.id)),
    );
    if (!current()) return;
    refundLines.value = remainingRefundLines(invoice.lines, refunds);
    for (const key of Object.keys(refundQuantities)) delete refundQuantities[key];
    for (const line of refundLines.value) refundQuantities[line.id] = line.remaining;
    refundError.value = null;
    refundOpen.value = true;
  });
}

function submitRefund(): void {
  const payment = paymentDoc.value;
  if (payment === null || !canManage.value || actionBusy.value) return;
  let items;
  try {
    items = refundSelection(refundLines.value, refundQuantities, payment.refundable_minor);
  } catch {
    refundError.value = t('billing.refundSelectionInvalid');
    return;
  }
  const selected = items;
  refundOpen.value = false;
  askConfirm(
    t('billing.refundTitle'),
    t('billing.refundText'),
    t('billing.refundPayment'),
    async () => {
      await billingApi.createRefund(accountId.value, payment.id, selected, createIdempotencyKey());
      toast.show(t('billing.refundCreated'), 'success');
      viewPayment(payment.id);
    },
  );
}

function viewReceipt(id: string): void {
  void loadDocument(async (current) => {
    const receipt = await billingApi.receipt(accountId.value, id);
    if (current()) receiptDoc.value = receipt;
  });
}

function viewRefunds(id: string): void {
  refundDoc.value = null;
  void loadDocument(async (current) => {
    const refunds = await billingApi.refunds(accountId.value, id);
    if (current()) refundsDoc.value = refunds;
  });
}

function viewRefund(id: string): void {
  refundDoc.value = null;
  void loadDocument(async (current) => {
    const refund = await billingApi.refund(accountId.value, id);
    if (current()) refundDoc.value = refund;
  });
}

function openCheckout(): void {
  const url = checkoutResult.value?.confirmation?.url;
  if (url !== undefined) {
    window.location.assign(url);
  }
}

async function loadHistory(offset = 0): Promise<void> {
  if (!/^[0-9a-f-]{0,36}$/i.test(historyQuery.value.trim())) {
    historyError.value = t('billing.historyQueryInvalid');
    return;
  }
  const version = ++historyGeneration;
  historyBusy.value = true;
  historyError.value = null;
  try {
    const state = await billingApi.state(accountId.value, 25, offset, historyQuery.value.trim());
    if (live && version === historyGeneration && billingState.value)
      billingState.value.history = state.history;
  } catch (error) {
    if (live && version === historyGeneration) historyError.value = resolveErrorMessage(error);
  } finally {
    if (live && version === historyGeneration) historyBusy.value = false;
  }
}

function openHistory(item: BillingHistoryItem): void {
  if (item.kind === 'invoice') viewInvoice(item.id);
  else if (item.kind === 'payment') viewPayment(item.id);
  else
    void loadDocument(async (current) => {
      const change = await billingApi.planChange(accountId.value, item.id);
      if (current()) planChangeDoc.value = change;
    });
}

function historyStatus(status: string): string {
  const key = `billing.status.${status}`;
  const known = [
    'draft',
    'active',
    'issued',
    'paid',
    'void',
    'pending',
    'waiting_for_capture',
    'succeeded',
    'cancelled',
    'applied',
    'scheduled',
    'payment_required',
    'paid_unapplied',
    'refunded',
    'failed',
    'expired',
    'canceled',
    'current',
    'past_due',
  ];
  return t(known.includes(status) ? key : 'billing.processingStatus');
}

function resumeBilling(): void {
  if (billingState.value?.pending_payment_id)
    void continuePayment(billingState.value.pending_payment_id);
  else if (billingState.value?.pending_invoice_id)
    void payInvoiceById(billingState.value.pending_invoice_id);
  else if (subscription.value?.status === 'draft') void startSubscription();
}

onMounted(load);
watch(
  accountId,
  () => {
    loadGeneration += 1;
    actionGeneration += 1;
    historyGeneration += 1;
    documentGeneration += 1;
    billingState.value = null;
    subscription.value = null;
    lastInvoice.value = null;
    lastPayment.value = null;
    planChange.value = null;
    checkoutFlow.value = null;
    invoiceFlow.value = null;
    checkoutResult.value = null;
    invoiceDoc.value = null;
    paymentDoc.value = null;
    receiptDoc.value = null;
    refundsDoc.value = null;
    refundDoc.value = null;
    planChangeDoc.value = null;
    selectedPriceId.value = '';
    changeTargetPriceId.value = '';
    methods.value = [];
    catalogPlans.value = [];
    entitlements.value = null;
    confirmOpen.value = false;
    refundOpen.value = false;
    confirmAction.value = null;
    reauthOpen.value = false;
    cancelPending();
    actionBusy.value = false;
    historyQuery.value = '';
    historyBusy.value = false;
    historyError.value = null;
    actionError.value = null;
    documentLoading.value = false;
    documentError.value = null;
    resetProfile();
    void load();
  },
  { flush: 'sync' },
);
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

    <p
      v-if="actionError"
      class="billing__error"
      role="alert"
    >
      {{ actionError }}
      <RouterLink :to="{ name: 'security' }">{{ $t('security.mfaRequired') }}</RouterLink>
    </p>

    <AppSkeleton
      v-if="loading"
      variant="card"
      :lines="5"
    />
    <AppErrorState
      v-else-if="loadError"
      :message="loadError"
    >
      <AppButton
        variant="secondary"
        @click="load"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <template v-else>
      <AppCard
        v-if="
          billingState?.pending_invoice_id ||
          billingState?.pending_payment_id ||
          subscription?.status === 'draft'
        "
      >
        <h3>{{ $t('billing.unfinishedTitle') }}</h3>
        <p class="billing__muted">{{ $t('billing.unfinishedHint') }}</p>
        <div class="billing__actions">
          <AppButton
            :disabled="!canManage || actionBusy"
            :loading="actionBusy"
            @click="resumeBilling"
          >
            {{ $t('billing.resumeBilling') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="actionBusy"
            @click="load"
          >
            {{ $t('billing.recheckStatus') }}
          </AppButton>
          <AppButton
            v-if="checkoutResult?.confirmation"
            variant="secondary"
            @click="openCheckout"
          >
            {{ $t('billing.openCheckout') }}
          </AppButton>
        </div>
      </AppCard>
      <AppCard
        v-if="!subscription && !billingState?.pending_invoice_id && billingState?.latest_invoice_id"
        role="status"
      >
        <h3>{{ $t('billing.inactiveTitle') }}</h3>
        <p class="billing__muted">{{ $t('billing.inactiveHint') }}</p>
      </AppCard>
      <AppCard v-if="subscription">
        <div class="billing__card-head">
          <div>
            <h3>{{ $t('billing.currentPlan') }}</h3>
            <p class="billing__muted">
              {{ $t('billing.subscriptionId') }}:
              <code>{{ subscription.id }}</code>
            </p>
          </div>
          <div class="billing__badges">
            <AppBadge :variant="subscription.status === 'active' ? 'success' : 'warning'">
              {{ historyStatus(subscription.status) }}
            </AppBadge>
            <AppBadge :variant="subscription.billing_status === 'past_due' ? 'danger' : 'neutral'">
              {{ historyStatus(subscription.billing_status) }}
            </AppBadge>
          </div>
        </div>
        <dl class="billing__facts">
          <div>
            <dt>{{ $t('billing.period') }}</dt>
            <dd>
              {{ formatDateTime(subscription.current_period_start, locale) }} —
              {{ formatDateTime(subscription.current_period_end, locale) }}
            </dd>
          </div>
          <div>
            <dt>{{ $t('billing.autoRenew') }}</dt>
            <dd>
              {{ subscription.auto_renew_enabled ? $t('common.enabled') : $t('common.disabled') }}
            </dd>
          </div>
          <div v-if="subscription.grace_until">
            <dt>{{ $t('billing.graceUntil') }}</dt>
            <dd>{{ formatDateTime(subscription.grace_until, locale) }}</dd>
          </div>
        </dl>
        <div class="billing__items">
          <div
            v-for="item in subscription.items"
            :key="item.id"
            class="billing__item"
          >
            <div>
              <strong>{{ item.plan.name }}</strong>
              <span class="billing__muted">
                {{ periodLabel(item.price.billing_period) }} · ×{{ item.quantity }}
              </span>
            </div>
            <span>
              {{
                formatMoney(item.price.amount_minor * item.quantity, item.price.currency, locale)
              }}
            </span>
          </div>
        </div>
        <div
          v-if="subscription.renewal"
          class="billing__renewal"
        >
          <p class="billing__muted">
            {{ $t('billing.renewal') }}: {{ subscription.renewal.invoice_status }} ·
            {{ subscription.renewal.payment_status ?? '—' }}
          </p>
          <div class="billing__actions">
            <AppButton
              size="s"
              variant="secondary"
              @click="viewInvoice(subscription.renewal.invoice_id)"
            >
              {{ $t('billing.viewInvoice') }}
            </AppButton>
            <AppButton
              v-if="subscription.renewal.payment_id"
              size="s"
              variant="secondary"
              @click="viewPayment(subscription.renewal.payment_id)"
            >
              {{ $t('billing.viewPayment') }}
            </AppButton>
            <AppButton
              v-if="
                subscription.renewal.payment_id &&
                subscription.renewal.payment_status !== 'succeeded' &&
                subscription.renewal.payment_status !== 'cancelled' &&
                canManage
              "
              size="s"
              :loading="actionBusy"
              @click="continuePayment(subscription.renewal.payment_id)"
            >
              {{ $t('billing.continuePayment') }}
            </AppButton>
            <AppButton
              v-if="
                subscription.renewal.invoice_status === 'issued' &&
                subscription.renewal.payment_id === null &&
                canManage
              "
              size="s"
              :loading="actionBusy"
              @click="payInvoiceById(subscription.renewal.invoice_id)"
            >
              {{ $t('billing.payRenewal') }}
            </AppButton>
          </div>
        </div>
        <div class="billing__actions">
          <AppButton
            v-if="subscription.auto_renew_enabled && canManage"
            size="s"
            variant="secondary"
            :loading="actionBusy"
            @click="
              askConfirm(
                $t('billing.cancelAutoRenewTitle'),
                $t('billing.cancelAutoRenewText'),
                $t('billing.cancelAutoRenew'),
                cancelAutoRenew,
                $t('billing.autoRenewCancelled'),
              )
            "
          >
            {{ $t('billing.cancelAutoRenew') }}
          </AppButton>
          <AppButton
            v-if="
              subscription.status !== 'cancelled' && subscription.status !== 'expired' && canManage
            "
            size="s"
            variant="danger"
            :loading="actionBusy"
            @click="
              askConfirm(
                $t('billing.cancelSubscriptionTitle'),
                $t('billing.cancelSubscriptionText'),
                $t('billing.cancelSubscription'),
                cancelSubscription,
                $t('billing.subscriptionCancelled'),
              )
            "
          >
            {{ $t('billing.cancelSubscription') }}
          </AppButton>
        </div>
      </AppCard>

      <AppCard v-if="planChangeRequested && planChange">
        <div class="billing__card-head">
          <div>
            <h3>{{ $t('billing.planChange') }}</h3>
            <p class="billing__muted">
              {{ $t(`billing.changeKind.${planChange.change_kind}`) }} ·
              {{ historyStatus(planChange.status) }} ·
              {{ formatMoney(planChange.amount_due_minor, planChange.currency, locale) }}
            </p>
          </div>
          <AppBadge :variant="planChange.status === 'payment_required' ? 'warning' : 'neutral'">
            {{ historyStatus(planChange.status) }}
          </AppBadge>
        </div>
        <p class="billing__muted">
          {{ formatDateTime(planChange.period_start, locale) }} —
          {{ formatDateTime(planChange.period_end, locale) }}
        </p>
        <div class="billing__actions">
          <AppButton
            v-if="canPayPlanChange"
            size="s"
            :disabled="!canManage || actionBusy"
            :loading="actionBusy"
            @click="payPlanChange"
          >
            {{ $t('billing.payPlanChange') }}
          </AppButton>
          <AppButton
            size="s"
            variant="secondary"
            :disabled="!canManage || actionBusy"
            :loading="actionBusy"
            @click="cancelPlanChange"
          >
            {{ $t('billing.cancelPlanChange') }}
          </AppButton>
        </div>
      </AppCard>

      <AppCard v-if="subscription?.status !== 'draft'">
        <div class="billing__card-head">
          <div>
            <h3>{{ subscription ? $t('billing.changePlan') : $t('billing.startSubscription') }}</h3>
            <p class="billing__muted">
              {{ readyForSubscription ? $t('billing.planHint') : $t('billing.catalogPending') }}
            </p>
          </div>
        </div>
        <div class="billing__plan-grid">
          <AppDropdown
            id="billing-plan"
            :model-value="subscription ? changeTargetPriceId : selectedPriceId"
            :label="$t('billing.plan')"
            :options="priceOptions"
            show-label
            :disabled="!canManage || actionBusy"
            @update:model-value="onPlanChange"
          />
          <AppInput
            id="billing-quantity"
            class="billing__quantity-input"
            :model-value="subscription ? changeQuantity : quantity"
            :label="$t('billing.quantity')"
            type="number"
            min="1"
            size="m"
            :disabled="!canManage || actionBusy"
            @update:model-value="onQuantityChange"
          />
        </div>
        <div
          v-if="!subscription"
          class="billing__form-grid"
        >
          <div class="billing__choice">
            <span class="billing__choice-label">{{ $t('billing.paymentMethod') }}</span>
            <label>
              <input
                v-model="checkoutMethod"
                type="radio"
                value="bank_card"
                :disabled="!canManage || actionBusy"
              />
              {{ $t('billing.bankCard') }}
            </label>
            <label>
              <input
                v-model="checkoutMethod"
                type="radio"
                value="sbp"
                :disabled="!canManage || actionBusy"
              />
              {{ $t('billing.sbp') }}
            </label>
          </div>
          <AppSwitch
            id="billing-save-method"
            v-model="savePaymentMethod"
            :label="$t('billing.saveMethod')"
            :disabled="!canManage || actionBusy"
          />
        </div>
        <div class="billing__actions">
          <AppButton
            v-if="subscription"
            :disabled="!canManage || actionBusy"
            :loading="actionBusy"
            @click="requestPlanChange"
          >
            {{ $t('billing.requestPlanChange') }}
          </AppButton>
          <AppButton
            v-else
            :disabled="!canManage || !readyForSubscription"
            :loading="actionBusy"
            @click="startSubscription"
          >
            {{ $t('billing.startCheckout') }}
          </AppButton>
          <AppButton
            v-if="checkoutResult?.confirmation"
            variant="secondary"
            @click="openCheckout"
          >
            {{ $t('billing.openCheckout') }}
          </AppButton>
        </div>
        <p class="billing__muted">
          {{ $t('billing.enableMfaHint') }}
          <RouterLink :to="{ name: 'security' }">{{ $t('security.goToSecurity') }}</RouterLink>
        </p>
        <p
          v-if="lastInvoice || lastPayment"
          class="billing__muted"
        >
          {{ $t('billing.lastInvoice') }}: {{ lastInvoice?.id ?? '—' }} ·
          {{ $t('billing.lastPayment') }}: {{ lastPayment?.id ?? '—' }}
        </p>
      </AppCard>

      <AppCard>
        <h3>{{ $t('billing.historyTitle') }}</h3>
        <form
          class="billing__search-form"
          @submit.prevent="loadHistory(0)"
        >
          <AppInput
            id="billing-history-query"
            class="billing__search-input"
            v-model="historyQuery"
            :label="$t('billing.historySearch')"
            maxlength="36"
            size="m"
          />
          <AppButton
            type="submit"
            variant="secondary"
            :loading="historyBusy"
          >
            {{ $t('billing.historyFind') }}
          </AppButton>
        </form>
        <p
          v-if="historyError"
          class="billing__error"
          role="alert"
        >
          {{ historyError }}
        </p>
        <p
          v-else-if="!billingState?.history.items.length"
          class="billing__muted"
        >
          {{ $t('billing.historyEmpty') }}
        </p>
        <ul
          v-else
          class="billing__history"
        >
          <li
            v-for="item in billingState.history.items"
            :key="`${item.kind}:${item.id}`"
          >
            <div>
              <strong>
                {{ $t(`billing.documentKind.${item.kind}`) }} №{{ item.id.slice(-8).toUpperCase() }}
              </strong>
              <p class="billing__muted">
                {{ formatDateTime(item.created_at, locale) }} ·
                {{ formatMoney(item.amount_minor, item.currency, locale) }} ·
                {{ historyStatus(item.status) }}
              </p>
            </div>
            <AppButton
              variant="secondary"
              size="s"
              @click="openHistory(item)"
            >
              {{ $t('admin.details') }}
            </AppButton>
          </li>
        </ul>
        <div
          v-if="billingState"
          class="billing__actions"
        >
          <AppButton
            variant="secondary"
            size="s"
            :disabled="historyBusy || billingState.history.offset === 0"
            @click="loadHistory(Math.max(0, billingState.history.offset - 25))"
          >
            {{ $t('common.previous') }}
          </AppButton>
          <span>
            {{ billingState.history.offset + Math.min(1, billingState.history.items.length) }}–{{
              billingState.history.offset + billingState.history.items.length
            }}
            / {{ billingState.history.total }}
          </span>
          <AppButton
            variant="secondary"
            size="s"
            :disabled="
              historyBusy || billingState.history.offset + 25 >= billingState.history.total
            "
            @click="loadHistory(billingState.history.offset + 25)"
          >
            {{ $t('common.next') }}
          </AppButton>
        </div>
      </AppCard>

      <AppCard>
        <h3>{{ $t('billing.profileTitle') }}</h3>
        <p class="billing__muted">{{ $t('billing.profileHint') }}</p>
        <div class="billing__form-grid">
          <AppInput
            id="billing-legal-name"
            v-model="profile.legal_name"
            :label="$t('billing.legalName')"
            :disabled="!canManage || actionBusy"
          />
          <AppInput
            id="billing-country"
            v-model="profile.country_code"
            :label="$t('billing.country')"
            maxlength="2"
            :disabled="!canManage || actionBusy"
          />
          <AppInput
            id="billing-contact-name"
            v-model="profile.contact_name"
            :label="$t('billing.contactName')"
            :disabled="!canManage || actionBusy"
          />
          <AppInput
            id="billing-contact-email"
            v-model="profile.contact_email"
            :label="$t('billing.contactEmail')"
            type="email"
            :disabled="!canManage || actionBusy"
          />
          <AppInput
            id="billing-tax-id"
            v-model="profile.tax_identifier"
            :label="$t('billing.taxId')"
            :disabled="!canManage || actionBusy"
          />
          <AppInput
            id="billing-city"
            v-model="profile.city"
            :label="$t('billing.city')"
            :disabled="!canManage || actionBusy"
          />
          <AppInput
            id="billing-line1"
            v-model="profile.line1"
            :label="$t('billing.address')"
            :disabled="!canManage || actionBusy"
          />
          <AppInput
            id="billing-postal"
            v-model="profile.postal_code"
            :label="$t('billing.postalCode')"
            :disabled="!canManage || actionBusy"
          />
        </div>
        <p
          v-if="profileError"
          class="billing__error"
          role="alert"
        >
          {{ profileError }}
        </p>
        <div class="billing__actions">
          <AppButton
            :disabled="!canManage || actionBusy"
            :loading="actionBusy"
            @click="saveProfile"
          >
            {{ $t('billing.saveProfile') }}
          </AppButton>
          <AppButton
            v-if="canReviewCatalog && detail?.onboarding_state === 'catalog_selection_pending'"
            variant="secondary"
            :loading="actionBusy"
            @click="reviewCatalog"
          >
            {{ $t('billing.confirmCatalog') }}
          </AppButton>
        </div>
      </AppCard>

      <AppCard>
        <div class="billing__card-head">
          <h3>{{ $t('billing.paymentMethods') }}</h3>
        </div>
        <AppEmptyState
          v-if="methods.length === 0"
          :title="$t('billing.noPaymentMethods')"
          :description="$t('billing.noPaymentMethodsText')"
        />
        <div
          v-else
          class="billing__methods"
        >
          <div
            v-for="method in methods"
            :key="method.id"
            class="billing__method"
          >
            <div>
              <strong>
                {{
                  method.title?.trim() ||
                  $t(
                    method.method_type === 'bank_card'
                      ? 'billing.bankCard'
                      : 'billing.paymentMethod',
                  )
                }}
              </strong>
              <p class="billing__muted">
                {{ method.card_brand ?? method.method_type }} ·
                {{ method.last4 ? `•••• ${method.last4}` : '' }}
                <AppBadge
                  v-if="method.is_default"
                  variant="success"
                >
                  {{ $t('billing.default') }}
                </AppBadge>
              </p>
            </div>
            <div class="billing__actions">
              <AppButton
                v-if="!method.is_default && method.status === 'active' && canManage"
                size="s"
                variant="secondary"
                :loading="actionBusy"
                @click="setDefaultMethod(method)"
              >
                {{ $t('billing.makeDefault') }}
              </AppButton>
              <AppButton
                v-if="method.status === 'active' && canManage"
                size="s"
                variant="danger"
                :loading="actionBusy"
                @click="disableMethod(method)"
              >
                {{ $t('billing.disableMethod') }}
              </AppButton>
            </div>
          </div>
        </div>
      </AppCard>

      <AppCard v-if="invoiceDoc || paymentDoc || receiptDoc || refundsDoc || planChangeDoc">
        <div class="billing__card-head">
          <h3>{{ $t('billing.documents') }}</h3>
          <AppButton
            v-if="documentLoading"
            size="s"
            variant="secondary"
            loading
          >
            {{ $t('common.loading') }}
          </AppButton>
        </div>
        <p
          v-if="documentError"
          class="billing__error"
          role="alert"
        >
          {{ documentError }}
        </p>
        <section
          v-if="planChangeDoc"
          class="billing__document"
        >
          <h4>{{ $t('billing.planChange') }} {{ planChangeDoc.id }}</h4>
          <p>
            {{ historyStatus(planChangeDoc.status) }} ·
            {{ formatMoney(planChangeDoc.amount_due_minor, planChangeDoc.currency, locale) }}
          </p>
        </section>
        <section
          v-if="invoiceDoc"
          class="billing__document"
        >
          <h4>{{ $t('billing.invoice') }} {{ invoiceDoc.id }}</h4>
          <p class="billing__muted">
            {{ historyStatus(invoiceDoc.status) }} ·
            {{ formatMoney(invoiceDoc.total_minor, invoiceDoc.currency, locale) }}
          </p>
          <div
            v-for="line in invoiceDoc.lines"
            :key="line.id"
            class="billing__line"
          >
            <span>{{ line.description }} ×{{ line.quantity }}</span>
            <span>{{ formatMoney(line.total_minor, invoiceDoc.currency, locale) }}</span>
          </div>
        </section>
        <section
          v-if="paymentDoc"
          class="billing__document"
        >
          <h4>{{ $t('billing.payment') }} {{ paymentDoc.id }}</h4>
          <p class="billing__muted">
            {{ historyStatus(paymentDoc.status) }} ·
            {{ formatMoney(paymentDoc.amount_minor, paymentDoc.currency, locale) }} ·
            {{ $t('billing.refundable') }}
            {{ formatMoney(paymentDoc.refundable_minor, paymentDoc.currency, locale) }}
          </p>
          <div class="billing__actions">
            <AppButton
              size="s"
              variant="secondary"
              @click="viewReceipt(paymentDoc.id)"
            >
              {{ $t('billing.receipt') }}
            </AppButton>
            <AppButton
              size="s"
              variant="secondary"
              @click="viewRefunds(paymentDoc.id)"
            >
              {{ $t('billing.refunds') }}
            </AppButton>
            <AppButton
              v-if="paymentDoc.status === 'pending' || paymentDoc.status === 'waiting_for_capture'"
              size="s"
              :loading="actionBusy"
              @click="continuePayment(paymentDoc.id)"
            >
              {{ $t('billing.continuePayment') }}
            </AppButton>
            <AppButton
              v-if="paymentDoc.refundable_minor > 0 && canManage"
              size="s"
              variant="danger"
              :loading="actionBusy"
              @click="refundPayment"
            >
              {{ $t('billing.refundPayment') }}
            </AppButton>
          </div>
        </section>
        <section
          v-if="receiptDoc"
          class="billing__document"
        >
          <h4>{{ $t('billing.receipt') }} {{ receiptDoc.id }}</h4>
          <p class="billing__muted">
            {{ historyStatus(receiptDoc.status) }} ·
            {{ formatMoney(receiptDoc.amount_minor, receiptDoc.currency, locale) }}
          </p>
          <div
            v-for="item in receiptDoc.items"
            :key="`${item.description}-${item.total_minor}`"
            class="billing__line"
          >
            <span>{{ item.description }}</span>
            <span>{{ formatMoney(item.total_minor, item.currency, locale) }}</span>
          </div>
        </section>
        <section
          v-if="refundsDoc"
          class="billing__document"
        >
          <h4>{{ $t('billing.refunds') }}</h4>
          <p
            v-if="refundsDoc.items.length === 0"
            class="billing__muted"
          >
            {{ $t('billing.noRefunds') }}
          </p>
          <div
            v-for="refund in refundsDoc.items"
            :key="refund.id"
            class="billing__line"
          >
            <span>{{ historyStatus(refund.status) }} · {{ refund.id }}</span>
            <span>{{ formatMoney(refund.amount_minor, refund.currency, locale) }}</span>
            <AppButton
              size="s"
              variant="secondary"
              :aria-label="$t('billing.viewRefund', { id: refund.id })"
              :aria-expanded="refundDoc?.id === refund.id"
              @click="viewRefund(refund.id)"
            >
              {{ $t('billing.refundDetails') }}
            </AppButton>
          </div>
        </section>
        <section
          v-if="refundDoc"
          class="billing__document"
          aria-labelledby="refund-details-title"
        >
          <h4 id="refund-details-title">{{ $t('billing.refundDetails') }} {{ refundDoc.id }}</h4>
          <p class="billing__muted">
            {{ historyStatus(refundDoc.status) }} ·
            {{ formatMoney(refundDoc.amount_minor, refundDoc.currency, locale) }} ·
            {{ formatDateTime(refundDoc.completed_at ?? refundDoc.created_at, locale) }}
          </p>
          <div
            v-for="item in refundDoc.items"
            :key="item.invoice_line_id"
            class="billing__line"
          >
            <span>{{ item.description }} × {{ item.quantity }}</span>
            <span>{{ formatMoney(item.total_minor, item.currency, locale) }}</span>
          </div>
          <p
            v-if="refundDoc.receipt"
            class="billing__muted"
          >
            {{ $t('billing.receipt') }} {{ refundDoc.receipt.id }} ·
            {{ historyStatus(refundDoc.receipt.status) }}
          </p>
        </section>
      </AppCard>
    </template>

    <AppDialog
      v-model="refundOpen"
      :title="$t('billing.refundTitle')"
      :description="$t('billing.refundSelectionHint')"
    >
      <div class="billing__items">
        <AppInput
          v-for="line in refundLines"
          :id="`refund-${line.id}`"
          :key="line.id"
          v-model="refundQuantities[line.id]"
          :label="line.description"
          type="number"
          min="0"
          :max="line.remaining"
          step="1"
          :disabled="line.remaining === 0 || actionBusy"
          :hint="`${$t('billing.refundAvailable')}: ${line.remaining}`"
        />
        <p>
          {{ $t('billing.refundTotal') }}:
          {{ formatMoney(refundTotal, paymentDoc?.currency ?? 'RUB', locale) }}
        </p>
        <p
          v-if="refundError"
          role="alert"
          class="billing__error"
        >
          {{ refundError }}
        </p>
        <AppButton
          :disabled="actionBusy"
          @click="submitRefund"
        >
          {{ $t('billing.refundPayment') }}
        </AppButton>
      </div>
    </AppDialog>
    <AppConfirmDialog
      v-model="confirmOpen"
      :title="confirmTitle"
      :description="confirmDescription"
      :confirm-label="confirmLabel"
      :busy="actionBusy"
      @confirm="confirm"
    />
    <ReauthenticateDialog
      v-model="reauthOpen"
      @authenticated="onReauthenticated"
      @cancelled="
        cancelPending();
        actionBusy = false;
      "
    />
  </section>
</template>

<style scoped>
.billing {
  display: grid;
  gap: var(--space-16);
}
.billing code {
  overflow-wrap: anywhere;
}
.billing__history {
  display: grid;
  gap: var(--space-12);
  padding: 0;
  margin-top: var(--space-16);
  list-style: none;
}
.billing__history li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
}
.billing__document h4,
.billing__document .billing__line span {
  overflow-wrap: anywhere;
  min-width: 0;
}
.billing__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-12);
}
.billing__title {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}
.billing__subtitle,
.billing__muted {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.billing__card-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-12);
}
.billing__card-head h3,
.billing h3,
.billing h4 {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}
.billing__badges {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
}
.billing__facts {
  display: grid;
  gap: var(--space-8);
  margin-top: var(--space-16);
}
.billing__facts div,
.billing__line {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--space-12);
  padding-bottom: var(--space-8);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
}
.billing__facts dt {
  color: var(--text-2);
}
.billing__facts dd {
  color: var(--text-1);
  text-align: end;
}
.billing__items {
  display: grid;
  gap: var(--space-8);
  margin-top: var(--space-12);
}
.billing__item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-12);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
}
.billing__item div {
  display: grid;
  gap: var(--space-4);
}
.billing__renewal {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  margin-top: var(--space-16);
  padding-top: var(--space-12);
  border-top: 1px solid var(--border);
}
.billing__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  margin-top: var(--space-12);
}
.billing__plan-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 140px;
  gap: var(--space-12);
  align-items: start;
  margin-top: var(--space-12);
}
@media (max-width: 640px) {
  .billing__plan-grid {
    grid-template-columns: 1fr;
  }
}
.billing__quantity-input {
  width: 100%;
}
.billing__search-form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-8);
  margin-top: var(--space-12);
}
.billing__search-input {
  flex: 1 1 280px;
  max-width: 420px;
}
.billing__form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
  gap: var(--space-12);
  margin-top: var(--space-12);
}
.billing__choice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-12);
  padding: var(--space-8) 0;
}
.billing__choice-label {
  width: 100%;
  font-size: var(--type-body-small-emphasis-size);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-2);
}
.billing__choice label {
  display: inline-flex;
  align-items: center;
  gap: var(--space-6);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-1);
}
.billing__error {
  padding: var(--space-12);
  border: 1px solid var(--danger);
  border-radius: var(--radius-control-m);
  background: var(--danger-soft);
  color: var(--danger-text);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
}
.billing__methods {
  display: grid;
  gap: var(--space-8);
  margin-top: var(--space-12);
}
.billing__method {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
}
.billing__method .billing__muted {
  display: flex;
  align-items: center;
  gap: var(--space-6);
  margin-top: 0;
}
.billing__document {
  margin-top: var(--space-16);
  display: grid;
  gap: var(--space-8);
}
.billing__document h4 {
  margin-bottom: var(--space-4);
}
</style>
