// What the validation reads from a UBL 2.1 Invoice, CreditNote or DebitNote with the DIAN extensions
// (annex 1.9, sections 6.1 to 6.3).
import { join } from 'node:path'
import { micros } from './money.ts'
import { attribute, child, children, descendants, NS, path, text } from './xml.ts'
import type { XmlDocument, XmlNode } from './xml.ts'

export type DocumentKind = 'invoice' | 'credit_note' | 'debit_note'

/** Root element, namespace, line element and totals element of each kind. */
const KINDS: Record<DocumentKind, { root: string, namespace: string, line: string, totals: string }> = {
  invoice: { root: 'Invoice', namespace: NS.invoice, line: 'InvoiceLine', totals: 'LegalMonetaryTotal' },
  credit_note: { root: 'CreditNote', namespace: NS.creditNote, line: 'CreditNoteLine', totals: 'LegalMonetaryTotal' },
  debit_note: { root: 'DebitNote', namespace: NS.debitNote, line: 'DebitNoteLine', totals: 'RequestedMonetaryTotal' },
}

/** The name of the official XSD of a kind, such as UBL-CreditNote-2.1.xsd. */
export function schemaName(kind: DocumentKind): string {
  return `UBL-${KINDS[kind].root}-2.1.xsd`
}

/** The official XSD of each kind in the directory of the schemas: maindoc/UBL-Invoice-2.1.xsd and the others. */
export function schemaPaths(directory: string): Record<DocumentKind, string> {
  const schema = (kind: DocumentKind): string => join(directory, 'maindoc', schemaName(kind))

  return { invoice: schema('invoice'), credit_note: schema('credit_note'), debit_note: schema('debit_note') }
}

export interface DocumentData {
  root: XmlNode
  /** Null when the root is none of the three documents. */
  kind: DocumentKind | null
  ublVersion: string | undefined
  environment: string | undefined
  number: string | undefined
  /** cbc:UUID: the CUFE of an invoice or the CUDE of a note. */
  cufe: string | undefined
  uuidScheme: string | undefined
  issueDate: string | undefined
  issueTime: string | undefined
  supplierNit: string | undefined
  supplierCheckDigit: string | undefined
  supplierName: string | undefined
  customerId: string | undefined
  softwareId: string | undefined
  softwareSecurityCode: string | undefined
  authorization: string | undefined
  periodStart: string | undefined
  periodEnd: string | undefined
  prefix: string | undefined
  from: string | undefined
  to: string | undefined
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
  signature: XmlNode | undefined
  certificate: string | undefined
  signingTime: string | undefined
  /** A note: cac:BillingReference/cac:InvoiceDocumentReference, the invoice it corrects. */
  reference: { id: string | undefined, cufe: string | undefined, scheme: string | undefined, issueDate: string | undefined } | undefined
  /** A note: cac:DiscrepancyResponse/cbc:ResponseCode, the concept of the correction. */
  concept: string | undefined
}

const cac = (name: string) => [NS.cac, name] as const
const cbc = (name: string) => [NS.cbc, name] as const
const sts = (name: string) => [NS.sts, name] as const

function sum(nodes: XmlNode[]): bigint {
  return nodes.reduce((total, node) => total + (micros(text(node)) ?? 0n), 0n)
}

function kindOf(root: XmlNode): DocumentKind | null {
  for (const [kind, shape] of Object.entries(KINDS) as [DocumentKind, (typeof KINDS)[DocumentKind]][]) {
    if (root.localName === shape.root && root.namespaceURI === shape.namespace) {
      return kind
    }
  }

  return null
}

export function readDocument(document: XmlDocument): DocumentData {
  const root = document.documentElement as XmlNode
  const kind = kindOf(root)
  const shape = KINDS[kind ?? 'invoice']
  const extensions = children(child(root, NS.ext, 'UBLExtensions'), NS.ext, 'UBLExtension')
  const dian = extensions.map((extension) => path(extension, [NS.ext, 'ExtensionContent'], sts('DianExtensions'))).find(Boolean)
  const control = child(dian, NS.sts, 'InvoiceControl')
  const supplier = path(root, cac('AccountingSupplierParty'), cac('Party'), cac('PartyTaxScheme'))
  const customer = path(root, cac('AccountingCustomerParty'), cac('Party'), cac('PartyTaxScheme'))
  const totals = child(root, NS.cac, shape.totals)

  const taxByCode = new Map<string, bigint>()
  let headerTaxes = 0n

  for (const total of children(root, NS.cac, 'TaxTotal')) {
    const amount = micros(text(child(total, NS.cbc, 'TaxAmount'))) ?? 0n
    const code = text(path(total, cac('TaxSubtotal'), cac('TaxCategory'), cac('TaxScheme'), cbc('ID'))) ?? ''
    taxByCode.set(code, (taxByCode.get(code) ?? 0n) + amount)
    headerTaxes += amount
  }

  const lines = children(root, NS.cac, shape.line)
  const linesTaxable = lines.reduce((total, line) => total + children(line, NS.cac, 'TaxTotal')
    .reduce((lineTotal, tax) => lineTotal + sum(children(tax, NS.cac, 'TaxSubtotal').map((subtotal) => child(subtotal, NS.cbc, 'TaxableAmount')).filter((node): node is XmlNode => node !== undefined)), 0n), 0n)

  const signature = descendants(root, NS.ds, 'Signature')[0]
  const companyId = child(supplier, NS.cbc, 'CompanyID')
  const uuid = child(root, NS.cbc, 'UUID')
  const reference = path(root, cac('BillingReference'), cac('InvoiceDocumentReference'))
  const referenceUuid = child(reference, NS.cbc, 'UUID')

  return {
    root,
    kind,
    ublVersion: text(child(root, NS.cbc, 'UBLVersionID')),
    environment: text(child(root, NS.cbc, 'ProfileExecutionID')),
    number: text(child(root, NS.cbc, 'ID')),
    cufe: text(uuid),
    uuidScheme: attribute(uuid, 'schemeName'),
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
    reference: reference === undefined
      ? undefined
      : {
          id: text(child(reference, NS.cbc, 'ID')),
          cufe: text(referenceUuid),
          scheme: attribute(referenceUuid, 'schemeName'),
          issueDate: text(child(reference, NS.cbc, 'IssueDate')),
        },
    concept: text(path(root, cac('DiscrepancyResponse'), cbc('ResponseCode'))),
  }
}
