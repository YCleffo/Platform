import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { coreResources } from './workbench/catalog';
export function useAdminResourceConfigs() {
  const {t} = useI18n();
  return computed(() => Object.fromEntries(coreResources.map(resource=>[resource.key,{...resource,title:t(resource.title),description:''}])));
}
