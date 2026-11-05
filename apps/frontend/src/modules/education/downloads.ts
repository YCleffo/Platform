import { onScopeDispose, ref, shallowRef } from 'vue';
import { coreAdminSession } from '@/core/admin/session';
import { ApiResponseError } from '@/shared/api/errors';
import { educationApi, type Material } from './api';
import { useEducationWorkspace } from './workspace';

export function useEducationDownload() {
  const workspace = useEducationWorkspace(); const busy = ref(''); const error = shallowRef<unknown>(null); let active = true;
  onScopeDispose(() => { active = false; });
  async function download(file: Material): Promise<void> {
    if (busy.value) return;
    const revision = workspace.revision; const epoch = coreAdminSession.epoch(); const org = workspace.organizationId;
    const current = () => active && revision === workspace.revision && epoch === coreAdminSession.epoch();
    busy.value = file.id; error.value = null;
    try {
      const blob = await educationApi.download(org, file.id);
      if (!current()) return;
      if (blob.size !== file.sizeBytes || blob.type.split(';')[0] !== file.mime) throw new ApiResponseError('/education/files');
      const url = URL.createObjectURL(blob); const anchor = document.createElement('a');
      anchor.href = url; anchor.download = file.name; document.body.append(anchor); anchor.click(); anchor.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (caught) { if (current()) error.value = caught; }
    finally { if (current()) busy.value = ''; }
  }
  return { busy, error, download };
}
