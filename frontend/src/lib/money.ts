/**
 * Exact arithmetic on amounts typed by the user, in integer cents, so a live total never goes
 * through floats. Amounts the API calculates are shown as they come.
 */

/** Cents of a decimal string with up to two decimals ("1234.5" is 123450); null when it is not one. */
export function toCents(value: string | null | undefined): bigint | null {
  const match = /^(-?)(\d+)(?:\.(\d{0,2}))?$/.exec((value ?? '').trim())

  if (!match) {
    return null
  }

  const amount = BigInt(match[2] ?? '0') * 100n + BigInt((match[3] ?? '').padEnd(2, '0') || '0')

  return match[1] === '-' ? -amount : amount
}

/** A decimal string with two decimals, as the API takes amounts. */
export function fromCents(cents: bigint): string {
  const negative = cents < 0n
  const absolute = negative ? -cents : cents
  const units = absolute / 100n
  const rest = (absolute % 100n).toString().padStart(2, '0')

  return `${negative ? '-' : ''}${units}.${rest}`
}
