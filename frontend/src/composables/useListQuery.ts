import type { ListParams, Paginated, QueryParams } from '@/api/types'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

interface ListOptions<T> {
  perPage?: number
  /** Extra query parameters, such as { status: 'draft' }; changing them starts again from page 1. */
  filters?: () => QueryParams
  /** Milliseconds between refreshes while the rows on screen need it, or false. */
  refetchInterval?: (rows: T[]) => number | false
}

/**
 * Page, search and filter state of a tenant list, with its query; pair it with DataTable, which
 * shows its loading, empty and error states (retry calls refetch).
 */
export function useListQuery<T>(resource: string, list: (params: ListParams) => Promise<Paginated<T>>, options: ListOptions<T> = {}) {
  const session = useSessionStore()
  const page = ref(1)
  // A list opened from the command palette (See all) starts with its search: ?search=.
  const initial = useRoute().query.search
  const search = ref(typeof initial === 'string' ? initial : '')
  const filters = computed(() => options.filters?.() ?? {})

  // A new search, other filters or another company start again from the first page.
  watch([search, () => JSON.stringify(filters.value)], () => {
    page.value = 1
  })

  watch(
    () => session.currentCompanyId,
    () => {
      page.value = 1
      search.value = ''
    },
  )

  const params = computed<ListParams>(() => ({
    ...filters.value,
    page: page.value,
    per_page: options.perPage ?? 25,
    search: search.value.trim() || undefined,
  }))

  const query = useQuery({
    queryKey: computed(() => tenantKey(session.currentCompanyId, resource, 'list', params.value)),
    queryFn: () => list(params.value),
    enabled: computed(() => session.currentCompanyId !== null),
    // Keep the previous page on screen while the next one loads, but never another company's rows.
    placeholderData: (previous, previousQuery) => (previousQuery?.queryKey[1] === session.currentCompanyId ? previous : undefined),
    refetchInterval: (query) => options.refetchInterval?.(query.state.data?.data ?? []) ?? false,
  })

  return {
    page,
    search,
    rows: computed(() => query.data.value?.data ?? []),
    meta: computed(() => query.data.value?.meta),
    isLoading: computed(() => query.isPending.value),
    error: computed(() => query.error.value),
    refetch: () => query.refetch(),
  }
}
