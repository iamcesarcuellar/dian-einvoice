export { cn } from 'cn'

/** Formats a NIT the Colombian way, with dots and the check digit: 900.123.456-7. */
export function formatNit(identificationNumber: string, checkDigit: number): string {
  return `${identificationNumber.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}-${checkDigit}`
}

const NIT_WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71]

/**
 * DIAN modulo 11 check digit, shown while typing a NIT. The API computes the stored one.
 * Returns null when the text is not a NIT.
 */
export function nitCheckDigit(nit: string): number | null {
  const digits = nit.replace(/[\s.]/g, '')

  if (!/^\d{5,15}$/.test(digits)) {
    return null
  }

  const sum = [...digits].reverse().reduce((total, digit, index) => total + Number(digit) * (NIT_WEIGHTS[index] ?? 0), 0)
  const remainder = sum % 11

  return remainder > 1 ? 11 - remainder : remainder
}
