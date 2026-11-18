// What the validation reads from a UBL 2.1 invoice with the DIAN extensions (annex 1.9, section 6).
import { micros } from './money.ts'
import { attribute, child, children, descendants, NS, path, text } from './xml.ts'
import type { XmlDocument, XmlNode } from './xml.ts'

export interface InvoiceData {
  root: XmlNode
  isInvoice: boolean
  ublVersion?: string
  environment?: string
  number?: string
  cufe?: string
  issueDate?: string
  issueTime?: string
  supplierNit?: string
  supplierCheckDigit?: string
  supplierName?: string
  customerId?: string
  softwareId?: string
  softwareSecurityCode?: string
  authorization?: string
  periodStart?: string
  periodEnd?: string
  prefix?: string
  from?: string
  to?: string
  lineExtension: bigint | null
  taxExclusive: bigint | null
  taxInclusive: bigint | null
  allowanceTotal: bigint
  chargeTotal: bigint
  payable: bigint | null
  /** Header TaxTotal amounts by tax code (01 IVA, 04 INC, 03 ICA...). */
  taxByCode: Map<string, bigint>
  headerTaxes: bigint
  linesExtension: bigint
  linesTaxable: bigint
  prepaid: bigint
  signature?: XmlNode
  certificate?: string
  signingTime?: string
}

const cac = (name: string) => [NS.cac, name] as const
const cbc = (name: string) => [NS.cbc, name] as const
const sts = (name: string) => [NS.sts, name] as const

function sum(nodes: XmlNode[]): bigint {
  return nodes.reduce((total, node) => total + (micros(text(node)) ?? 0n), 0n)
}

export function readInvoice(document: XmlDocument): InvoiceData {
  const root = document.documentElement as XmlNode
  const extensions = children(child(root, NS.ext, 'UBLExtensions'), NS.ext, 'UBLExtension')
  const dian = extensions.map((extension) => path(extension, [NS.ext, 'ExtensionContent'], sts('DianExtensions'))).find(Boolean)
  const control = child(dian, NS.sts, 'InvoiceControl')
  const supplier = path(root, cac('AccountingSupplierParty'), cac('Party'), cac('PartyTaxScheme'))
  const customer = path(root, cac('AccountingCustomerParty'), cac('Party'), cac('PartyTaxScheme'))
  const totals = child(root, NS.cac, 'LegalMonetaryTotal')

  const taxByCode = new Map<string, bigint>()
  let headerTaxes = 0n

  for (const total of children(root, NS.cac, 'TaxTotal')) {
    const amount = micros(text(child(total, NS.cbc, 'TaxAmount'))) ?? 0n
    const code = text(path(total, cac('TaxSubtotal'), cac('TaxCategory'), cac('TaxScheme'), cbc('ID'))) ?? ''
    taxByCode.set(code, (taxByCode.get(code) ?? 0n) + amount)
    headerTaxes += amount
  }

  const lines = children(root, NS.cac, 'InvoiceLine')
  const linesTaxable = lines.reduce((total, line) => total + children(line, NS.cac, 'TaxTotal')
    .reduce((lineTotal, tax) => lineTotal + sum(children(tax, NS.cac, 'TaxSubtotal').map((subtotal) => child(subtotal, NS.cbc, 'TaxableAmount')).filter((node): node is XmlNode => node !== undefined)), 0n), 0n)

  const signature = descendants(root, NS.ds, 'Signature')[0]
  const companyId = child(supplier, NS.cbc, 'CompanyID')

  return {
    root,
    isInvoice: root.localName === 'Invoice' && root.namespaceURI === NS.invoice,
    ublVersion: text(child(root, NS.cbc, 'UBLVersionID')),
    environment: text(child(root, NS.cbc, 'ProfileExecutionID')),
    number: text(child(root, NS.cbc, 'ID')),
    cufe: text(child(root, NS.cbc, 'UUID')),
    issueDate: text(child(root, NS.cbc, 'IssueDate')),
    issueTime: text(child(root, NS.cbc, 'IssueTime')),
    supplierNit: text(companyId),
    supplierCheckDigit: attribute(companyId, 'schemeID'),
    supplierName: text(child(supplier, NS.cbc, 'RegistrationName')),
    customerId: text(child(customer, NS.cbc, 'CompanyID')),
    softwareId: text(path(dian, sts('SoftwareProvider'), sts('SoftwareID'))),
    softwareSecurityCode: text(child(dian, NS.sts, 'SoftwareSecurityCode')),
    authorization: text(child(control, NS.sts, 'InvoiceAuthorization')),
    periodStart: text(path(control, sts('AuthorizationPeriod'), cbc('StartDate'))),
    periodEnd: text(path(control, sts('AuthorizationPeriod'), cbc('EndDate'))),
    prefix: text(path(control, sts('AuthorizedInvoices'), sts('Prefix'))),
    from: text(path(control, sts('AuthorizedInvoices'), sts('From'))),
    to: text(path(control, sts('AuthorizedInvoices'), sts('To'))),
    lineExtension: micros(text(child(totals, NS.cbc, 'LineExtensionAmount'))),
    taxExclusive: micros(text(child(totals, NS.cbc, 'TaxExclusiveAmount'))),
    taxInclusive: micros(text(child(totals, NS.cbc, 'TaxInclusiveAmount'))),
    allowanceTotal: micros(text(child(totals, NS.cbc, 'AllowanceTotalAmount'))) ?? 0n,
    chargeTotal: micros(text(child(totals, NS.cbc, 'ChargeTotalAmount'))) ?? 0n,
    payable: micros(text(child(totals, NS.cbc, 'PayableAmount'))),
    taxByCode,
    headerTaxes,
    linesExtension: sum(lines.map((line) => child(line, NS.cbc, 'LineExtensionAmount')).filter((node): node is XmlNode => node !== undefined)),
    linesTaxable,
    prepaid: sum(children(root, NS.cac, 'PrepaidPayment').map((payment) => child(payment, NS.cbc, 'PaidAmount')).filter((node): node is XmlNode => node !== undefined)),
    signature,
    certificate: text(path(signature, [NS.ds, 'KeyInfo'], [NS.ds, 'X509Data'], [NS.ds, 'X509Certificate'])),
    signingTime: text(descendants(signature, NS.xades, 'SigningTime')[0]),
  }
}
