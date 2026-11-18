import type { Contact, ContactInput } from '@/api/types'
import { orNull } from '@/components/company/companyForm'

export const COLOMBIA = 'CO'
export const NIT = '31'

/** Form state of a contact. Text fields are the strings bound to their inputs. */
export interface ContactFormModel {
  organization_type: string | null
  identification_type: string | null
  identification_number: string
  legal_name: string
  trade_name: string
  tax_scheme: string | null
  fiscal_responsibilities: string[]
  email: string
  phone: string
  address: string
  country_code: string
  municipality_code: string | null
  postal_code: string
  is_active: boolean
}

/** A new contact starts as a company with a NIT in Colombia, without special responsibilities. */
export function emptyContactForm(): ContactFormModel {
  return {
    organization_type: '1',
    identification_type: NIT,
    identification_number: '',
    legal_name: '',
    trade_name: '',
    tax_scheme: 'ZZ',
    fiscal_responsibilities: ['R-99-PN'],
    email: '',
    phone: '',
    address: '',
    country_code: COLOMBIA,
    municipality_code: null,
    postal_code: '',
    is_active: true,
  }
}

export function contactFormFrom(contact: Contact): ContactFormModel {
  return {
    organization_type: contact.organization_type,
    identification_type: contact.identification_type,
    identification_number: contact.identification_number,
    legal_name: contact.legal_name,
    trade_name: contact.trade_name ?? '',
    tax_scheme: contact.tax_scheme,
    fiscal_responsibilities: [...contact.fiscal_responsibilities],
    email: contact.email ?? '',
    phone: contact.phone ?? '',
    address: contact.address ?? '',
    country_code: contact.country_code,
    municipality_code: contact.municipality_code,
    postal_code: contact.postal_code ?? '',
    is_active: contact.is_active,
  }
}

export function toContactInput(form: ContactFormModel): ContactInput {
  const country = form.country_code.trim().toUpperCase()

  return {
    organization_type: form.organization_type ?? '',
    identification_type: form.identification_type ?? '',
    identification_number: form.identification_number.trim(),
    legal_name: form.legal_name.trim(),
    trade_name: orNull(form.trade_name),
    tax_scheme: form.tax_scheme ?? '',
    fiscal_responsibilities: [...form.fiscal_responsibilities],
    email: orNull(form.email),
    phone: orNull(form.phone),
    address: orNull(form.address),
    country_code: country,
    // A contact abroad has no municipality.
    municipality_code: country === COLOMBIA ? form.municipality_code : null,
    postal_code: orNull(form.postal_code),
    is_active: form.is_active,
  }
}
