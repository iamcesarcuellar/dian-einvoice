// Which failure mode applies to each request (src/modes.ts): the one of its NIT, else the one for
// every NIT ("*"), each limited to the next N requests and then back to normal. What each mode does to
// the answer lives in the server: server.test.ts.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { ALL, currentMode, normalMode, takeMode } from '../src/modes.ts'
import type { Mode } from '../src/state.ts'
import { emptyState } from '../src/state.ts'

const A = '990000001'
const B = '990000002'
const SEND = 'SendBillSync'
const STATUS = 'GetStatus'

interface Case {
  name: string
  /** The modes set, by NIT or "*"; remaining is the count of the admin API. */
  modes: Record<string, Partial<Mode>>
  /** Each request in order: its NIT (null when the document is unknown), the operation, and what applies (null: normal). */
  requests: Array<[nit: string | null, operation: string, applied: Partial<Mode> | null]>
  /** The modes still set afterwards. */
  left: string[]
}

const CASES: Case[] = [
  {
    name: 'normal when no mode is set',
    modes: {},
    requests: [[A, SEND, null], [null, STATUS, null]],
    left: [],
  },
  {
    name: 'reject, with its rule, only for SendBillSync: GetStatus neither gets it nor counts it',
    modes: { [A]: { mode: 'reject', rule: 'FAD06', remaining: 1 } },
    requests: [[A, STATUS, null], [A, SEND, { mode: 'reject', rule: 'FAD06' }], [A, SEND, null]],
    left: [],
  },
  {
    name: 'error_500 for one NIT, on both operations, until it is changed',
    modes: { [A]: { mode: 'error_500' } },
    requests: [[A, SEND, { mode: 'error_500' }], [A, STATUS, { mode: 'error_500' }], [B, SEND, null], [A, SEND, { mode: 'error_500' }]],
    left: [A],
  },
  {
    name: 'no_response for every NIT, also for a document the simulator does not know',
    modes: { [ALL]: { mode: 'no_response' } },
    requests: [[A, SEND, { mode: 'no_response' }], [null, STATUS, { mode: 'no_response' }]],
    left: [ALL],
  },
  {
    name: 'slow, with its delay',
    modes: { [A]: { mode: 'slow', delay_ms: 250 } },
    requests: [[A, STATUS, { mode: 'slow', delay_ms: 250 }]],
    left: [A],
  },
  {
    name: 'intermittent, with its failure rate',
    modes: { [A]: { mode: 'intermittent', failure_rate: 30 } },
    requests: [[A, SEND, { mode: 'intermittent', failure_rate: 30 }]],
    left: [A],
  },
  {
    name: 'the mode of the NIT before the one for every NIT',
    modes: { [ALL]: { mode: 'error_500' }, [A]: { mode: 'slow', delay_ms: 10 } },
    requests: [[A, SEND, { mode: 'slow' }], [B, SEND, { mode: 'error_500' }]],
    left: [ALL, A],
  },
  {
    name: 'limited to the next 2 requests of a NIT, then normal',
    modes: { [A]: { mode: 'error_500', remaining: 2 } },
    requests: [[A, SEND, { mode: 'error_500', remaining: 2 }], [A, STATUS, { mode: 'error_500', remaining: 1 }], [A, SEND, null]],
    left: [],
  },
  {
    name: 'limited to the next request of any NIT, then normal for all',
    modes: { [ALL]: { mode: 'no_response', remaining: 1 } },
    requests: [[B, SEND, { mode: 'no_response' }], [A, SEND, null], [null, STATUS, null]],
    left: [],
  },
  {
    name: 'when the limited mode of a NIT ends, the one for every NIT applies',
    modes: { [ALL]: { mode: 'slow', delay_ms: 5 }, [A]: { mode: 'error_500', remaining: 1 } },
    requests: [[A, SEND, { mode: 'error_500' }], [A, SEND, { mode: 'slow', delay_ms: 5 }]],
    left: [ALL],
  },
]

describe('failure modes', () => {
  for (const item of CASES) {
    it(item.name, () => {
      const state = emptyState()

      for (const [key, mode] of Object.entries(item.modes)) {
        state.modes[key] = { ...normalMode(), ...mode }
      }

      for (const [index, [nit, operation, expected]] of item.requests.entries()) {
        const applied = takeMode(state, nit, operation)
        const request = `request ${index + 1} (${operation} of ${nit ?? 'an unknown document'})`

        if (expected === null) {
          assert.equal(applied, null, `${request} must be normal`)
        } else {
          assert.ok(applied, `${request} must get ${expected.mode}`)
          assert.deepEqual(Object.fromEntries(Object.keys(expected).map((field) => [field, applied[field as keyof Mode]])), expected, request)
        }
      }

      assert.deepEqual(Object.keys(state.modes).sort(), [...item.left].sort())
    })
  }

  it('says whose mode a NIT sees', () => {
    const state = emptyState()
    assert.deepEqual([currentMode(state, A).scope, currentMode(state, A).mode], ['default', 'normal'])

    state.modes[ALL] = { ...normalMode(), mode: 'slow', delay_ms: 5 }
    state.modes[A] = { ...normalMode(), mode: 'error_500' }
    assert.deepEqual([currentMode(state, A).scope, currentMode(state, A).mode], ['company', 'error_500'])
    assert.deepEqual([currentMode(state, B).scope, currentMode(state, B).mode], ['all', 'slow'])
  })
})
