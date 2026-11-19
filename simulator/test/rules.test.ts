// Every rule of src/rules.ts on the invoice, credit note and debit note: the signed fixtures pass with
// no rejection, and each defect, written into the document or into the state of the simulator, raises
// its rule. The clock is fixed inside the validity of the fixture certificate, so the checks hold at
// any date. Rule 90 lives in the server: server.test.ts checks it over HTTP.
import assert from 'node:assert/strict'
import { after, before, describe, it, mock } from 'node:test'
import type { DocumentData, DocumentKind } from '../src/document.ts'
import { RULES, rule } from '../src/rules.ts'
import type { Finding } from '../src/rules.ts'
import type { DocumentRecord, Range, State } from '../src/state.ts'
import { validateDocument } from '../src/validate.ts'
import { KINDS, SCHEMAS, TAXPAYER, change, fixture, fixtureState, read } from './helpers.ts'

/** Inside the validity of the fixture certificate, 2026-05-03 to 2028-05-03. */
const CLOCK = Date.parse('2026-11-19T12:00:00Z')
const CERTIFICATE_EXPIRED = Date.parse('2028-05-04T00:00:00Z')

interface Case {
  /** The rule each kind of document breaks with the defect. */
  codes: Partial<Record<DocumentKind, string>>
  defect: string
  /** The defect written into the document; the edit breaks its signature too (ZE02). */
  edit?: (xml: string) => string
  /** The defect in what the simulator knows. */
  state?: (state: State, document: DocumentData) => void
  /** The time of the check, when the defect is a date. */
  now?: number
}

/** The rule of each kind, numbered by the annex with F, C or D before the rest of the code. */
const each = (rest: string): Case['codes'] => ({ invoice: `F${rest}`, credit_note: `C${rest}`, debit_note: `D${rest}` })
const notes = (rest: string): Case['codes'] => ({ credit_note: `C${rest}`, debit_note: `D${rest}` })
const every = (code: string): Case['codes'] => ({ invoice: code, credit_note: code, debit_note: code })

function software(state: State): { id: string, pin: string } {
  const registered = state.taxpayers[TAXPAYER.nit]?.software
  assert.ok(registered)

  return registered
}

function range(state: State): Range {
  const registered = state.taxpayers[TAXPAYER.nit]?.ranges[0]
  assert.ok(registered)

  return registered
}

function referenced(state: State, note: DocumentData): DocumentRecord {
  const invoice = state.documents[note.reference?.cufe?.toLowerCase() ?? '']
  assert.ok(invoice)

  return invoice
}

const plusOne = (amount: string): string => String(Number(amount) + 1)
const nextDay = (day: string): string => new Date(Date.parse(`${day}T00:00:00Z`) + 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

const CASES: Case[] = [
  { codes: every('ZB01'), defect: 'an element the XSD does not allow', edit: (xml) => change(xml, /<cbc:UBLVersionID>/, (match) => `<cbc:Unexpected>1</cbc:Unexpected>${match}`) },
  { codes: each('AB24b'), defect: 'the software ID is not the one of the issuer', state: (state) => { software(state).id = '00000000-0000-4000-8000-000000000000' } },
  { codes: each('AB27b'), defect: 'the security code was made with another PIN', state: (state) => { software(state).pin = '99999' } },
  { codes: { invoice: 'FAB05b' }, defect: 'a resolution the issuer does not have', state: (state) => { range(state).resolution_number = '18760000099' } },
  { codes: { invoice: 'FAB07b' }, defect: 'the range starts on another day', state: (state) => { range(state).valid_from = '2019-01-20' } },
  { codes: { invoice: 'FAB08b' }, defect: 'the range ends on another day', state: (state) => { range(state).valid_to = '2030-01-20' } },
  { codes: { invoice: 'FAB10b' }, defect: 'the range has another prefix', state: (state) => { range(state).prefix = 'SETT' } },
  { codes: { invoice: 'FAB11b' }, defect: 'the range has another first number', state: (state) => { range(state).from = 989_999_999 } },
  { codes: { invoice: 'FAB12b' }, defect: 'the range has another last number', state: (state) => { range(state).to = 995_000_001 } },
  { codes: { invoice: 'FAD05b' }, defect: 'the number is below the range', state: (state) => { range(state).from = 990_000_001 } },
  { codes: { invoice: 'FAD05c' }, defect: 'the number is above the range', state: (state) => { range(state).to = 989_999_999 } },
  { codes: each('AD06'), defect: 'cbc:UUID is not the CUFE or CUDE of the document', edit: (xml) => change(xml, /(<cbc:UUID[^>]*>)([0-9a-f])/, (_, start, first) => `${start}${first === '0' ? '1' : '0'}`) },
  { codes: { invoice: 'FAD09a' }, defect: 'issued before the range is valid', state: (state) => { range(state).valid_from = '2026-06-05' } },
  { codes: { invoice: 'FAD09b' }, defect: 'issued after the range expired', state: (state) => { range(state).valid_to = '2026-06-03' } },
  { codes: each('AD09e'), defect: 'signed the day after its issue date', edit: (xml) => change(xml, /(<xades:SigningTime>)(\d{4}-\d{2}-\d{2})/, (_, start, day) => `${start}${nextDay(day)}`) },
  { codes: each('AU02'), defect: 'a line amount that LineExtensionAmount leaves out', edit: (xml) => change(xml, /(<cac:(?:Invoice|CreditNote|DebitNote)Line>[\s\S]*?<cbc:LineExtensionAmount[^>]*>)(\d+)/, (_, start, amount) => `${start}${plusOne(amount)}`) },
  { codes: each('AU04'), defect: 'TaxExclusiveAmount is not the sum of the taxable amounts', edit: (xml) => change(xml, /(<cbc:TaxExclusiveAmount[^>]*>)(\d+)/, (_, start, amount) => `${start}${plusOne(amount)}`) },
  { codes: each('AU06'), defect: 'TaxInclusiveAmount is not LineExtensionAmount plus the taxes', edit: (xml) => change(xml, /(<cbc:TaxInclusiveAmount[^>]*>)(\d+)/, (_, start, amount) => `${start}${plusOne(amount)}`) },
  { codes: each('AU14'), defect: 'a charge that PayableAmount leaves out', edit: (xml) => change(xml, /<\/cbc:TaxInclusiveAmount>/, (match) => `${match}<cbc:ChargeTotalAmount currencyID="COP">1.00</cbc:ChargeTotalAmount>`) },
  { codes: notes('BG01'), defect: 'no BillingReference to the invoice it corrects', edit: (xml) => change(xml, /<cac:BillingReference>[\s\S]*?<\/cac:BillingReference>/, () => '') },
  { codes: notes('BG03'), defect: 'the referenced invoice has another number', state: (state, note) => { referenced(state, note).number = 'SETP990000099' } },
  { codes: notes('BG04'), defect: 'an invoice the simulator never accepted', state: (state, note) => { delete state.documents[referenced(state, note).cufe] } },
  { codes: { credit_note: 'CBG05' }, defect: 'the referenced UUID is not a CUFE-SHA384', edit: (xml) => change(xml, /(<cac:BillingReference>[\s\S]*?schemeName=")CUFE-SHA384/, (_, start) => `${start}CUFE-SHA256`) },
  { codes: notes('BG06'), defect: 'the referenced invoice is dated after the note', edit: (xml) => change(xml, /(<cac:BillingReference>[\s\S]*?<cbc:IssueDate>)[^<]+/, (_, start) => `${start}2099-12-31`) },
  { codes: every('ZD01'), defect: 'a signature without its certificate', edit: (xml) => change(xml, /<ds:KeyInfo[\s\S]*?<\/ds:KeyInfo>/, () => '') },
  { codes: every('ZE02'), defect: 'a signature value that does not verify', edit: (xml) => change(xml, /(<ds:SignatureValue[^>]*>\s*)(\S)/, (_, start, first) => `${start}${first === 'A' ? 'B' : 'A'}`) },
  { codes: every('ZD02'), defect: 'the certificate expired by now', now: CERTIFICATE_EXPIRED },
  { codes: every('ZD05'), defect: 'the synthetic self-signed test certificate of every fixture' },
]

async function findings(kind: DocumentKind, item?: Case): Promise<Finding[]> {
  const xml = item?.edit?.(fixture(kind)) ?? fixture(kind)
  const document = read(xml)
  const state = fixtureState()
  item?.state?.(state, document)
  mock.timers.setTime(item?.now ?? CLOCK)

  return validateDocument(xml, document, state, SCHEMAS)
}

const rejections = (list: Finding[]): Finding[] => list.filter((item) => item.kind === 'rejection')
const summary = (list: Finding[]): string => list.map((item) => `${item.code ?? '-'} (${item.kind})`).join(', ') || 'no findings'
const named = (kind: DocumentKind): string => kind.replace('_', ' ')

describe('validation rules', () => {
  before(() => mock.timers.enable({ apis: ['Date'], now: CLOCK }))
  after(() => mock.timers.reset())

  for (const kind of KINDS) {
    it(`accepts the correct ${named(kind)}`, async () => {
      const list = await findings(kind)
      assert.deepEqual(rejections(list), [], summary(list))
    })
  }

  for (const item of CASES) {
    for (const [kind, code] of Object.entries(item.codes) as Array<[DocumentKind, string]>) {
      it(`${code} on the ${named(kind)}: ${item.defect}`, async () => {
        const list = await findings(kind, item)
        const found = list.find((candidate) => candidate.code === code)
        assert.ok(found, `${code} is missing: ${summary(list)}`)
        assert.equal(found.kind, rule(code)?.kind, `${code} must be a ${rule(code)?.kind}`)

        if (found.kind === 'notification' && item.edit === undefined) {
          assert.deepEqual(rejections(list), [], `a notification alone does not reject: ${summary(list)}`)
        }
      })
    }
  }

  it('has a case for every rule but 90', () => {
    const covered = new Set(CASES.flatMap((item) => Object.values(item.codes)))
    assert.deepEqual(RULES.map((item) => item.code).filter((code) => code !== '90' && !covered.has(code)), [])
  })
})
