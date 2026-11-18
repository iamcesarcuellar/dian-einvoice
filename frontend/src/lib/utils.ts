export { cn } from 'cn'

/** Formats a NIT the Colombian way, with dots and the check digit: 900.123.456-7. */
export function formatNit(identificationNumber: string, checkDigit: number): string {
  return `${identificationNumber.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}-${checkDigit}`
}
