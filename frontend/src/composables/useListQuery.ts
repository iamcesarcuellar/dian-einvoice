import type { ListParams, Paginated } from '@/api/types'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** Page and search state of a tenant list, with its query; pair it with DataTable. */
export function useListQuery<T>(resource: string, list: (params: ListParams) => Promise<Paginated<T>>, perPage = 25) {
  const session = useSessionStore()
  const page = ref(1)
  const search = ref('')

  // A new search, or another company, starts again from the first page.
  watch(search, () => {
    page.value = 1
  })

  watch(() => session.currentCompanyId, () => {
    page.value = 1
    search.value = ''
  })

  const params = computed<ListParams>(() => ({
    page: page.value,
    per_page: perPage,
    search: search.value.trim() || undefined,
  }))

  const query = useQuery({
    queryKey: computed(() => tenantKey(session.currentCompanyId, resource, 'list', params.value)),
    queryFn: () => list(params.value),
    enabled: computed(() => session.currentCompanyId !== null),
    // Keep the previous page on screen while the next one loads, but never another company's rows.
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[1] === session.currentCompanyId ? previous : undefined,
  })

  return {
    page,
    search,
    rows: computed(() => query.data.value?.data ?? []),
    meta: computed(() => query.data.value?.meta),
    isLoading: computed(() => query.isPending.value),
  }
}
