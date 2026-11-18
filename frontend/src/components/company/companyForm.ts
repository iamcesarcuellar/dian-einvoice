import type { Company, CompanyInput } from '@/api/types'

/** Form state of a company. Text fields are the strings bound to their inputs. */
export interface CompanyFormModel {
  name: string
  legal_name: string
  identification_number: string
  check_digit: string
  organization_type: string | null
  tax_scheme: string | null
  fiscal_responsibilities: string[]
  registration_number: string
  email: string
  phone: string
  address: string
  municipality_code: string | null
  postal_code: string
}

export function emptyCompanyForm(): CompanyFormModel {
  return {
    name: '',
    legal_name: '',
    identification_number: '',
    check_digit: '',
    organization_type: null,
    tax_scheme: null,
    fiscal_responsibilities: [],
    registration_number: '',
    email: '',
    phone: '',
    address: '',
    municipality_code: null,
    postal_code: '',
  }
}

export function companyFormFrom(company: Company): CompanyFormModel {
  return {
    name: company.name,
    legal_name: company.legal_name,
    identification_number: company.identification_number,
    check_digit: String(company.check_digit),
    organization_type: company.organization_type,
    tax_scheme: company.tax_scheme,
    fiscal_responsibilities: [...company.fiscal_responsibilities],
    registration_number: company.registration_number ?? '',
    email: company.email ?? '',
    phone: company.phone ?? '',
    address: company.address ?? '',
    municipality_code: company.municipality_code,
    postal_code: company.postal_code ?? '',
  }
}

export function orNull(value: string): string | null {
  const trimmed = value.trim()

  return trimmed === '' ? null : trimmed
}

/** The API validates every value, including a check digit that does not match the NIT. */
export function toCompanyInput(form: CompanyFormModel): CompanyInput {
  return {
    name: form.name.trim(),
    legal_name: orNull(form.legal_name),
    identification_number: form.identification_number.trim(),
    check_digit: orNull(form.check_digit),
    organization_type: form.organization_type,
    tax_scheme: form.tax_scheme,
    fiscal_responsibilities: form.fiscal_responsibilities.length > 0 ? [...form.fiscal_responsibilities] : null,
    registration_number: orNull(form.registration_number),
    email: orNull(form.email),
    phone: orNull(form.phone),
    address: orNull(form.address),
    municipality_code: form.municipality_code,
    postal_code: orNull(form.postal_code),
  }
}
