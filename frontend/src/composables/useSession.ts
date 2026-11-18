import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { meQuery } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** The signed-in user, their companies and the company they are working in. */
export function useSession() {
  const store = useSessionStore()
  const { data } = useQuery(meQuery)

  const user = computed(() => data.value ?? null)
  const companies = computed(() => user.value?.companies ?? [])
  const currentCompany = computed(
    () => companies.value.find((company) => company.id === store.currentCompanyId) ?? null,
  )

  return { user, companies, currentCompany }
}
