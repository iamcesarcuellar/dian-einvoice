// The signature check of the local webhook receiver, Standard Webhooks signed here on its own: a webhook
// signed with the registered secret verifies; another secret, a changed body, a timestamp outside the
// tolerance or no signature do not. The receiver answers with the result of the check.
import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import { after, before, describe, it } from 'node:test'
import { emptyState } from '../src/state.ts'
import { startSimulator } from './helpers.ts'
import type { Simulator } from './helpers.ts'

/** A synthetic company id: 26 letters and digits, like the ids of the backend. */
const COMPANY = '01jsynthetic00000000000000'
const SECRET = `whsec_${Buffer.from('synthetic webhook secret, test 1').toString('base64')}`
const OTHER_SECRET = `whsec_${Buffer.from('synthetic webhook secret, test 2').toString('base64')}`
const BODY = JSON.stringify({ id: 'evt_synthetic_1', type: 'document.accepted', data: { object: { id: 'doc_synthetic_1' } } })

function signature(secret: string, id: string, timestamp: number, body: string): string {
  const key = Buffer.from(secret.slice('whsec_'.length), 'base64')

  return `v1,${createHmac('sha256', key).update(`${id}.${timestamp}.${body}`).digest('base64')}`
}

const CASES = [
  { name: 'signed with the registered secret: verifies', secret: SECRET, ageSeconds: 0, sent: BODY, verification: 'valid' },
  { name: 'signed with another secret', secret: OTHER_SECRET, ageSeconds: 0, sent: BODY, verification: 'invalid_signature' },
  { name: 'a body changed after it was signed', secret: SECRET, ageSeconds: 0, sent: BODY.replace('accepted', 'rejected'), verification: 'invalid_signature' },
  { name: 'a timestamp six minutes old, outside the five of tolerance', secret: SECRET, ageSeconds: 6 * 60, sent: BODY, verification: 'timestamp_out_of_tolerance' },
  { name: 'no signature', secret: null, ageSeconds: 0, sent: BODY, verification: 'missing_headers' },
]

describe('webhook receiver', () => {
  let simulator: Simulator

  before(async () => {
    simulator = await startSimulator(emptyState())
    await simulator.admin('PUT', `/admin/webhook-receiver/${COMPANY}/secrets/sub_synthetic_1`, { secret: SECRET })
  })

  after(() => simulator.stop())

  for (const item of CASES) {
    it(item.name, async () => {
      const id = `msg_synthetic_${item.verification}`
      const timestamp = Math.floor(Date.now() / 1000) - item.ageSeconds
      const headers: Record<string, string> = { 'content-type': 'application/json', 'webhook-id': id, 'webhook-timestamp': String(timestamp) }

      if (item.secret !== null) {
        headers['webhook-signature'] = signature(item.secret, id, timestamp, BODY)
      }

      const answer = await simulator.post(`/webhook-receiver/${COMPANY}`, item.sent, headers)
      assert.equal(answer.status, 200, answer.text)
      assert.deepEqual(JSON.parse(answer.text), { received: true, verification: item.verification })
    })
  }
})
