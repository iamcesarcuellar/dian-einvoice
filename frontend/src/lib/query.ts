import { QueryClient, queryOptions } from '@tanstack/vue-query'
import { authApi } from '@/api/auth'
import { ApiError } from '@/api/client'
import type { Me } from '@/api/types'

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      // Client errors (4xx) are final; network and server errors are retried once.
      retry: (failureCount, error) => !(error instanceof ApiError && error.status < 500) && failureCount < 1,
    },
  },
})

export const queryKeys = {
  me: ['me'] as const,
  reference: ['reference'] as const,
  company: (id: string) => ['companies', id] as const,
}

/**
 * Key of data that belongs to a company. It includes the company id, so switching company
 * never shows the cache of another one.
 */
export function tenantKey(companyId: string | null, resource: string, ...rest: unknown[]): unknown[] {
  return ['tenant', companyId, resource, ...rest]
}

/** Refetches a tenant resource, such as "contacts", for every company. */
export function invalidateTenant(...resources: string[]): Promise<void> {
  return queryClient.invalidateQueries({
    predicate: (query) => query.queryKey[0] === 'tenant' && resources.includes(String(query.queryKey[2])),
  })
}

/** The signed-in user, or null when there is no session. */
export const meQuery = queryOptions({
  queryKey: queryKeys.me,
  queryFn: async (): Promise<Me | null> => {
    try {
      return await authApi.me()
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        return null
      }

      throw error
    }
  },
  staleTime: 5 * 60_000,
})
