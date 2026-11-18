// The validations of a received invoice, credit note or debit note, each reported with the code and
// message of its annex rule: schema, the taxpayer's software and its security code; for an invoice
// the numbering range and its validity and the CUFE recalculated with the technical key; for a note
// the CUDE recalculated with the PIN of the software and the invoice it corrects, which must be one
// this simulator accepted for the same issuer; then the totals against the lines, and the signature
// and its certificate.
import { X509Certificate } from 'node:crypto'
import type { DocumentData, DocumentKind } from './document.ts'
import { cufeAmount, round, sha384 } from './money.ts'
import { finding } from './rules.ts'
import type { Finding } from './rules.ts'
import type { Range, State, Taxpayer } from './state.ts'
import { schemaErrors, signatureVerifies } from './tools.ts'

/** Prefix of the rule codes of each kind: FA/FB for invoices, CA/CB credit notes, DA/DB debit notes. */
const LETTER: Record<DocumentKind, string> = { invoice: 'F', credit_note: 'C', debit_note: 'D' }

/** schemes: the path of the XSD of each kind. */
export async function validateDocument(xml: string, document: DocumentData, state: State, schemas: Record<DocumentKind, string>): Promise<Finding[]> {
  if (document.kind === null) {
    return [finding('ZB01', 'the document is not a UBL 2.1 Invoice, CreditNote or DebitNote')]
  }

  const errors = await schemaErrors(xml, schemas[document.kind])

  if (errors.length > 0) {
    return [finding('ZB01', errors[0])]
  }

  const kind = document.kind
  const taxpayer = document.supplierNit === undefined ? undefined : state.taxpayers[document.supplierNit]

  return [
    ...software(document, kind, taxpayer),
    ...(kind === 'invoice' ? numbering(document, taxpayer) : [...cude(document, kind, taxpayer), ...reference(document, kind, state)]),
    ...totals(document, kind),
    ...(await signature(xml, document)),
  ]
}

function software(invoice: DocumentData, kind: DocumentKind, taxpayer: Taxpayer | undefined): Finding[] {
  const letter = LETTER[kind]

  if (!taxpayer?.software || taxpayer.software.id !== invoice.softwareId) {
    return [finding(`${letter}AB24b`)]
  }

  const expected = sha384(`${taxpayer.software.id}${taxpayer.software.pin}${invoice.number ?? ''}`)

  return expected === invoice.softwareSecurityCode?.toLowerCase() ? [] : [finding(`${letter}AB27b`)]
}

function numbering(invoice: DocumentData, taxpayer: Taxpayer | undefined): Finding[] {
  const range = taxpayer?.ranges.find((candidate) => candidate.resolution_number === invoice.authorization)

  if (!range) {
    return [finding('FAB05b')]
  }

  const findings: Finding[] = []
  const prefix = invoice.prefix ?? ''
  const number = invoice.number ?? ''

  if (prefix !== range.prefix || !number.startsWith(prefix)) {
    findings.push(finding('FAB10b'))
  }

  if (invoice.from !== String(range.from)) {
    findings.push(finding('FAB11b'))
  }

  if (invoice.to !== String(range.to)) {
    findings.push(finding('FAB12b'))
  }

  if (range.valid_from !== null && invoice.periodStart !== range.valid_from) {
    findings.push(finding('FAB07b'))
  }

  if (range.valid_to !== null && invoice.periodEnd !== range.valid_to) {
    findings.push(finding('FAB08b'))
  }

  const consecutive = /^\d+$/.test(number.slice(prefix.length)) ? Number(number.slice(prefix.length)) : Number.NaN

  if (Number.isNaN(consecutive) || consecutive < range.from) {
    findings.push(finding('FAD05b'))
  } else if (consecutive > range.to) {
    findings.push(finding('FAD05c'))
  }

  const issueDate = invoice.issueDate ?? ''

  if (range.valid_from !== null && issueDate < range.valid_from) {
    findings.push(finding('FAD09a'))
  }

  if (range.valid_to !== null && issueDate > range.valid_to) {
    findings.push(finding('FAD09b'))
  }

  if (cufe(invoice, range) !== invoice.cufe?.toLowerCase()) {
    findings.push(finding('FAD06'))
  }

  return findings
}

/**
 * Annex 1.9, sections 11.2 and 11.4: SHA-384 of NumDoc, FecDoc, HorDoc, ValDoc, 01 and ValImp1
 * (IVA), 04 and ValImp2 (INC), 03 and ValImp3 (ICA), ValTot, NitOFE, NumAdq, then the technical key
 * of the range (CUFE of an invoice) or the PIN of the software (CUDE of a note), and TipoAmbiente.
 */
function documentKey(document: DocumentData, secret: string): string {
  const tax = (code: string): string => cufeAmount(document.taxByCode.get(code) ?? 0n)

  return sha384([
    document.number ?? '',
    document.issueDate ?? '',
    document.issueTime ?? '',
    cufeAmount(document.lineExtension ?? 0n),
    '01', tax('01'),
    '04', tax('04'),
    '03', tax('03'),
    cufeAmount(document.payable ?? 0n),
    document.supplierNit ?? '',
    document.customerId ?? '',
    secret,
    document.environment ?? '',
  ].join(''))
}

function cufe(invoice: DocumentData, range: Range): string {
  return documentKey(invoice, range.technical_key)
}

/** CAD06 and DAD06: the CUDE recalculated with the PIN of the software registered for the issuer. */
function cude(note: DocumentData, kind: DocumentKind, taxpayer: Taxpayer | undefined): Finding[] {
  const pin = taxpayer?.software?.pin

  return pin !== undefined && documentKey(note, pin) === note.cufe?.toLowerCase() ? [] : [finding(`${LETTER[kind]}AD06`)]
}

/**
 * CBG01 to CBG06 and DBG01 to DBG06: the note references, by number, CUFE and date, an invoice this
 * simulator accepted for the same issuer. A rejected invoice, or one of another issuer, does not
 * exist for the note.
 */
function reference(note: DocumentData, kind: DocumentKind, state: State): Finding[] {
  const letter = LETTER[kind]
  const referenced = note.reference

  if (!referenced?.cufe) {
    return [finding(`${letter}BG01`)]
  }

  const findings: Finding[] = []

  if (kind === 'credit_note' && referenced.scheme !== 'CUFE-SHA384') {
    findings.push(finding('CBG05'))
  }

  const invoice = state.documents[referenced.cufe.toLowerCase()]

  if (!invoice || (invoice.kind ?? 'invoice') !== 'invoice' || invoice.status !== 'accepted' || invoice.nit !== note.supplierNit) {
    findings.push(finding(`${letter}BG04`))

    return findings
  }

  if (referenced.id !== invoice.number) {
    findings.push(finding(`${letter}BG03`))
  }

  const invoiceDate = referenced.issueDate ?? invoice.issue_date ?? ''

  if (invoiceDate > (note.issueDate ?? '')) {
    findings.push(finding(`${letter}BG06`))
  }

  return findings
}

/** FAU02, FAU04, FAU06 and FAU14 (CAU and DAU for the notes), comparing rounded values as the annex formulas do. */
function totals(document: DocumentData, kind: DocumentKind): Finding[] {
  const findings: Finding[] = []
  const letter = LETTER[kind]
  const lineExtension = document.lineExtension ?? 0n

  if (round(lineExtension) !== round(document.linesExtension)) {
    findings.push(finding(`${letter}AU02`))
  }

  if (round(document.taxExclusive ?? 0n) !== round(document.linesTaxable)) {
    findings.push(finding(`${letter}AU04`))
  }

  if (round(lineExtension + document.headerTaxes) !== round(document.taxInclusive ?? 0n)) {
    findings.push(finding(`${letter}AU06`))
  }

  const payable = (document.taxInclusive ?? 0n) - document.allowanceTotal + document.chargeTotal - document.prepaid

  if (round(payable) !== round(document.payable ?? 0n)) {
    findings.push(finding(`${letter}AU14`))
  }

  return findings
}

async function signature(xml: string, invoice: DocumentData): Promise<Finding[]> {
  if (!invoice.signature || !invoice.certificate) {
    return [finding('ZD01')]
  }

  const base64 = invoice.certificate.replace(/\s+/g, '')
  const pem = `-----BEGIN CERTIFICATE-----\n${(base64.match(/.{1,64}/g) ?? []).join('\n')}\n-----END CERTIFICATE-----\n`
  let certificate: X509Certificate

  try {
    certificate = new X509Certificate(pem)
  } catch {
    return [finding('ZD01', 'the X509Certificate cannot be read')]
  }

  const findings: Finding[] = []
  const verification = await signatureVerifies(xml, pem)

  if (!verification.ok) {
    findings.push(finding('ZE02', verification.detail || undefined))
  }

  const validFrom = new Date(certificate.validFrom)
  const validTo = new Date(certificate.validTo)
  const signedAt = invoice.signingTime === undefined ? Number.NaN : Date.parse(invoice.signingTime)
  const now = Date.now()

  if (Number.isNaN(signedAt) || signedAt < validFrom.getTime() || signedAt > validTo.getTime() || now < validFrom.getTime() || now > validTo.getTime()) {
    findings.push(finding('ZD02'))
  }

  if (certificate.checkIssued(certificate) && certificate.verify(certificate.publicKey)) {
    findings.push(finding('ZD05'))
  }

  return findings
}
