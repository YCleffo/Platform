import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { i18n } from '@/shared/i18n';
import CloudDriveView from './CloudDriveView.vue';
import {
  cloudDriveApi,
  type DriveFile,
  type DriveSpace,
  type DriveFileVersion,
  type DriveConflict,
} from '@/shared/api/storage/drive';

vi.mock('@/shared/api/storage/drive', () => ({
  cloudDriveApi: {
    space: vi.fn(),
    listFiles: vi.fn(),
    listConflicts: vi.fn(),
    requestUploadTicket: vi.fn(),
    generatePresignedDownloadUrl: vi.fn(),
    listVersions: vi.fn(),
    restoreVersion: vi.fn(),
    renameFile: vi.fn(),
    deleteFile: vi.fn(),
    resolveConflict: vi.fn(),
  },
}));

vi.mock('@/shared/composables/useToast', () => ({
  useToast: () => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  }),
}));

describe('CloudDriveView', () => {
  const spaceData: DriveSpace = {
    bytesUsed: 52428800, // 50 MB
    bytesLimit: 1073741824, // 1 GB
    filesCount: 12,
    versionCount: 25,
  };

  const filesData: DriveFile[] = [
    {
      id: 'file-doc-1',
      name: 'financial_summary.pdf',
      path: '/financial_summary.pdf',
      sizeBytes: 2097152,
      mimeType: 'application/pdf',
      sha256: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
      version: 2,
      updatedAt: '2026-11-23T00:00:00Z',
      isDeleted: false,
    },
  ];

  const conflictsData: DriveConflict[] = [
    {
      id: 'conf-1',
      fileId: 'file-doc-1',
      fileName: 'financial_summary.pdf',
      filePath: '/financial_summary.pdf',
      serverVersion: 3,
      clientVersion: 2,
      createdAt: '2026-11-23T00:00:00Z',
    },
  ];

  const versionsData: DriveFileVersion[] = [
    {
      id: 'ver-1',
      fileId: 'file-doc-1',
      versionNumber: 1,
      sizeBytes: 1048576,
      sha256: 'a1b2c3d4e5f6',
      createdAt: '2026-11-20T00:00:00Z',
    },
    {
      id: 'ver-2',
      fileId: 'file-doc-1',
      versionNumber: 2,
      sizeBytes: 2097152,
      sha256: 'e3b0c44298fc',
      createdAt: '2026-11-23T00:00:00Z',
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(cloudDriveApi.space).mockResolvedValue(spaceData);
    vi.mocked(cloudDriveApi.listFiles).mockResolvedValue([...filesData]);
    vi.mocked(cloudDriveApi.listConflicts).mockResolvedValue([...conflictsData]);
  });

  it('renders quota usage and file explorer', async () => {
    const wrapper = mount(CloudDriveView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('50 MB');
    expect(wrapper.text()).toContain('1 GB');
    expect(wrapper.text()).toContain('financial_summary.pdf');
    expect(wrapper.text()).toContain('v2');
  });

  it('displays conflict banner when conflicts exist', async () => {
    const wrapper = mount(CloudDriveView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(wrapper.find('.cloud-drive__conflict-banner').exists()).toBe(true);
  });

  it('opens share modal and requests presigned link', async () => {
    vi.mocked(cloudDriveApi.generatePresignedDownloadUrl).mockResolvedValueOnce({
      url: 'https://s3.platform.example/presigned/test?exp=123',
      expiresAt: '2026-11-23T01:00:00Z',
    });

    const wrapper = mount(CloudDriveView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const buttons = wrapper.findAll('.cloud-drive__btn-group button');
    // First button is Share / Generate link
    await buttons[0]?.trigger('click');
    await flushPromises();

    expect(cloudDriveApi.generatePresignedDownloadUrl).toHaveBeenCalledWith('file-doc-1', 3600);
  });

  it('opens versions modal and displays version history', async () => {
    vi.mocked(cloudDriveApi.listVersions).mockResolvedValueOnce([...versionsData]);

    const wrapper = mount(CloudDriveView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const buttons = wrapper.findAll('.cloud-drive__btn-group button');
    // Second button is Versions
    await buttons[1]?.trigger('click');
    await flushPromises();

    expect(cloudDriveApi.listVersions).toHaveBeenCalledWith('file-doc-1');
  });
});
