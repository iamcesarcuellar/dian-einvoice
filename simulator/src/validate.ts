// The validations of a received invoice, each reported with the code and message of its annex rule:
// schema, the taxpayer's software and its security code, the numbering range and its validity, the
// CUFE recalculated with the technical key, the totals against the lines, and the signature and its
// certificate.
import { X509Certificate } from 'node:crypto'
import type { InvoiceData } from './invoice.ts'
import { cufeAmount, round, sha384 } from './money.ts'
import { finding } from './rules.ts'
import type { Finding } from './rules.ts'
import type { Range, State, Taxpayer } from './state.ts'
import { schemaErrors, signatureVerifies } from './tools.ts'

export async function validateInvoice(xml: string, invoice: InvoiceData, state: State, schema: string): Promise<Finding[]> {
  const errors = await schemaErrors(xml, schema)

  if (!invoice.isInvoice || errors.length > 0) {
    return [finding('ZB01', errors[0] ?? 'the document is not a UBL 2.1 Invoice')]
  }

  const taxpayer = invoice.supplierNit === undefined ? undefined : state.taxpayers[invoice.supplierNit]

  return [
    ...software(invoice, taxpayer),
    ...numbering(invoice, taxpayer),
    ...totals(invoice),
    ...(await signature(xml, invoice)),
  ]
}

function software(invoice: InvoiceData, taxpayer: Taxpayer | undefined): Finding[] {
  if (!taxpayer?.software || taxpayer.software.id !== invoice.softwareId) {
    return [finding('FAB24b')]
  }

  const expected = sha384(`${taxpayer.software.id}${taxpayer.software.pin}${invoice.number ?? ''}`)

  return expected === invoice.softwareSecurityCode?.toLowerCase() ? [] : [finding('FAB27b')]
}

function numbering(invoice: InvoiceData, taxpayer: Taxpayer | undefined): Finding[] {
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
 * Annex 1.9, section 11.2: SHA-384 of NumFac, FecFac, HorFac, ValFac, 01 and ValImp1 (IVA), 04
 * and ValImp2 (INC), 03 and ValImp3 (ICA), ValTot, NitOFE, NumAdq, ClTec and TipoAmbiente.
 */
function cufe(invoice: InvoiceData, range: Range): string {
  const tax = (code: string): string => cufeAmount(invoice.taxByCode.get(code) ?? 0n)

  return sha384([
    invoice.number ?? '',
    invoice.issueDate ?? '',
    invoice.issueTime ?? '',
    cufeAmount(invoice.lineExtension ?? 0n),
    '01', tax('01'),
    '04', tax('04'),
    '03', tax('03'),
    cufeAmount(invoice.payable ?? 0n),
    invoice.supplierNit ?? '',
    invoice.customerId ?? '',
    range.technical_key,
    invoice.environment ?? '',
  ].join(''))
}

/** FAU02, FAU04, FAU06 and FAU14, comparing rounded values as the annex formulas do. */
function totals(invoice: InvoiceData): Finding[] {
  const findings: Finding[] = []
  const lineExtension = invoice.lineExtension ?? 0n

  if (round(lineExtension) !== round(invoice.linesExtension)) {
    findings.push(finding('FAU02'))
  }

  if (round(invoice.taxExclusive ?? 0n) !== round(invoice.linesTaxable)) {
    findings.push(finding('FAU04'))
  }

  if (round(lineExtension + invoice.headerTaxes) !== round(invoice.taxInclusive ?? 0n)) {
    findings.push(finding('FAU06'))
  }

  const payable = (invoice.taxInclusive ?? 0n) - invoice.allowanceTotal + invoice.chargeTotal - invoice.prepaid

  if (round(payable) !== round(invoice.payable ?? 0n)) {
    findings.push(finding('FAU14'))
  }

  return findings
}

async function signature(xml: string, invoice: InvoiceData): Promise<Finding[]> {
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
