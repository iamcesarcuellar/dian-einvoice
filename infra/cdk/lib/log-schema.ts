// The schema of the structured log line of every function, from its single source of truth in the backend
// (backend/resources/observability/log-schema.json): the formatter of the backend writes it, the logger of the
// TypeScript functions writes it, and here the metric filters and the saved queries read its fields, which a test
// holds them to. Compose mounts that directory read-only at /workspace/observability; LOG_SCHEMA may point elsewhere.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

export interface LogField {
  readonly name: string
  readonly type: string
  readonly nullable?: boolean
  readonly optional?: boolean
  readonly values?: readonly string[]
  readonly fields?: readonly string[]
}

export interface LogSchema {
  readonly correlation: { readonly header: string, readonly pattern: string }
  readonly fields: readonly LogField[]
  readonly detail: Readonly<Record<string, { readonly type: string, readonly description: string }>>
  readonly events: Readonly<Record<string, string>>
}

export function logSchemaFile(): string {
  return process.env.LOG_SCHEMA ?? resolve(import.meta.dirname, '../../observability/log-schema.json')
}

export function loadLogSchema(file: string = logSchemaFile()): LogSchema {
  const parsed = JSON.parse(readFileSync(file, 'utf8')) as Partial<LogSchema>

  if (!Array.isArray(parsed.fields) || typeof parsed.detail !== 'object' || typeof parsed.events !== 'object' || typeof parsed.correlation?.pattern !== 'string') {
    throw new Error(`${file} is not the schema of the log lines.`)
  }

  return parsed as LogSchema
}

/**
 * Whether a field of a line exists in the schema: a field of the line (event, duration_ms), a key of detail
 * (detail.job) or a field of error (error.class), as a metric filter or a query names it.
 */
export function fieldExists(schema: LogSchema, path: string): boolean {
  const [head, key, ...rest] = path.split('.')

  if (head === undefined || rest.length > 0) {
    return false
  }

  if (key === undefined) {
    return schema.fields.some((field) => field.name === head)
  }

  if (head === 'detail') {
    return key in schema.detail
  }

  return schema.fields.some((field) => field.name === head && (field.fields ?? []).includes(key))
}
