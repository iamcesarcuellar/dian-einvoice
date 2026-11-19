// Hostile input over HTTP: XML with a DTD (an external entity, entity expansion), zips that are too
// big, too many or badly named, admin bodies that are not JSON objects, a wrong admin token, and a
// simulator started without one. Every case is refused with a 4xx or a rejection, never a 500.
import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { once } from 'node:events'
import { randomBytes } from 'node:crypto'
import { join } from 'node:path'
import { after, before, describe, it } from 'node:test'
import { strToU8, zipSync } from 'fflate'
import { TAXPAYER, TOKEN, envelope, fixture, fixtureState, result, startSimulator } from './helpers.ts'
import type { Answer, Simulator } from './helpers.ts'

const SOAP = '/WcfDianCustomerServices.svc'
const NAME = `${TAXPAYER.nit.padStart(10, '0')}0002600000001`

/** SendBillSync of these zip entries, under the zip name of annex 1.9. */
function send(entries: Record<string, Uint8Array>): string {
  return envelope('SendBillSync', { fileName: `z${NAME}.zip`, contentFile: Buffer.from(zipSync(entries)).toString('base64') })
}

/** The invoice fixture with a DOCTYPE written before its root element. */
function withDoctype(doctype: string): Uint8Array {
  return strToU8(fixture('invoice').replace(/<Invoice\b/, `${doctype}<Invoice`))
}

const XXE = '<!DOCTYPE Invoice [<!ENTITY secret SYSTEM "file:///etc/hostname">]>'
const LAUGHS = `<!DOCTYPE Invoice [<!ENTITY a "aaaaaaaaaa">${['b', 'c', 'd', 'e', 'f', 'g', 'h'].map((name, index) => `<!ENTITY ${name} "${`&${'abcdefg'[index]};`.repeat(10)}">`).join('')}]>`

function rejected(answer: Answer, message: RegExp): void {
  assert.equal(answer.status, 200, answer.text)
  const { valid, messages } = result(answer)
  assert.equal(valid, false)
  assert.ok(messages.some((text) => message.test(text)), messages.join(' | '))
}

describe('hostile input', () => {
  let simulator: Simulator

  before(async () => {
    simulator = await startSimulator(fixtureState())
  })

  after(() => simulator.stop())

  it('an XML with an external entity is refused before any tool reads it', async () => {
    rejected(await simulator.post(SOAP, send({ [`fv${NAME}.xml`]: withDoctype(XXE) })), /not well formed/)
  })

  it('an XML with nested entities is refused at once, without expanding them', async () => {
    const answer = await simulator.post(SOAP, send({ [`fv${NAME}.xml`]: withDoctype(LAUGHS) }))
    rejected(answer, /not well formed/)
    assert.ok(answer.ms < 2000, `answered after ${Math.round(answer.ms)} ms`)
  })

  it('a SOAP envelope with a DTD is not an envelope', async () => {
    const answer = await simulator.post(SOAP, `<?xml version="1.0"?><!DOCTYPE x SYSTEM "http://127.0.0.1:9/x.dtd">${envelope('GetStatus', { trackId: 'f'.repeat(96) }).replace(/^<\?xml[^>]*>/, '')}`)
    assert.equal(answer.status, 400, answer.text)
  })

  it('a zip of more than ten files is refused', async () => {
    const entries = Object.fromEntries(Array.from({ length: 11 }, (_, index) => [`f${index}.txt`, strToU8('x')]))
    rejected(await simulator.post(SOAP, send(entries)), /más de 10 archivos/)
  })

  for (const name of ['../evil.xml', 'folder/evil.xml', 'folder\\evil.xml', '/evil.xml', 'c:evil.xml']) {
    it(`an entry named ${name} is refused`, async () => {
      rejected(await simulator.post(SOAP, send({ [name]: strToU8(fixture('invoice')) })), /no es válido/)
    })
  }

  it('an entry that expands beyond 10 MB is refused before it is uncompressed', async () => {
    // Zeros compress to a few kilobytes and declare their real size.
    rejected(await simulator.post(SOAP, send({ [`fv${NAME}.xml`]: new Uint8Array(11 * 1024 * 1024) })), /supera 10 MB por archivo/)
  })

  it('a zip larger than 10 MB is refused', async () => {
    rejected(await simulator.post(SOAP, send({ [`fv${NAME}.xml`]: randomBytes(10 * 1024 * 1024 + 1024) })), /supera 10 MB\./)
  })

  for (const body of ['null', '[1]', '"normal"', '42']) {
    it(`the admin API answers 400 to the body ${body}`, async () => {
      const answer = await simulator.post(`/admin/modes/${TAXPAYER.nit}`, body, { 'content-type': 'application/json', 'x-simulator-token': TOKEN })
      assert.equal(answer.status, 400, answer.text)
    })
  }

  it('the admin API reads an empty list, as PHP sends an empty array, as an empty body', async () => {
    // The reset the backend asks before loading the demo, with the body PHP writes for no data.
    const answer = await simulator.post('/admin/reset', '[]', { 'content-type': 'application/json', 'x-simulator-token': TOKEN })
    assert.equal(answer.status, 200, answer.text)
  })

  it('the admin API answers 400 to a body that is not JSON', async () => {
    const answer = await simulator.post(`/admin/taxpayers/${TAXPAYER.nit}`, '{"software":', { 'content-type': 'application/json', 'x-simulator-token': TOKEN })
    assert.equal(answer.status, 400, answer.text)
  })

  it('the admin API answers 401 to a wrong token', async () => {
    const answer = await simulator.post('/admin/reset', '{}', { 'content-type': 'application/json', 'x-simulator-token': `${TOKEN}x` })
    assert.equal(answer.status, 401, answer.text)
  })
})

describe('admin token', () => {
  it('the simulator does not start without one', async () => {
    const env: NodeJS.ProcessEnv = { ...process.env, PORT: '0', SIMULATOR_ADMIN_TOKEN: '' }
    delete env.NODE_TEST_CONTEXT

    const server = spawn(process.execPath, [join(import.meta.dirname, '..', 'src', 'server.ts')], { env, stdio: 'ignore' })
    const [code] = (await once(server, 'exit')) as [number | null]

    assert.equal(code, 1)
  })
})
