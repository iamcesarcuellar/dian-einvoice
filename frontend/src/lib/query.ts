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
  company: (id: string) => ['companies', id] as const,
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
