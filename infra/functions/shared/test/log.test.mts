// The logger of the TypeScript functions (log.mts) against the schema of the log lines of the platform, its single
// source of truth (backend/resources/observability/log-schema.json, which compose mounts at /workspace/observability).
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, it } from 'node:test'
import { correlationId, LINE_FIELDS, line, logger, redact } from '../log.mts'

interface Field { readonly name: string, readonly nullable?: boolean, readonly optional?: boolean, readonly values?: readonly string[], readonly pattern?: string }

const candidates = [process.env.LOG_SCHEMA ?? '', '/workspace/observability/log-schema.json', resolve(import.meta.dirname, '../../../../backend/resources/observability/log-schema.json')]
const file = candidates.find((candidate) => candidate !== '' && existsSync(candidate)) ?? assert.fail('The schema of the log lines is not mounted.')
const schema = JSON.parse(readFileSync(file, 'utf8')) as { correlation: { pattern: string }, fields: Field[], detail: Record<string, unknown>, events: Record<string, string> }
const required = schema.fields.filter((field) => field.optional !== true)

describe('the log line of the TypeScript functions', () => {
  it('holds exactly the fields of the schema, in their order, error and detail only when there is one', () => {
    assert.deepEqual([...LINE_FIELDS], required.map((field) => field.name))

    const bare = line('notifier', 'info', 'Something happened.')
    assert.deepEqual(Object.keys(bare), required.map((field) => field.name))

    const full = line('origin-verifier', 'error', 'It failed.', { event: 'edge.secret', outcome: 'failure', durationMs: 12.345, correlationId: correlationId(), requestId: 'b893ee23-9387-427e-8c3c-9b94d179104d', detail: { reason: 'no answer', state: 'ALARM', none: undefined }, error: new Error('connect ECONNREFUSED https://secretsmanager.us-east-1.amazonaws.com/') })
    assert.deepEqual(Object.keys(full), [...required.map((field) => field.name), 'error', 'detail'])
    assert.equal(full.duration_ms, 12.3)
    assert.deepEqual(full.detail, { reason: 'no answer', state: 'ALARM' })

    for (const field of required) {
      if (field.nullable !== true) {
        assert.notEqual(bare[field.name], null, `${field.name} is never null`)
      }

      if (field.values !== undefined && full[field.name] !== null) {
        assert.ok(field.values.includes(String(full[field.name])), `${field.name}: ${String(full[field.name])}`)
      }
    }

    assert.match(String(bare.time), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/)
    assert.equal(bare.actor, 'system')
    assert.match(String(bare.actor), new RegExp(schema.fields.find((field) => field.name === 'actor')?.pattern ?? '^$'))
  })

  it('writes events of the schema, detail it names, and its error masked', () => {
    for (const event of ['edge.refused', 'edge.secret', 'alarm.notification']) {
      assert.ok(event in schema.events, event)
    }

    for (const key of ['reason', 'alarm', 'state', 'previous_state', 'runbook']) {
      assert.ok(key in schema.detail, key)
    }

    const error = line('notifier', 'error', 'x', { error: new Error('POST https://hooks.chat.example/services/T0/B0/secret0000000000000000000000000 failed for ops@example.com') }).error as { class: string, message: string, trace: string[] }
    assert.equal(error.class, 'Error')
    assert.equal(error.message, 'POST [url] failed for [email]')
    assert.ok(error.trace.length <= 8)
  })

  it('gives correlation ids of the form of the schema, and one line per call', () => {
    const pattern = new RegExp(schema.correlation.pattern)

    for (let index = 0; index < 50; index++) {
      assert.match(correlationId(), pattern)
    }

    assert.ok(correlationId(Date.UTC(2026, 10, 20)) < correlationId(Date.UTC(2026, 10, 21)), 'They sort by time.')

    const written: string[] = []
    logger('notifier', (text) => written.push(text))('warning', 'Two\nlines', { event: 'alarm.notification' })
    assert.equal(written.length, 1)
    assert.ok(written[0]?.endsWith('}\n'))
    assert.equal((written[0]?.match(/\n/g) ?? []).length, 1)
    assert.equal((JSON.parse(written[0] ?? '{}') as { message: string }).message, 'Two lines')
  })

  it('masks addresses, emails and generated secrets, and keeps ids', () => {
    assert.equal(redact('See https://hooks.chat.example/a/b?c=d now'), 'See [url] now')
    assert.equal(redact('Mail ops@example.com'), 'Mail [email]')
    assert.equal(redact('Value Xy7Qz2Lm9Np4Rs8Tu1Vw6Ab3Cd5Ef0GhJk here'), 'Value [redacted] here')
    assert.equal(redact('Request b893ee23-9387-427e-8c3c-9b94d179104d'), 'Request b893ee23-9387-427e-8c3c-9b94d179104d')
    assert.equal(redact('abcdef', 4), 'abc…')
  })
})
