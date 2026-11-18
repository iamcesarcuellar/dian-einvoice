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
  /** Concepts of the notes, from the official lists of the DIAN toolbox (tables 13.2.4 and 13.2.5). */
  credit_note_concepts: ConceptOption[]
  debit_note_concepts: ConceptOption[]
  departments: CodeOption[]
}

/** A concept of a credit or debit note: its code, English name and the name of the official list. */
export interface ConceptOption {
  code: string
  name: string
  official_name: string
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
  /** Date of the DIAN resolution, required for invoices. */
  resolution_date: string | null
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
  resolution_date: string | null
  valid_from: string | null
  valid_to: string | null
  /** Write-only: leave it out to keep the stored key. */
  technical_key?: string | null
  is_default: boolean
  is_active: boolean
}

export type DocumentStatus = 'draft' | 'issued'

/** Credit and debit notes: the document types that correct an invoice. */
export type NoteType = Exclude<DocumentType, 'invoice'>

/** Balance of an issued invoice after the notes the local simulator accepted. */
export type BalanceStatus = 'open' | 'partially_credited' | 'voided'

/** Calculated by the API: decimal strings with 2 decimals. */
export interface InvoiceBalance {
  total: string
  /** Accepted credit notes. */
  credited: string
  /** Accepted debit notes. */
  debited: string
  /** Total plus debited less credited. */
  balance: string
  /** Credit notes still on their way: issued, processing or failed, not rejected. */
  pending_credits: string
  /** What a new credit note can still credit. */
  remaining_to_credit: string
}

/** The invoice a note corrects. */
export interface RelatedDocument {
  id: string
  type?: 'invoice'
  full_number: string | null
  issue_date: string | null
  cufe: string | null
  customer_name: string | null
  currency?: string
  payable_amount: string | null
  electronic_status?: ElectronicStatus | null
  balance_status?: BalanceStatus | null
}

/** A note in the summary of its invoice. */
export interface RelatedNote {
  id: string
  type: NoteType
  status: DocumentStatus
  full_number: string | null
  issue_date: string | null
  reason_code: string | null
  payable_amount: string
  currency: string
  electronic_status: ElectronicStatus | null
  created_at: string
}

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

export type DocumentEventType =
  | 'created'
  | 'issued'
  | 'xml_generated'
  | 'xml_signed'
  | 'xml_failed'
  | 'transmission_attempt'
  | 'accepted'
  | 'rejected'
  | 'transmission_failed'
  | 'retry_requested'
  | 'resend_requested'
  | 'attached_document_built'
  | 'pdf_generated'
  | 'email_attempt'
  | 'delivery_not_applicable'
  | 'delivery_failed'
  | 'delivery_requested'
  | 'pdf_regeneration_requested'
  | 'note_accepted'

export interface DocumentEvent {
  id: string
  type: DocumentEventType
  /**
   * xml_failed: { step, reason }; xml_signed: { certificate_serial, test_certificate, sha256, size };
   * transmission_attempt: TransmissionAttemptPayload; accepted and rejected: { status_code, rules,
   * notifications }; transmission_failed: { attempts, reason }; retry_requested: { step, waiting };
   * resend_requested: { cufe_changed }; attached_document_built and pdf_generated: { file_name, size,
   * sha256, pages }; email_attempt: { attempt, max_attempts, recipient, outcome, error, retry_in };
   * delivery_failed: { step, reason, attempts }; delivery_requested: { recipient, previous_recipient, step }.
   */
  payload: Record<string, unknown> | null
  occurred_at: string
  actor: { id: string, name: string } | null
}

/** How one call to the local DIAN simulator ended. */
export type TransmissionOutcome =
  | 'responded'
  | 'found'
  | 'not_found'
  | 'timeout'
  | 'no_response'
  | 'refused'
  | 'server_error'
  | 'invalid_response'
  | 'client_error'
  | 'abandoned'

/** UBL 2.1 XML of an issued document, signed with XAdES-EPES; null for drafts. */
export type XmlStatus = 'pending' | 'generated' | 'signed' | 'failed'

export interface DocumentXml {
  status: XmlStatus | null
  /** The signed XML can be viewed and downloaded. */
  available: boolean
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
  resolution_date: string | null
  valid_from: string | null
  valid_to: string | null
}

/** An invoice, a credit note or a debit note. */
export interface Invoice {
  id: string
  type: DocumentType
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
  /** A note: the invoice it corrects, and the concept and description of the correction. */
  related_document_id: string | null
  related_document: RelatedDocument | null
  reason_code: string | null
  reason_text: string | null
  /** An issued invoice: open, partially_credited or voided. */
  balance_status: BalanceStatus | null
  /** On the detail of an issued invoice: its balance and its notes. */
  balance?: InvoiceBalance
  related_notes?: RelatedNote[]
  /** On the detail of a note: the balance of its invoice without the note itself. */
  invoice_balance?: InvoiceBalance
  totals: DocumentTotals
  taxes?: DocumentTaxSubtotal[]
  withholdings?: DocumentWithholdingAmount[]
  lines?: DocumentLine[]
  events?: DocumentEvent[]
  company_snapshot: PartySnapshot | null
  customer_snapshot: PartySnapshot | null
  numbering_snapshot: NumberingSnapshot | null
  /** Set on issue: SHA-384 code of the document, in lowercase hexadecimal; the CUFE of an invoice or the CUDE of a note. */
  cufe: string | null
  key_name: 'CUFE' | 'CUDE'
  /** DIAN verification URL, drawn as the QR code. */
  qr_url: string | null
  /** Always 2, the habilitación (test) environment. */
  dian_environment: number | null
  xml: DocumentXml
  /** The electronic document chain; null for drafts. */
  electronic: ElectronicDocument | null
  /** Delivery to the customer once accepted; null for drafts. */
  delivery: InvoiceDelivery | null
  created_at: string
  updated_at: string
}

/** Delivery of an accepted invoice to its customer, apart from its electronic status. */
export type DeliveryStatus = 'pending' | 'delivered' | 'failed' | 'not_applicable'

/** The steps of the delivery, in order. */
export type DeliveryStep = 'attached_document' | 'pdf' | 'email'

/** A file delivered to the customer, as stored. */
export interface DeliveredFile {
  /** Name of the download; the PDF downloads under the invoice number. */
  file_name: string
  /** Name inside the zip, as annex 1.9 names the files. */
  package_name: string
  mime_type: string
  size: number
  sha256: string
  pages: number | null
  updated_at: string
}

export interface InvoiceDelivery {
  /** Null until the local simulator accepts the invoice. */
  status: DeliveryStatus | null
  /** Delivered, failed or not applicable: the delivery has ended. */
  final: boolean
  /** The step running while pending, or the one that failed. */
  step: DeliveryStep | null
  status_changed_at: string | null
  /** Pass of the delivery: 1 on acceptance, one more on each Send again or Regenerate PDF. */
  run: number
  attempt: number
  max_attempts: number
  /** Waiting for the next email attempt after a transient failure. */
  retrying: boolean
  next_attempt_at: string | null
  recipient: string | null
  /** The customer email frozen on the invoice; null for the final consumer. */
  customer_email: string | null
  delivered_at: string | null
  /** Why the delivery failed, while failed. */
  error: string | null
  can_send: boolean
  can_regenerate_pdf: boolean
  /** Always the local development mailbox: no email leaves the machine. */
  mailbox: 'local'
  /** Only on the invoice detail. */
  files: {
    attached_document: DeliveredFile | null
    pdf: DeliveredFile | null
    package: DeliveredFile | null
  } | null
}

/** The local development mailbox (Mailpit), a development tool. */
export interface LocalMailboxStatus {
  /** Address of its web interface. */
  url: string
  reachable: boolean
  /** Emails it holds, from every company. */
  messages: number | null
  version: string | null
}

/** State of the electronic document chain of an issued invoice. */
export type ElectronicStatus = 'pending' | 'generated' | 'signed' | 'sending' | 'accepted' | 'rejected' | 'failed'

/** A rule the local DIAN simulator reported, with its code and its message in Spanish (DIAN data). */
export interface DianRuleMessage {
  rule: string | null
  kind: 'rejection' | 'notification'
  message: string
}

/** One call to the local DIAN simulator. */
export interface DianTransmission {
  id: string
  run: number
  attempt: number
  operation: 'SendBillSync' | 'GetStatus'
  gateway: string
  zip_file_name: string | null
  started_at: string
  finished_at: string | null
  duration_ms: number | null
  outcome: TransmissionOutcome | null
  http_status: number | null
  status_code: string | null
  status_description: string | null
  is_valid: boolean | null
  error: string | null
}

export interface ElectronicDocument {
  status: ElectronicStatus
  /** Accepted, rejected or failed: the chain has ended. */
  final: boolean
  status_changed_at: string | null
  /** Pass of the chain: 1 on issue, one more on each Resend or Retry after a failure. */
  run: number
  attempt: number
  max_attempts: number
  /** Waiting for the next attempt after a transient failure. */
  retrying: boolean
  next_attempt_at: string | null
  failed_step: 'generate' | 'sign' | 'transmit' | null
  can_retry: boolean
  can_resend: boolean
  zip_file_name: string | null
  xml_file_name: string | null
  /** Always the local simulator: no document of this project is registered with the DIAN. */
  validated_by: 'local_simulator'
  result: {
    gateway: string | null
    accepted: boolean
    status_code: string
    status_description: string | null
    status_message: string | null
    rules: DianRuleMessage[]
    notifications: DianRuleMessage[]
    responded_at: string | null
    response_available: boolean
    response_file_name: string | null
  } | null
  transmissions?: DianTransmission[]
}

/** Failure modes of the local DIAN simulator. */
export type SimulatorModeName = 'normal' | 'reject' | 'no_response' | 'error_500' | 'slow' | 'intermittent'

export interface SimulatorMode {
  nit: string
  mode: SimulatorModeName
  rule: string | null
  delay_ms: number | null
  failure_rate: number | null
  /** Requests it still applies to; null until it is changed. */
  remaining: number | null
  updated_at: string
  /** company: set for this company; all: set for every company; default: never set. */
  scope: 'company' | 'all' | 'default'
}

export interface SimulatorRule {
  code: string
  kind: 'rejection' | 'notification'
  message: string
  check: string
}

export interface SimulatorSettings {
  mode: SimulatorMode
  modes: SimulatorModeName[]
  rules: SimulatorRule[]
}

export interface SimulatorModeInput {
  mode: SimulatorModeName
  rule?: string | null
  delay_ms?: number | null
  failure_rate?: number | null
  count?: number | null
}

/** A request the local DIAN simulator received from the company. */
export interface SimulatorRequest {
  at: string
  operation: string
  nit: string | null
  document: string | null
  result: string
  status_code: string | null
  duration_ms: number
  mode: string | null
  rules: string[]
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
  contact_id?: string | null
  /** A new note: the accepted invoice it corrects. */
  related_document_id?: string | null
  reason_code?: string | null
  reason_text?: string | null
  number_template_id: string | null
  payment_form?: string | null
  payment_means_code?: string | null
  due_date?: string | null
  notes: string | null
  lines: InvoiceLineInput[]
  withholding_ids: string[]
}

/** GET /credit-notes/prefill and /debit-notes/prefill: a note not saved yet, for an accepted invoice. */
export interface NotePrefill {
  type: NoteType
  status: 'draft'
  related_document_id: string
  related_document: RelatedDocument
  contact_id: string | null
  contact: Contact | null
  customer_name: string | null
  currency: string
  payment_form: string | null
  payment_means_code: string | null
  due_date: string | null
  number_template_id: string | null
  reason_code: string | null
  reason_text: string | null
  notes: string | null
  lines: DocumentLine[]
  withholdings: DocumentWithholdingAmount[]
  balance: InvoiceBalance
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
