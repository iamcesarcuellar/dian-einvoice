// One simulator for the local stack and the emulated cloud, with each one's records apart (src/stacks.ts): a number
// the local stack sent is free in the cloud, and the other way round; a failure mode, the activity log, a reset and
// the webhook receiver stay in their stack; and each stack saves its records to its own file. So going from make up
// to make cloud-up and back never makes the simulator reject a new document with rule 90.
import assert from 'node:assert/strict'
import { after, before, describe, it } from 'node:test'
import { ALL } from '../src/modes.ts'
import { stackOf } from '../src/stacks.ts'
import type { RequestLog } from '../src/state.ts'
import { TAXPAYER, acceptedInvoice, envelope, fixture, fixtureState, read, result, sendBillSync, startSimulator } from './helpers.ts'
import type { Answer, Simulator } from './helpers.ts'

const NIT = TAXPAYER.nit
const LOCAL = '/WcfDianCustomerServices.svc'
const CLOUD = '/cloud/WcfDianCustomerServices.svc'
/** A synthetic company id: 26 letters and digits, like the ids of the backend. */
const COMPANY = '01jsyntheticstack000000000'
const invoice = read(fixture('invoice'))

const ruled = (answer: Answer, code: string): boolean => result(answer).messages.some((message) => message.startsWith(`Regla: ${code},`))
const accepted = (answer: Answer): void => {
  assert.equal(answer.status, 200, answer.text)
  assert.equal(result(answer).valid, true, result(answer).messages.join(' | '))
  assert.ok(!ruled(answer, '90'))
}
const repeated = (answer: Answer): void => {
  assert.equal(answer.status, 200, answer.text)
  assert.equal(result(answer).valid, false)
  assert.ok(ruled(answer, '90'), result(answer).messages.join(' | '))
}
const getStatus = (): string => envelope('GetStatus', { trackId: invoice.cufe ?? '' })

describe('the stack of a path', () => {
  const CASES: [string, ReturnType<typeof stackOf>][] = [
    ['/WcfDianCustomerServices.svc', { name: 'local', path: '/WcfDianCustomerServices.svc' }],
    ['/admin/modes/900123456', { name: 'local', path: '/admin/modes/900123456' }],
    ['/cloud/WcfDianCustomerServices.svc', { name: 'cloud', path: '/WcfDianCustomerServices.svc' }],
    ['/cloud/admin/documents', { name: 'cloud', path: '/admin/documents' }],
    ['/CLOUD/health', { name: 'cloud', path: '/health' }],
    ['/cloud', { name: 'cloud', path: '/' }],
    ['/cloudy/health', { name: 'local', path: '/cloudy/health' }],
  ]

  for (const [path, expected] of CASES) {
    it(`${path}: ${expected.name}, ${expected.path}`, () => assert.deepEqual(stackOf(path), expected))
  }
})

describe('each stack keeps its own records', () => {
  let simulator: Simulator

  before(async () => {
    // The local stack accepted the invoice fixture; the emulated cloud, with the same taxpayer and the same
    // numbering, has not sent it yet.
    const local = fixtureState()
    const invoiceRecord = acceptedInvoice(invoice.cufe, invoice.number, invoice.issueDate)
    local.documents[invoiceRecord.cufe] = invoiceRecord
    simulator = await startSimulator(local, fixtureState())
  })

  after(() => simulator.stop())

  it('accepts in the emulated cloud a number the local stack already sent', async () => {
    accepted(await simulator.post(CLOUD, sendBillSync(fixture('invoice'), 'invoice')))
  })

  it('still rejects the same document again with rule 90, in each stack', async () => {
    repeated(await simulator.post(LOCAL, sendBillSync(fixture('invoice'), 'invoice')))
    repeated(await simulator.post(CLOUD, sendBillSync(fixture('invoice'), 'invoice')))
  })

  it('lists in each stack only its own activity', async () => {
    const { requests: local } = await simulator.admin('GET', `/admin/requests?nit=${NIT}`) as { requests: RequestLog[] }
    const { requests: cloud } = await simulator.admin('GET', `/cloud/admin/requests?nit=${NIT}`) as { requests: RequestLog[] }
    assert.deepEqual(local.map((entry) => entry.result), ['duplicate'])
    assert.deepEqual(cloud.map((entry) => entry.result), ['duplicate', 'accepted'])
  })

  it('applies a failure mode only to the stack it was set in', async () => {
    await simulator.admin('PUT', `/cloud/admin/modes/${encodeURIComponent(ALL)}`, { mode: 'error_500' })

    try {
      assert.equal((await simulator.post(CLOUD, getStatus())).status, 500)
      assert.equal((await simulator.post(LOCAL, getStatus())).status, 200)
      assert.equal((await simulator.admin('GET', `/admin/modes/${NIT}`) as { scope: string }).scope, 'default')
      assert.equal((await simulator.admin('GET', `/cloud/admin/modes/${NIT}`) as { scope: string }).scope, 'all')
    } finally {
      await simulator.admin('PUT', `/cloud/admin/modes/${encodeURIComponent(ALL)}`, { mode: 'normal' })
    }
  })

  it('keeps the webhooks of each stack in its own receiver', async () => {
    const answer = await simulator.post(`/cloud/webhook-receiver/${COMPANY}`, '{}', { 'content-type': 'application/json' })
    assert.equal(answer.status, 200, answer.text)
    const cloud = await simulator.admin('GET', `/cloud/admin/webhook-receiver/${COMPANY}`) as { received: unknown[] }
    const local = await simulator.admin('GET', `/admin/webhook-receiver/${COMPANY}`) as { received: unknown[] }
    assert.equal(cloud.received.length, 1)
    assert.equal(local.received.length, 0)
  })

  it('resets only the stack it is asked to, and saves each stack to its own file', async () => {
    await simulator.admin('POST', '/cloud/admin/reset')
    const { documents: cloud } = await simulator.admin('GET', '/cloud/admin/documents') as { documents: unknown[] }
    const { documents: local } = await simulator.admin('GET', `/admin/documents?nit=${NIT}`) as { documents: { number: string }[] }
    assert.deepEqual(cloud, [])
    assert.ok(local.some((document) => document.number === invoice.number), 'the local stack keeps its invoice')
    assert.deepEqual(Object.keys((await simulator.saved('cloud/state.json')).documents), [])
    assert.ok(Object.keys((await simulator.saved('state.json')).documents).includes(invoice.cufe?.toLowerCase() ?? ''))
  })

  it('counts its own records in the health of each stack, which names where the others are', async () => {
    const local = await simulator.get('/health') as { stack: string, documents: number, stacks: Record<string, string> }
    const cloud = await simulator.get('/cloud/health') as { stack: string, documents: number }
    assert.equal(local.stack, 'local')
    assert.equal(cloud.stack, 'cloud')
    assert.ok(local.documents > 0)
    assert.equal(cloud.documents, 0)
    assert.deepEqual(local.stacks, { local: '/health', cloud: '/cloud/health' })
  })
})
