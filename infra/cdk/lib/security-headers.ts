// The security headers of the platform and the paths the edge sends to the API, from their single source of truth
// in the backend (backend/resources/http): security-headers.json, which the backend also sets its answers from
// (App\Http\SecurityHeaderPolicy), and paths.json, which a backend test holds every route of the application to.
// Compose mounts that directory read-only at /workspace/http; HTTP_RESOURCES may point elsewhere. The policy of a
// document is rendered as the backend renders it: each directive with its sources, in the order of the file,
// joined by "; ".
import { readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'

export type DocumentName = 'panel' | 'api' | 'files' | 'apiReference'

export interface DocumentHeaders {
  readonly headers: Readonly<Record<string, string>>
  readonly contentSecurityPolicy: Readonly<Record<string, string>>
  readonly reasons: Readonly<Record<string, string>>
}

export interface SecurityHeaders {
  readonly common: Readonly<Record<'Strict-Transport-Security' | 'X-Content-Type-Options' | 'X-Frame-Options', string>>
  readonly documents: Readonly<Record<DocumentName, DocumentHeaders>>
}

/** Strict-Transport-Security, read from its header value, as the response headers policy of CloudFront takes it. */
export interface StrictTransportSecurity {
  readonly maxAgeSeconds: number
  readonly includeSubDomains: boolean
  readonly preload: boolean
}

export function httpResourcesDirectory(): string {
  return process.env.HTTP_RESOURCES ?? resolve(import.meta.dirname, '../../http')
}

function read(file: string): unknown {
  return JSON.parse(readFileSync(file, 'utf8'))
}

function strings(value: unknown, what: string): Record<string, string> {
  if (typeof value !== 'object' || value === null || Array.isArray(value) || !Object.values(value).every((entry) => typeof entry === 'string' && entry !== '')) {
    throw new Error(`security-headers.json: ${what} must map names to values.`)
  }

  return value as Record<string, string>
}

export function loadSecurityHeaders(directory: string = httpResourcesDirectory()): SecurityHeaders {
  const parsed = read(join(directory, 'security-headers.json'))

  if (typeof parsed !== 'object' || parsed === null || !('common' in parsed) || !('documents' in parsed) || typeof parsed.documents !== 'object' || parsed.documents === null) {
    throw new Error('security-headers.json has no common headers or documents.')
  }

  const common = strings(parsed.common, 'common')

  for (const name of ['Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options']) {
    if (common[name] === undefined) {
      throw new Error(`security-headers.json: the common headers have no ${name}.`)
    }
  }

  const documents: Partial<Record<DocumentName, DocumentHeaders>> = {}

  for (const name of ['panel', 'api', 'files', 'apiReference'] as const) {
    const document = (parsed.documents as Record<string, unknown>)[name]

    if (typeof document !== 'object' || document === null) {
      throw new Error(`security-headers.json has no document ${name}.`)
    }

    const fields = document as Record<string, unknown>
    documents[name] = {
      headers: strings(fields.headers, `${name}.headers`),
      contentSecurityPolicy: strings(fields.contentSecurityPolicy, `${name}.contentSecurityPolicy`),
      reasons: fields.reasons === undefined || (typeof fields.reasons === 'object' && fields.reasons !== null && Object.keys(fields.reasons).length === 0) ? {} : strings(fields.reasons, `${name}.reasons`),
    }
  }

  return { common: common as SecurityHeaders['common'], documents: documents as SecurityHeaders['documents'] }
}

/** The Content-Security-Policy of a document as one header value. */
export function renderContentSecurityPolicy(document: DocumentHeaders): string {
  return Object.entries(document.contentSecurityPolicy).map(([directive, sources]) => `${directive} ${sources}`).join('; ')
}

export function strictTransportSecurity(value: string): StrictTransportSecurity {
  const directives = value.split(';').map((directive) => directive.trim().toLowerCase())
  const maxAge = directives.map((directive) => /^max-age=(\d+)$/.exec(directive)?.[1]).find((match) => match !== undefined)

  if (maxAge === undefined || directives.some((directive) => !/^(max-age=\d+|includesubdomains|preload)$/.test(directive))) {
    throw new Error(`Strict-Transport-Security "${value}" is not max-age with includeSubDomains or preload.`)
  }

  return { maxAgeSeconds: Number(maxAge), includeSubDomains: directives.includes('includesubdomains'), preload: directives.includes('preload') }
}

/** The path patterns of CloudFront the edge sends to the API (backend/resources/http/paths.json). */
export function loadApiPaths(directory: string = httpResourcesDirectory()): readonly string[] {
  const parsed = read(join(directory, 'paths.json'))
  const paths = typeof parsed === 'object' && parsed !== null && 'api' in parsed ? parsed.api : null

  if (!Array.isArray(paths) || paths.length === 0 || !paths.every((path) => typeof path === 'string' && /^\/[A-Za-z0-9_./*?-]+$/.test(path))) {
    throw new Error('paths.json must list the path patterns of the API, such as /api/*.')
  }

  return paths as string[]
}

/** The sign-in points the firewall of the edge limits more strictly: POST routes of the application, as Laravel writes them. */
export function loadAuthenticationPaths(directory: string = httpResourcesDirectory()): readonly string[] {
  const parsed = read(join(directory, 'paths.json'))
  const paths = typeof parsed === 'object' && parsed !== null && 'authentication' in parsed ? parsed.authentication : null

  if (!Array.isArray(paths) || paths.length === 0 || !paths.every((path) => typeof path === 'string' && /^\/api\/[A-Za-z0-9_/{}-]+$/.test(path))) {
    throw new Error('paths.json must list the sign-in points, routes of the API such as /api/v1/auth/login.')
  }

  return paths as string[]
}
