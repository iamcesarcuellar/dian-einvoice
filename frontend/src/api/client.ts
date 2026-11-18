import type { Problem, QueryParams } from './types'
import { useSessionStore } from '@/stores/session'

const API_BASE = '/api/v1'
const CSRF_COOKIE = 'XSRF-TOKEN'

type Method = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

/** An HTTP error answered by the API as application/problem+json. */
export class ApiError extends Error {
  readonly status: number
  readonly problem: Problem

  constructor(problem: Problem) {
    super(problem.detail ?? problem.title)
    this.name = 'ApiError'
    this.status = problem.status
    this.problem = problem
  }

  /** Validation messages by field, such as { email: ['The email field is required.'] }. */
  get fieldErrors(): Record<string, string[]> {
    return this.problem.errors ?? {}
  }
}

/** Validation messages by field for any error; empty when it is not a validation error. */
export function fieldErrors(error: unknown): Record<string, string[]> {
  return error instanceof ApiError ? error.fieldErrors : {}
}

function readCookie(name: string): string | null {
  const prefix = `${name}=`
  const cookie = document.cookie.split('; ').find((part) => part.startsWith(prefix))

  return cookie ? decodeURIComponent(cookie.slice(prefix.length)) : null
}

/** Sanctum sets the XSRF-TOKEN cookie; writes send it back in the X-XSRF-TOKEN header. */
async function fetchCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } })
}

async function toApiError(response: Response): Promise<ApiError> {
  let problem: Problem

  try {
    problem = (await response.json()) as Problem
  } catch {
    problem = { type: 'about:blank', title: response.statusText || 'Error', status: response.status }
  }

  return new ApiError({ ...problem, status: response.status })
}

function url(path: string, query?: QueryParams): string {
  const params = new URLSearchParams()

  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== '') {
      params.set(key, String(value))
    }
  }

  const search = params.toString()

  return `${API_BASE}${path}${search ? `?${search}` : ''}`
}

async function send(method: Method, path: string, body: unknown, query?: QueryParams): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const isForm = body instanceof FormData

  // FormData sets its own multipart content type, with the boundary.
  if (body !== undefined && !isForm) {
    headers['Content-Type'] = 'application/json'
  }

  if (method !== 'GET') {
    if (!readCookie(CSRF_COOKIE)) {
      await fetchCsrfCookie()
    }

    const token = readCookie(CSRF_COOKIE)

    if (token) {
      headers['X-XSRF-TOKEN'] = token
    }
  }

  // Tenant endpoints act on the company selected in the session store.
  const companyId = useSessionStore().currentCompanyId

  if (companyId) {
    headers['X-Company-Id'] = companyId
  }

  return fetch(url(path, query), {
    method,
    headers,
    credentials: 'include',
    body: body === undefined ? undefined : (isForm ? body : JSON.stringify(body)),
  })
}

async function sendChecked(method: Method, path: string, body?: unknown, query?: QueryParams): Promise<Response> {
  let response = await send(method, path, body, query)

  // The CSRF token expired or was replaced: fetch a fresh cookie and retry once.
  if (response.status === 419) {
    await fetchCsrfCookie()
    response = await send(method, path, body, query)
  }

  if (!response.ok) {
    throw await toApiError(response)
  }

  return response
}

/**
 * Calls the API under /api/v1 and returns the JSON body. A FormData body is sent as multipart, for
 * file uploads. Throws ApiError on HTTP errors.
 */
export async function request<T>(method: Method, path: string, body?: unknown, query?: QueryParams): Promise<T> {
  const response = await sendChecked(method, path, body, query)

  if (response.status === 204) {
    return undefined as T
  }

  return (await response.json()) as T
}

/** GETs a file from the API, such as an XML document, as text. Throws ApiError on HTTP errors. */
export async function requestText(path: string): Promise<string> {
  return (await sendChecked('GET', path)).text()
}

/** Hands text to the browser as a file download. */
export function saveTextFile(contents: string, fileName: string, type: string): void {
  const url = URL.createObjectURL(new Blob([contents], { type }))
  const link = document.createElement('a')

  link.href = url
  link.download = fileName
  link.click()

  // Released once the browser has taken the file.
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
