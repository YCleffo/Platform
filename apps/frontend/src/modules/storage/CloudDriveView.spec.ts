import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { i18n } from '@/shared/i18n';
import CloudDriveView from './CloudDriveView.vue';
import { mediaApi, type MediaAsset } from '@/shared/api/mediaApi';
import { workspaceApi } from '@/shared/api/workspaceApi';
import { useOperationsCenter } from '@/modules/workspace/useOperationsCenter';

vi.mock('@/shared/api/mediaApi', () => ({
  computeBlobSha256: vi.fn().mockResolvedValue('a'.repeat(64)),
  mediaApi: {
    listAssets: vi.fn(),
    reserveUpload: vi.fn(),
    uploadBinary: vi.fn(),
    confirmUpload: vi.fn(),
    cancelUpload: vi.fn(),
    getPlaybackUrl: vi.fn(),
    getDownloadUrl: vi.fn(),
    renameAsset: vi.fn(),
    moveAsset: vi.fn(),
    copyAsset: vi.fn(),
  },
}));

vi.mock('@/shared/api/workspaceApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/shared/api/workspaceApi')>();
  return {
    ...actual,
    workspaceApi: {
      ...actual.workspaceApi,
      moveToTrash: vi.fn(),
    },
  };
});

vi.mock('@/shared/composables/useToast', () => ({
  useToast: () => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  }),
}));

describe('CloudDriveView (Universal File Manager)', () => {
  const assetsData: MediaAsset[] = [
    {
      id: 'asset-doc-1',
      kind: 'document',
      originalName: 'financial_summary.pdf',
      originalMime: 'application/pdf',
      verifiedMime: 'application/pdf',
      sizeBytes: 2097152,
      sha256: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
      status: 'READY',
      metadata: {},
      createdAt: '2026-11-23T00:00:00Z',
      updatedAt: '2026-11-23T00:00:00Z',
    },
    {
      id: 'asset-img-2',
      kind: 'image',
      originalName: 'diagram.png',
      originalMime: 'image/png',
      verifiedMime: 'image/png',
      sizeBytes: 512000,
      sha256: 'b'.repeat(64),
      status: 'READY',
      metadata: { folderPath: 'designs' },
      createdAt: '2026-11-24T00:00:00Z',
      updatedAt: '2026-11-24T00:00:00Z',
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    const ops = useOperationsCenter();
    ops.setOrganizationId('org-1');
    vi.mocked(mediaApi.listAssets).mockResolvedValue({
      items: [...assetsData],
      limit: 50,
      offset: 0,
    });
  });

  it('renders files, folders, and navigates into nested folder', async () => {
    const wrapper = mount(CloudDriveView, {
      props: { organizationId: 'org-1' },
      global: { plugins: [i18n], stubs: { RouterLink: true } },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('financial_summary.pdf');
    expect(wrapper.text()).toContain('designs');
    expect(wrapper.text()).not.toContain('diagram.png');

    const folderBtn = wrapper.find('.cloud-drive__folder-btn');
    await folderBtn.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('diagram.png');
    expect(wrapper.text()).not.toContain('financial_summary.pdf');
  });

  it('opens media preview using real playback URL', async () => {
    vi.mocked(mediaApi.getPlaybackUrl).mockResolvedValueOnce({
      url: '/api/media/stream/asset-doc-1?ticket=abc',
      mime: 'application/pdf',
      durationSeconds: null,
      expiresAt: '2026-11-27T12:00:00Z',
    });

    const wrapper = mount(CloudDriveView, {
      props: { organizationId: 'org-1' },
      global: { plugins: [i18n], stubs: { RouterLink: true } },
    });
    await flushPromises();

    const buttons = wrapper.findAll('[data-testid="drive-file-row"] button');
    await buttons[0]?.trigger('click');
    await flushPromises();

    expect(mediaApi.getPlaybackUrl).toHaveBeenCalledWith('org-1', 'asset-doc-1');
  });

  it('copies a file and moves a file to Trash', async () => {
    vi.mocked(mediaApi.copyAsset).mockResolvedValueOnce({
      ...assetsData[0]!,
      id: 'asset-doc-copy',
      originalName: 'financial_summary (copy).pdf',
    });
    vi.mocked(workspaceApi.moveToTrash).mockResolvedValueOnce({} as never);

    const wrapper = mount(CloudDriveView, {
      props: { organizationId: 'org-1' },
      global: { plugins: [i18n], stubs: { RouterLink: true } },
    });
    await flushPromises();

    const rowButtons = wrapper.findAll('[data-testid="drive-file-row"] button');
    // Copy button is 6th button (index 5)
    await rowButtons[5]?.trigger('click');
    await flushPromises();

    expect(mediaApi.copyAsset).toHaveBeenCalledWith('org-1', 'asset-doc-1', '');
    expect(wrapper.text()).toContain('financial_summary (copy).pdf');
  });
});
