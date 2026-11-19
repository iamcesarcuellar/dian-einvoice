import { describe, expect, it } from 'vitest'
import { fromCents, toCents } from '@/lib/money'

// The payment editor adds the amounts typed for each invoice with these, so a total never goes
// through floats.
describe('money in cents', () => {
  it.each([
    ['1234.5', 123450n],
    ['1234.56', 123456n],
    ['1234', 123400n],
    [' 0.1 ', 10n],
    ['-0.5', -50n],
    ['1.234', null],
    ['1.234,5', null],
    ['abc', null],
    ['', null],
    [null, null],
  ])('reads %j as %s cents', (value, cents) => {
    expect(toCents(value)).toBe(cents)
  })

  it('writes cents back with two decimals, as the API takes amounts', () => {
    expect(fromCents(123450n)).toBe('1234.50')
    expect(fromCents(5n)).toBe('0.05')
    expect(fromCents(-5n)).toBe('-0.05')
    expect(fromCents(0n)).toBe('0.00')
  })

  it('adds amounts exactly', () => {
    const total = ['0.1', '0.2', '1234567.89'].reduce((sum, amount) => sum + (toCents(amount) ?? 0n), 0n)

    expect(fromCents(total)).toBe('1234568.19')
  })
})
