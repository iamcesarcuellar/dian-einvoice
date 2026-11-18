export type Role = 'owner' | 'admin' | 'member' | 'viewer'

/**
 * manage-catalog: contacts and items. manage-documents: drafts and issuing.
 * manage-settings: taxes, withholdings, numbering and the company.
 */
export type Permission = 'manage-catalog' | 'manage-documents' | 'manage-settings'

export interface Municipality {
  code: string
  name: string
  department_code: string
  department_name?: string
}

export interface Company {
  id: string
  name: string
  legal_name: string
  /** NIT without the check digit. */
  identification_number: string
  /** Computed by the API with the DIAN modulo 11 algorithm. */
  check_digit: number
  organization_type: string | null
  tax_scheme: string | null
  fiscal_responsibilities: string[]
  registration_number: string | null
  email: string | null
  phone: string | null
  address: string | null
  country_code: 'CO'
  municipality_code: string | null
  municipality?: Municipality | null
  postal_code: string | null
  /** Role of the signed-in user in this company, and what it allows. */
  role?: Role
  permissions?: Permission[]
  created_at: string
  updated_at: string
}

export interface User {
  id: string
  name: string
  email: string
}

/** The signed-in user with the companies they belong to. */
export interface Me extends User {
  companies: Company[]
}

export interface CompanyInput {
  name: string
  legal_name: string | null
  identification_number: string
  /** Optional; when sent it must match the NIT, otherwise the API computes it. */
  check_digit: string | null
  organization_type: string | null
  tax_scheme: string | null
  fiscal_responsibilities: string[] | null
  registration_number: string | null
  email: string | null
  phone: string | null
  address: string | null
  municipality_code: string | null
  postal_code: string | null
}

export type CompanyIdentityInput = Pick<CompanyInput, 'name' | 'legal_name' | 'identification_number' | 'check_digit'>

export interface RegisterInput {
  name: string
  email: string
  password: string
  company: CompanyIdentityInput
}

export interface LoginInput {
  email: string
  password: string
}

/** A code list entry from GET /reference, with its English name. */
export interface CodeOption {
  code: string
  name: string
}

export type WithholdingBase = 'tax' | 'subtotal'

export interface Reference {
  identification_types: CodeOption[]
  organization_types: CodeOption[]
  fiscal_responsibilities: CodeOption[]
  tax_schemes: CodeOption[]
  tax_types: CodeOption[]
  withholding_types: Array<CodeOption & { base: WithholdingBase }>
  units: CodeOption[]
  payment_forms: CodeOption[]
  payment_means: CodeOption[]
  departments: CodeOption[]
}

export interface PageMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export interface Paginated<T> {
  data: T[]
  meta: PageMeta
}

export type QueryParams = Record<string, string | number | undefined>

export interface ListParams extends QueryParams {
  page?: number
  per_page?: number
  search?: string
}

export interface Contact {
  id: string
  organization_type: string
  identification_type: string
  identification_number: string
  /** Only for a NIT. */
  check_digit: number | null
  legal_name: string
  trade_name: string | null
  tax_scheme: string
  fiscal_responsibilities: string[]
  email: string | null
  phone: string | null
  address: string | null
  country_code: string
  municipality_code: string | null
  municipality?: Municipality | null
  postal_code: string | null
  is_active: boolean
  /** The final consumer: it cannot be edited or deleted. */
  is_system: boolean
  created_at: string
  updated_at: string
}

export interface ContactInput {
  organization_type: string
  identification_type: string
  identification_number: string
  legal_name: string
  trade_name: string | null
  tax_scheme: string
  fiscal_responsibilities: string[]
  email: string | null
  phone: string | null
  address: string | null
  country_code: string
  municipality_code: string | null
  postal_code: string | null
  is_active: boolean
}

/** A tax rate, such as IVA 19 %. Rates are percentage strings with 4 decimals. */
export interface Tax {
  id: string
  dian_code: string
  name: string
  rate: string
  is_active: boolean
  /** A company default: it can only be activated or deactivated. */
  is_system: boolean
  created_at: string
  updated_at: string
}

export interface Withholding extends Tax {
  base: WithholdingBase
}

export interface RateInput {
  dian_code?: string
  name?: string
  rate?: string
  is_active: boolean
}

export type ItemType = 'product' | 'service'

export interface Item {
  id: string
  code: string
  name: string
  description: string | null
  type: ItemType
  unit_code: string
  /** Decimal string with 6 decimals. */
  unit_price: string
  /** Null means excluded: no tax. */
  tax_id: string | null
  tax?: Tax | null
  standard_scheme: string | null
  standard_code: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface ItemInput {
  code: string
  name: string
  description: string | null
  type: ItemType
  unit_code: string
  unit_price: string
  tax_id: string | null
  standard_scheme: string | null
  standard_code: string | null
  is_active: boolean
}

export type DocumentType = 'invoice' | 'credit_note' | 'debit_note'

export type NumberingStatus = 'active' | 'upcoming' | 'expired' | 'exhausted'

export interface NumberTemplate {
  id: string
  document_type: DocumentType
  prefix: string | null
  from_number: number
  to_number: number
  next_number: number
  resolution_number: string | null
  valid_from: string | null
  valid_to: string | null
  /** The technical key itself is never returned. */
  technical_key_set: boolean
  technical_key_last4: string | null
  is_default: boolean
  is_active: boolean
  status: NumberingStatus
  usage: { used: number, total: number }
  created_at: string
  updated_at: string
}

export interface NumberTemplateInput {
  document_type: DocumentType
  prefix: string | null
  from_number: number
  to_number: number
  next_number: number
  resolution_number: string | null
  valid_from: string | null
  valid_to: string | null
  /** Write-only: leave it out to keep the stored key. */
  technical_key?: string | null
  is_default: boolean
  is_active: boolean
}

export type DocumentStatus = 'draft' | 'issued'

/** Amounts of a document: decimal strings with 2 decimals, calculated by the API. */
export interface DocumentTotals {
  /** Quantity times price, before discounts. */
  subtotal_amount: string
  discount_total: string
  line_extension_amount: string
  tax_exclusive_amount: string
  tax_total: string
  tax_inclusive_amount: string
  payable_amount: string
  withholding_total: string
  /** Payable amount minus withholdings. */
  amount_due: string
}

export interface DocumentTaxSubtotal {
  dian_code: string
  name: string
  rate: string
  taxable_amount: string
  tax_amount: string
}

export interface DocumentWithholdingAmount {
  withholding_id: string | null
  dian_code: string
  name: string
  rate: string
  base_amount: string
  amount: string
}

export interface DocumentLine {
  id: string
  position: number
  item_id: string | null
  item?: ItemOption | null
  code: string | null
  description: string | null
  unit_code: string | null
  quantity: string
  unit_price: string
  discount_rate: string
  discount_amount: string
  line_extension_amount: string
  tax_id: string | null
  tax_code: string | null
  tax_name: string | null
  tax_rate: string | null
  tax_amount: string
}

export type DocumentEventType = 'created' | 'issued' | 'xml_generated' | 'xml_signed' | 'xml_failed'

export interface DocumentEvent {
  id: string
  type: DocumentEventType
  /** xml_failed carries { reason }; xml_signed carries { certificate_serial, test_certificate, sha256, size }. */
  payload: Record<string, unknown> | null
  occurred_at: string
  actor: { id: string, name: string } | null
}

/** UBL 2.1 XML of an issued document, signed with XAdES-EPES; null for drafts. */
export type XmlStatus = 'pending' | 'generated' | 'signed' | 'failed'

export interface DocumentXml {
  status: XmlStatus | null
  file_name: string | null
  /** Bytes and SHA-256 of the stored, signed XML. */
  size: number | null
  sha256: string | null
  generated_at: string | null
  signed_at: string | null
  /** Decimal serial number of the certificate that signed it. */
  certificate_serial: string | null
}

/** A party as it was frozen on an issued document. */
export interface PartySnapshot {
  legal_name: string
  trade_name?: string | null
  name?: string
  identification_type: string
  identification_number: string
  check_digit: number | null
  email: string | null
  phone: string | null
  address: string | null
  country_code: string
  municipality: Municipality | null
  postal_code: string | null
}

export interface NumberingSnapshot {
  number_template_id: string
  prefix: string | null
  from_number: number
  to_number: number
  resolution_number: string | null
  valid_from: string | null
  valid_to: string | null
}

export interface Invoice {
  id: string
  type: 'invoice'
  status: DocumentStatus
  number_template_id: string | null
  prefix: string | null
  number: number | null
  full_number: string | null
  contact_id: string | null
  contact?: Contact | null
  customer_name: string | null
  /** Colombian date of issue, and time with its offset, such as 10:53:10-05:00. */
  issue_date: string | null
  issue_time: string | null
  issued_at: string | null
  due_date: string | null
  payment_form: string | null
  payment_means_code: string | null
  currency: string
  notes: string | null
  totals: DocumentTotals
  taxes?: DocumentTaxSubtotal[]
  withholdings?: DocumentWithholdingAmount[]
  lines?: DocumentLine[]
  events?: DocumentEvent[]
  company_snapshot: PartySnapshot | null
  customer_snapshot: PartySnapshot | null
  numbering_snapshot: NumberingSnapshot | null
  /** Set on issue: SHA-384 code of the invoice, in lowercase hexadecimal. */
  cufe: string | null
  /** DIAN verification URL, drawn as the QR code. */
  qr_url: string | null
  /** Always 2, the habilitación (test) environment. */
  dian_environment: number | null
  xml: DocumentXml
  created_at: string
  updated_at: string
}

/** DIAN settings of the company, for owners and admins. */
export interface DianSettings {
  /** Always 2, habilitación (test): production is not part of this project. */
  environment: number
  environment_name: string
  software_id: string | null
  /** The PIN itself is never returned. */
  software_pin_set: boolean
  test_set_id: string | null
  updated_at: string | null
}

export type CertificateStatus = 'valid' | 'expiring_soon' | 'expired' | 'not_yet_valid'

/** Public metadata of the signing certificate; the private key and the password never leave the API. */
export interface SigningCertificate {
  id: string
  subject: string
  issuer: string
  serial_number: string
  fingerprint_sha256: string
  valid_from: string
  valid_to: string
  /** expiring_soon: less than 30 days left. */
  status: CertificateStatus
  /** A synthetic self-signed certificate generated by this app, not issued by an accredited authority. */
  is_test: boolean
  created_at: string
}

export interface DianSettingsInput {
  software_id: string | null
  /** Write-only: leave it out to keep the stored PIN. */
  software_pin?: string | null
  test_set_id: string | null
}

export interface InvoiceLineInput {
  item_id: string | null
  code: string | null
  description: string | null
  unit_code: string | null
  quantity: string | null
  unit_price: string | null
  discount_rate: string | null
  /** Null means excluded: no tax. */
  tax_id: string | null
}

export interface InvoiceInput {
  contact_id: string | null
  number_template_id: string | null
  payment_form: string | null
  payment_means_code: string | null
  due_date: string | null
  notes: string | null
  lines: InvoiceLineInput[]
  withholding_ids: string[]
}

/** POST /invoices/preview: what the invoice would be, with nothing stored. */
export interface Calculation {
  lines: Array<{ position: number, discount_amount: string, line_extension_amount: string, tax_amount: string }>
  taxes: DocumentTaxSubtotal[]
  withholdings: DocumentWithholdingAmount[]
  totals: DocumentTotals
}

/** The item fields a line shows when it was taken from the catalog. */
export interface ItemOption {
  id: string
  code: string
  name: string
}

/** RFC 9457 problem details: the shape of every API error. */
export interface Problem {
  type: string
  title: string
  status: number
  detail?: string
  instance?: string
  /** Validation messages by field. */
  errors?: Record<string, string[]>
}

export interface DataEnvelope<T> {
  data: T
}
