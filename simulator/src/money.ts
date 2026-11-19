// Exact decimal arithmetic for amounts: values are bigint millionths, so sums never drift.
import { createHash } from 'node:crypto'

const SCALE = 1_000_000n

/** A decimal string in millionths; null when it is missing or not a plain decimal. */
export function micros(value: string | undefined): bigint | null {
  const [, sign, whole, decimals = ''] = /^\s*(-)?(\d+)(?:\.(\d+))?\s*$/.exec(value ?? '') ?? []

  if (whole === undefined) {
    return null
  }

  const amount = BigInt(whole) * SCALE + BigInt(decimals.padEnd(6, '0').slice(0, 6))

  return sign ? -amount : amount
}

/** Two decimals truncated, as the CUFE writes every amount: 1500000 and 1500000.009 are 1500000.00. */
export function cufeAmount(value: bigint): string {
  const negative = value < 0n
  const cents = (negative ? -value : value) / 10_000n
  const text = `${cents / 100n}.${(cents % 100n).toString().padStart(2, '0')}`

  return negative ? `-${text}` : text
}

/** XPath round(): to the nearest whole number, halves up, as the annex rules compare totals. */
export function round(value: bigint): bigint {
  const shifted = value + SCALE / 2n
  const quotient = shifted / SCALE

  return shifted < 0n && shifted % SCALE !== 0n ? quotient - 1n : quotient
}

export function sha384(text: string): string {
  return createHash('sha384').update(text, 'utf8').digest('hex')
}
