<script setup lang="ts">
import { computed, defineComponent, h, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useTheme, type Theme } from '@/app/theme';
import { useAppearance, type Density } from '@/app/appearance';
import { useToast } from '@/shared/composables/useToast';
import AppContextMenu from '@/shared/ui/menus/AppContextMenu.vue';
import type { MenuItem } from '@/shared/ui/menus/types';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import QrCode from '@/shared/ui/media/QrCode.vue';
import {
  ChevronLeft,
  ChevronRight,
  LayoutGrid,
  Moon,
  RotateCw,
  Settings,
  Sun,
} from '@/shared/ui/icons';

const { t } = useI18n();
const router = useRouter();
const toast = useToast();
const { theme, setTheme } = useTheme();
const { appearance, updateAppearance } = useAppearance();

const themes: Theme[] = ['light', 'dark', 'system'];
const densities: Density[] = ['compact', 'comfortable', 'spacious'];

function makeIcon(name: string, render: () => ReturnType<typeof h>[]) {
  return defineComponent({
    name,
    props: { size: { type: [Number, String], default: 18 } },
    setup(props) {
      return () =>
        h(
          'svg',
          {
            width: props.size,
            height: props.size,
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            strokeWidth: '2',
            strokeLinecap: 'round',
            strokeLinejoin: 'round',
            'aria-hidden': 'true',
          },
          render(),
        );
    },
  });
}

const CopyIcon = makeIcon('IconCopy', () => [
  h('rect', { x: 9, y: 9, width: 13, height: 13, rx: 2, ry: 2 }),
  h('path', { d: 'M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1' }),
]);

const CutIcon = makeIcon('IconCut', () => [
  h('circle', { cx: 6, cy: 6, r: 3 }),
  h('circle', { cx: 6, cy: 18, r: 3 }),
  h('line', { x1: 20, y1: 4, x2: 8.12, y2: 15.88 }),
  h('line', { x1: 14.47, y1: 14.48, x2: 20, y2: 20 }),
  h('line', { x1: 8.12, y1: 8.12, x2: 12, y2: 12 }),
]);

const PasteIcon = makeIcon('IconPaste', () => [
  h('path', { d: 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2' }),
  h('rect', { x: 8, y: 2, width: 8, height: 4, rx: 1, ry: 1 }),
]);

const ShareIcon = makeIcon('IconShare', () => [
  h('circle', { cx: 18, cy: 5, r: 3 }),
  h('circle', { cx: 6, cy: 12, r: 3 }),
  h('circle', { cx: 18, cy: 19, r: 3 }),
  h('line', { x1: 8.59, y1: 13.51, x2: 15.42, y2: 17.49 }),
  h('line', { x1: 15.41, y1: 6.51, x2: 8.59, y2: 10.49 }),
]);

const LinkIcon = makeIcon('IconLink', () => [
  h('path', { d: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71' }),
  h('path', { d: 'M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71' }),
]);

const selectedText = ref('');
const targetResourceUrl = ref('');
const targetResourceTitle = ref('');
const hasClipboard = ref(false);
const clipboardText = ref('');
const canCut = ref(false);

const shareDialogOpen = ref(false);
const shareUrl = ref('');
const shareTitle = ref('');
const qrContainerRef = ref<HTMLElement | null>(null);

async function prepareItems({ target }: { target: HTMLElement }): Promise<void> {
  if (typeof window !== 'undefined' && window.getSelection) {
    selectedText.value = window.getSelection()?.toString().trim() ?? '';
  } else {
    selectedText.value = '';
  }

  const anchorEl = target.closest<HTMLAnchorElement>('a[href]');
  const imgEl = target.closest<HTMLImageElement>('img[src]');
  const shareDatasetEl = target.closest<HTMLElement>('[data-share-url]');

  if (shareDatasetEl?.dataset.shareUrl) {
    targetResourceUrl.value = shareDatasetEl.dataset.shareUrl;
    targetResourceTitle.value = shareDatasetEl.dataset.shareTitle || '';
  } else if (anchorEl?.href) {
    targetResourceUrl.value = anchorEl.href;
    targetResourceTitle.value = anchorEl.textContent?.trim() || anchorEl.href;
  } else if (imgEl?.src) {
    targetResourceUrl.value = imgEl.src;
    targetResourceTitle.value = imgEl.alt?.trim() || imgEl.src;
  } else {
    targetResourceUrl.value = typeof window !== 'undefined' ? window.location.href : '';
    targetResourceTitle.value = typeof document !== 'undefined' ? document.title : '';
  }

  hasClipboard.value = false;
  clipboardText.value = '';
  if (
    typeof navigator !== 'undefined' &&
    navigator.clipboard &&
    typeof navigator.clipboard.readText === 'function'
  ) {
    try {
      const text = await navigator.clipboard.readText();
      if (text && text.length > 0) {
        hasClipboard.value = true;
        clipboardText.value = text;
      }
    } catch {
      // Clipboard permission denied or unavailable in sandbox
    }
  }

  const isEditable =
    target.isContentEditable || Boolean(target.closest('[contenteditable="true"]'));
  canCut.value = isEditable && selectedText.value.length > 0;
}

const items = computed<MenuItem[]>(() => {
  const hasSelection = selectedText.value.length > 0;
  const isTargetUrl = Boolean(
    targetResourceUrl.value &&
    typeof window !== 'undefined' &&
    targetResourceUrl.value !== window.location.href,
  );

  return [
    {
      id: 'copy',
      label: hasSelection ? t('menus.copy') : isTargetUrl ? t('menus.copyLink') : t('menus.copy'),
      icon: CopyIcon,
      shortcut: 'Ctrl+C',
      disabled: !hasSelection && !isTargetUrl,
    },
    {
      id: 'cut',
      label: t('menus.cut'),
      icon: CutIcon,
      shortcut: 'Ctrl+X',
      disabled: !canCut.value,
    },
    {
      id: 'paste',
      label: t('menus.paste'),
      icon: PasteIcon,
      shortcut: 'Ctrl+V',
      disabled: !hasClipboard.value,
    },
    {
      id: 'select-all',
      label: t('menus.selectAll'),
      shortcut: 'Ctrl+A',
    },
    { id: 'sep-edit', kind: 'separator', label: '' },
    {
      id: 'share',
      label: t('menus.share'),
      icon: ShareIcon,
      description: isTargetUrl ? t('menus.shareResource') : t('menus.sharePage'),
    },
    {
      id: 'copy-link',
      label: isTargetUrl ? t('menus.copyLink') : t('menus.copyPageLink'),
      icon: LinkIcon,
    },
    { id: 'sep-share', kind: 'separator', label: '' },
    {
      id: 'nav-back',
      label: t('menus.back'),
      icon: ChevronLeft,
      shortcut: 'Alt+←',
    },
    {
      id: 'nav-forward',
      label: t('menus.forward'),
      icon: ChevronRight,
      shortcut: 'Alt+→',
    },
    {
      id: 'reload',
      label: t('menus.reload'),
      icon: RotateCw,
      shortcut: 'Ctrl+R',
    },
    { id: 'sep-nav', kind: 'separator', label: '' },
    { id: 'settings', label: t('nav.settings'), icon: Settings },
    { id: 'catalog', label: t('nav.catalog'), icon: LayoutGrid },
    { id: 'sep-site', kind: 'separator', label: '' },
    {
      id: 'theme',
      label: t('appearance.theme'),
      icon: theme.value === 'dark' ? Moon : Sun,
      children: themes.map((value) => ({
        id: `theme:${value}`,
        label: t(`appearance.theme_${value}`),
        checked: theme.value === value,
        checkType: 'radio',
      })),
    },
    {
      id: 'density',
      label: t('appearance.density'),
      children: densities.map((value) => ({
        id: `density:${value}`,
        label: t(`appearance.density_${value}`),
        checked: appearance.density === value,
        checkType: 'radio',
      })),
    },
  ];
});

async function select(item: MenuItem): Promise<void> {
  if (item.id === 'copy') {
    if (selectedText.value) {
      if (typeof navigator !== 'undefined' && navigator.clipboard) {
        await navigator.clipboard.writeText(selectedText.value);
        toast.show(t('menus.copiedText'), 'success');
      }
    } else if (targetResourceUrl.value) {
      if (typeof navigator !== 'undefined' && navigator.clipboard) {
        await navigator.clipboard.writeText(targetResourceUrl.value);
        toast.show(t('menus.copiedLink'), 'success');
      }
    }
  } else if (item.id === 'cut') {
    if (selectedText.value) {
      if (typeof navigator !== 'undefined' && navigator.clipboard) {
        await navigator.clipboard.writeText(selectedText.value);
      }
      document.execCommand('delete');
      toast.show(t('menus.cutDone'), 'success');
    }
  } else if (item.id === 'paste') {
    if (clipboardText.value) {
      if (document.activeElement && 'value' in document.activeElement) {
        const input = document.activeElement as HTMLInputElement;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.setRangeText(clipboardText.value, start, end, 'end');
        input.dispatchEvent(new Event('input', { bubbles: true }));
      } else {
        document.execCommand('insertText', false, clipboardText.value);
      }
      toast.show(t('menus.pasted'), 'success');
    }
  } else if (item.id === 'select-all') {
    document.execCommand('selectAll');
  } else if (item.id === 'share') {
    shareUrl.value =
      targetResourceUrl.value || (typeof window !== 'undefined' ? window.location.href : '');
    shareTitle.value =
      targetResourceTitle.value || (typeof document !== 'undefined' ? document.title : '');
    shareDialogOpen.value = true;
  } else if (item.id === 'copy-link') {
    const url =
      targetResourceUrl.value || (typeof window !== 'undefined' ? window.location.href : '');
    if (typeof navigator !== 'undefined' && navigator.clipboard) {
      await navigator.clipboard.writeText(url);
      toast.show(t('menus.copiedLink'), 'success');
    }
  } else if (item.id === 'nav-back') {
    if (typeof window !== 'undefined') window.history.back();
  } else if (item.id === 'nav-forward') {
    if (typeof window !== 'undefined') window.history.forward();
  } else if (item.id === 'reload') {
    if (typeof window !== 'undefined') window.location.reload();
  } else if (item.id.startsWith('theme:')) {
    setTheme(item.id.slice(6) as Theme);
  } else if (item.id.startsWith('density:')) {
    updateAppearance({ density: item.id.slice(8) as Density });
  } else if (item.id === 'settings' || item.id === 'catalog') {
    void router.push({ name: item.id });
  }
}

const canWebShare = computed(
  () => typeof navigator !== 'undefined' && typeof navigator.share === 'function',
);

async function copyShareUrl(): Promise<void> {
  if (typeof navigator !== 'undefined' && navigator.clipboard) {
    await navigator.clipboard.writeText(shareUrl.value);
    toast.show(t('menus.copiedLink'), 'success');
  }
}

function downloadQr(): void {
  const svgEl = qrContainerRef.value?.querySelector('svg');
  if (!svgEl) return;
  const serializer = new XMLSerializer();
  const source = serializer.serializeToString(svgEl);
  const blob = new Blob([source], { type: 'image/svg+xml;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  const safeName = (shareTitle.value || 'resource')
    .toLowerCase()
    .replace(/[^a-z0-9а-яё]+/gi, '-')
    .slice(0, 30);
  a.download = `qr-${safeName || 'code'}.svg`;
  a.click();
  URL.revokeObjectURL(url);
  toast.show(t('menus.qrDownloaded'), 'success');
}

async function shareViaNavigator(): Promise<void> {
  if (typeof navigator !== 'undefined' && navigator.share) {
    try {
      await navigator.share({
        title: shareTitle.value || (typeof document !== 'undefined' ? document.title : ''),
        url: shareUrl.value,
      });
      toast.show(t('menus.shared'), 'success');
    } catch {
      // User cancelled
    }
  }
}
</script>

<template>
  <AppContextMenu
    :items="items"
    :label="$t('menus.siteContext')"
    :before-open="prepareItems"
    @select="select"
  >
    <slot />
  </AppContextMenu>

  <AppDialog
    v-model="shareDialogOpen"
    :title="$t('menus.shareTitle')"
    size="s"
  >
    <div class="share-dialog">
      <div
        ref="qrContainerRef"
        class="share-dialog__qr-container"
      >
        <QrCode
          :value="shareUrl"
          :size="200"
          :margin="2"
          :label="$t('menus.qrLabel')"
        />
      </div>

      <div class="share-dialog__url-box">
        <span class="share-dialog__url-label">{{ $t('menus.shareUrlLabel') }}</span>
        <code class="share-dialog__url-text">{{ shareUrl }}</code>
      </div>
    </div>

    <template #footer>
      <div class="share-dialog__actions">
        <AppButton
          variant="secondary"
          @click="copyShareUrl"
        >
          <component
            :is="CopyIcon"
            :size="16"
            aria-hidden="true"
          />
          {{ $t('menus.copyUrl') }}
        </AppButton>

        <AppButton
          variant="secondary"
          @click="downloadQr"
        >
          {{ $t('menus.downloadQr') }}
        </AppButton>

        <AppButton
          v-if="canWebShare"
          variant="primary"
          @click="shareViaNavigator"
        >
          {{ $t('menus.shareVia') }}
        </AppButton>

        <AppButton
          v-else
          variant="primary"
          @click="shareDialogOpen = false"
        >
          {{ $t('common.close') }}
        </AppButton>
      </div>
    </template>
  </AppDialog>
</template>

<style scoped>
.share-dialog {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-16);
  padding: var(--space-8) 0;
}

.share-dialog__qr-container {
  display: inline-flex;
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  background: var(--color-white);
  box-shadow: var(--shadow-soft);
  transition: var(--theme-transition);
}

.share-dialog__url-box {
  width: 100%;
  display: grid;
  gap: var(--space-4);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
  transition: var(--theme-transition);
}

.share-dialog__url-label {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
  transition: var(--theme-transition);
}

.share-dialog__url-text {
  font-family: var(--font-mono);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-1);
  overflow-wrap: anywhere;
  word-break: break-all;
  user-select: all;
  transition: var(--theme-transition);
}

.share-dialog__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  justify-content: flex-end;
  width: 100%;
}
</style>
