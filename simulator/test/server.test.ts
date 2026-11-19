// What lives in the HTTP server: rule 90, which looks at the documents the simulator already accepted,
// and what each failure mode does to the answer, set through the admin API for one NIT or for every NIT
// ("*") and limited to the next N requests. The simulator runs as a process of its own.
import assert from 'node:assert/strict'
import { after, before, describe, it } from 'node:test'
import { ALL } from '../src/modes.ts'
import { TAXPAYER, acceptedInvoice, change, envelope, fixture, fixtureState, read, result, sendBillSync, startSimulator } from './helpers.ts'
import type { Answer, Simulator } from './helpers.ts'

const SOAP = '/WcfDianCustomerServices.svc'
const NIT = TAXPAYER.nit
const DELAY_MS = 200
const invoice = read(fixture('invoice'))

/** GetStatus of a document of the NIT: no validation, and the mode of its NIT applies. */
const known = (): string => envelope('GetStatus', { trackId: invoice.cufe ?? '' })
/** GetStatus of a document the simulator does not know: only the mode for every NIT applies. */
const unknown = (): string => envelope('GetStatus', { trackId: 'f'.repeat(96) })
const creditNote = (): string => sendBillSync(fixture('credit_note'), 'credit_note')

const ruled = (answer: Answer, code: string): boolean => result(answer).messages.some((message) => message.startsWith(`Regla: ${code},`))

type Check = (answer: Answer) => void

const answered: Check = (answer) => assert.equal(answer.status, 200, answer.text)
const prompt: Check = (answer) => {
  answered(answer)
  assert.ok(answer.ms < DELAY_MS, `answered after ${Math.round(answer.ms)} ms`)
}
const late: Check = (answer) => {
  answered(answer)
  // Timers may fire a millisecond early.
  assert.ok(answer.ms >= DELAY_MS - 5, `answered after ${Math.round(answer.ms)} ms`)
}
const withheld: Check = (answer) => assert.equal(answer.status, 'timeout', answer.text)
const internalError: Check = (answer) => {
  assert.equal(answer.status, 500)
  assert.match(answer.text, /<s:Value>s:Receiver<\/s:Value>/)
}
const failed: Check = (answer) => assert.ok(answer.status === 500 || answer.status === 'reset', `answered ${answer.status}`)
const rejectedByCad06: Check = (answer) => {
  answered(answer)
  assert.equal(result(answer).valid, false)
  assert.ok(ruled(answer, 'CAD06'), result(answer).messages.join(' | '))
}
const withoutCad06: Check = (answer) => {
  answered(answer)
  assert.ok(!ruled(answer, 'CAD06'), result(answer).messages.join(' | '))
}

interface ModeCase {
  name: string
  /** A NIT or "*". */
  scope: string
  /** The body of PUT /admin/modes/{scope}. */
  mode: Record<string, unknown>
  request: () => string
  /** One check per request, in order; the last one shows the mode is over. */
  checks: Check[]
  /** How long a request may wait for its answer. */
  timeoutMs?: number
}

const MODES: ModeCase[] = [
  { name: 'normal: answered at once', scope: NIT, mode: { mode: 'normal' }, request: known, checks: [prompt] },
  { name: 'reject for the NIT, once: a correct document gets the rule', scope: NIT, mode: { mode: 'reject', rule: 'CAD06', count: 1 }, request: creditNote, checks: [rejectedByCad06, withoutCad06] },
  { name: 'no_response for the NIT, once: processed but never answered', scope: NIT, mode: { mode: 'no_response', count: 1 }, request: known, checks: [withheld, answered], timeoutMs: 400 },
  { name: 'error_500 for every NIT, once: HTTP 500 with a SOAP fault', scope: ALL, mode: { mode: 'error_500', count: 1 }, request: unknown, checks: [internalError, answered] },
  { name: 'slow for the NIT, twice: answered after the delay', scope: NIT, mode: { mode: 'slow', delay_ms: DELAY_MS, count: 2 }, request: known, checks: [late, late, prompt] },
  { name: 'intermittent at 100 % for the NIT, twice: HTTP 500 or a dropped connection', scope: NIT, mode: { mode: 'intermittent', failure_rate: 100, count: 2 }, request: known, checks: [failed, failed, answered] },
]

describe('SOAP service', () => {
  let simulator: Simulator

  before(async () => {
    const state = fixtureState()
    // The invoice fixture was accepted before.
    const accepted = acceptedInvoice(invoice.cufe, invoice.number, invoice.issueDate)
    state.documents[accepted.cufe] = accepted
    simulator = await startSimulator(state)
  })

  after(() => simulator.stop())

  const RULE_90 = [
    { name: 'the same document again', request: () => sendBillSync(fixture('invoice'), 'invoice'), rejected: true },
    { name: 'another document with an accepted number', request: () => sendBillSync(change(fixture('invoice'), /(<cbc:UUID[^>]*>)([0-9a-f])/, (_, start, first) => `${start}${first === '0' ? '1' : '0'}`), 'invoice'), rejected: true },
    { name: 'a document with a number not used yet', request: () => sendBillSync(fixture('debit_note'), 'debit_note'), rejected: false },
  ]

  for (const item of RULE_90) {
    it(`rule 90: ${item.name}`, async () => {
      const answer = await simulator.post(SOAP, item.request())
      answered(answer)
      assert.equal(ruled(answer, '90'), item.rejected, result(answer).messages.join(' | '))
    })
  }

  it('rule 90: GetStatus still returns the accepted document', async () => {
    const answer = await simulator.post(SOAP, known())
    answered(answer)
    assert.equal(result(answer).valid, true)
  })

  for (const item of MODES) {
    it(`failure mode ${item.name}`, async () => {
      await simulator.admin('PUT', `/admin/modes/${item.scope}`, item.mode)

      try {
        for (const check of item.checks) {
          check(await simulator.post(SOAP, item.request(), undefined, item.timeoutMs))
        }
      } finally {
        await simulator.admin('PUT', `/admin/modes/${item.scope}`, { mode: 'normal' })
      }
    })
  }
})
