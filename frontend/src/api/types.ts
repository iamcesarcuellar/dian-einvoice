export type Role = 'owner' | 'admin' | 'member' | 'viewer'

export interface Company {
  id: string
  name: string
  legal_name: string
  /** NIT without the check digit. */
  identification_number: string
  /** Computed by the API with the DIAN modulo 11 algorithm. */
  check_digit: number
  email: string | null
  phone: string | null
  address: string | null
  city: string | null
  /** Role of the signed-in user in this company. */
  role?: Role
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
  email: string | null
  phone: string | null
  address: string | null
  city: string | null
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
