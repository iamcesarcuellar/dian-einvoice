import type { Contact, DocumentLine, DocumentType, DocumentWithholdingAmount, Invoice, InvoiceInput, ItemOption, NotePrefill } from '@/api/types'
import { orNull } from '@/components/company/companyForm'

/** Value of the "Excluded (no tax)" option; the API receives null. */
export const EXCLUDED = 'excluded'

export const CASH = '1'
export const CREDIT = '2'

export interface LineForm {
  /** Stable key of the line on screen; it is not sent. */
  key: string
  item: ItemOption | null
  code: string
  description: string
  unit_code: string | null
  quantity: string
  unit_price: string
  discount_rate: string
  /** A tax id, EXCLUDED, or null until the taxes load. */
  tax_id: string | null
}

export interface InvoiceForm {
  contact: Contact | null
  number_template_id: string | null
  payment_form: string | null
  payment_means_code: string | null
  due_date: string
  notes: string
  lines: LineForm[]
  withholding_ids: string[]
  /** A note: the invoice it corrects (it never changes), its concept and its description. */
  related_document_id: string | null
  reason_code: string | null
  reason_text: string
}

let sequence = 0

function newKey(): string {
  sequence += 1

  return `line-${sequence}`
}

export function emptyLine(taxId: string | null): LineForm {
  return {
    key: newKey(),
    item: null,
    code: '',
    description: '',
    unit_code: '94',
    quantity: '1',
    unit_price: '',
    discount_rate: '',
    tax_id: taxId,
  }
}

/** A new invoice is paid in cash, by default. */
export function emptyInvoiceForm(): InvoiceForm {
  return {
    contact: null,
    number_template_id: null,
    payment_form: CASH,
    payment_means_code: '10',
    due_date: '',
    notes: '',
    lines: [],
    withholding_ids: [],
    related_document_id: null,
    reason_code: null,
    reason_text: '',
  }
}

function linesFrom(lines: DocumentLine[]): LineForm[] {
  return lines.map((line) => ({
    key: newKey(),
    item: line.item ?? null,
    code: line.code ?? '',
    description: line.description ?? '',
    unit_code: line.unit_code,
    quantity: line.quantity,
    unit_price: line.unit_price,
    discount_rate: line.discount_rate,
    tax_id: line.tax_id ?? EXCLUDED,
  }))
}

function withholdingIds(withholdings: DocumentWithholdingAmount[]): string[] {
  return withholdings
    .map((withholding) => withholding.withholding_id)
    .filter((id): id is string => id !== null)
}

/** A saved draft: an invoice or a note. */
export function invoiceFormFrom(invoice: Invoice): InvoiceForm {
  return {
    contact: invoice.contact ?? null,
    number_template_id: invoice.number_template_id,
    payment_form: invoice.payment_form,
    payment_means_code: invoice.payment_means_code,
    due_date: invoice.due_date ?? '',
    notes: invoice.notes ?? '',
    lines: linesFrom(invoice.lines ?? []),
    withholding_ids: withholdingIds(invoice.withholdings ?? []),
    related_document_id: invoice.related_document_id,
    reason_code: invoice.reason_code,
    reason_text: invoice.reason_text ?? '',
  }
}

/** A new note, from what the API prefilled from its invoice. */
export function noteFormFrom(prefill: NotePrefill): InvoiceForm {
  return {
    contact: prefill.contact,
    number_template_id: prefill.number_template_id,
    payment_form: prefill.payment_form,
    payment_means_code: prefill.payment_means_code,
    due_date: prefill.due_date ?? '',
    notes: prefill.notes ?? '',
    lines: linesFrom(prefill.lines),
    withholding_ids: withholdingIds(prefill.withholdings),
    related_document_id: prefill.related_document_id,
    reason_code: prefill.reason_code,
    reason_text: prefill.reason_text ?? '',
  }
}

/** A line is blank until it has an item, a description or a price; blank lines are not sent. */
export function isBlankLine(line: LineForm): boolean {
  return line.item === null && line.description.trim() === '' && line.unit_price.trim() === ''
}

/**
 * The body for the API, without blank lines, and the keys of the lines that were sent: the API
 * answers line by line in that order. A note sends its invoice, concept and description; its
 * customer and payment terms come from the invoice.
 */
export function toInvoiceInput(form: InvoiceForm, type: DocumentType = 'invoice'): { input: InvoiceInput, keys: string[] } {
  const lines = form.lines.filter((line) => !isBlankLine(line))
  const header = type === 'invoice'
    ? {
        contact_id: form.contact?.id ?? null,
        payment_form: form.payment_form,
        payment_means_code: form.payment_means_code,
        due_date: orNull(form.due_date),
      }
    : {
        related_document_id: form.related_document_id,
        reason_code: form.reason_code,
        reason_text: orNull(form.reason_text),
      }

  return {
    input: {
      ...header,
      number_template_id: form.number_template_id,
      notes: orNull(form.notes),
      lines: lines.map((line) => ({
        item_id: line.item?.id ?? null,
        code: orNull(line.code),
        description: orNull(line.description),
        unit_code: line.unit_code,
        quantity: orNull(line.quantity),
        unit_price: orNull(line.unit_price),
        discount_rate: orNull(line.discount_rate),
        tax_id: line.tax_id === EXCLUDED ? null : line.tax_id,
      })),
      withholding_ids: [...form.withholding_ids],
    },
    keys: lines.map((line) => line.key),
  }
}
