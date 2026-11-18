import { onScopeDispose, ref, shallowRef, watch } from 'vue';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiResponseError } from '@/shared/api/errors';
import { educationApi, type Material } from './api';
export function useEducationDownload(organization: () => string, scope: () => string | number) {
  const busy = ref('');
  const error = shallowRef<unknown>(null);
  let controller: AbortController | undefined;

  function cancel(): void {
    controller?.abort();
    controller = undefined;
    busy.value = '';
    error.value = null;
  }

  watch([organization, scope, coreAdminSessionEpoch], cancel, { flush: 'sync' });
  onScopeDispose(cancel);

  async function download(file: Material): Promise<void> {
    if (busy.value) return;
    const request = new AbortController();
    controller = request;
    busy.value = file.id;
    error.value = null;
    try {
      const blob = await educationApi.download(organization(), file.id, request.signal);
      if (controller !== request) return;
      if (blob.size !== file.sizeBytes || blob.type.split(';')[0] !== file.mime) throw new ApiResponseError('/education/files');
      const url = URL.createObjectURL(blob); const anchor = document.createElement('a');
      anchor.href = url; anchor.download = file.name; document.body.append(anchor); anchor.click(); anchor.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (caught) {
      if (controller === request) error.value = caught;
    } finally {
      if (controller === request) {
        controller = undefined;
        busy.value = '';
      }
    }
  }
  return { busy, error, download };
}
