import type { Company, CompanyInput } from '@/api/types'

/** Form state of a company. Every field is the string bound to its input. */
export interface CompanyFormModel {
  name: string
  legal_name: string
  identification_number: string
  check_digit: string
  email: string
  phone: string
  address: string
  city: string
}

export function emptyCompanyForm(): CompanyFormModel {
  return {
    name: '',
    legal_name: '',
    identification_number: '',
    check_digit: '',
    email: '',
    phone: '',
    address: '',
    city: '',
  }
}

export function companyFormFrom(company: Company): CompanyFormModel {
  return {
    name: company.name,
    legal_name: company.legal_name,
    identification_number: company.identification_number,
    check_digit: String(company.check_digit),
    email: company.email ?? '',
    phone: company.phone ?? '',
    address: company.address ?? '',
    city: company.city ?? '',
  }
}

function orNull(value: string): string | null {
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
    email: orNull(form.email),
    phone: orNull(form.phone),
    address: orNull(form.address),
    city: orNull(form.city),
  }
}
