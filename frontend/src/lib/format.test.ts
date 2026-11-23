import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import { formatDate, formatDateRange, formatDateTime, formatMoney, formatPercent, formatPerMil, formatWholeMoney } from '@/lib/format'

// The one formatter of the app: every list, total, detail and timeline shows amounts, rates and dates through it.
describe('the formatter', () => {
  it.each([
    ['602140.00', '$ 602.140,00'],
    ['6439265.40', '$ 6.439.265,40'],
    ['602140', '$ 602.140,00'],
    ['0', '$ 0,00'],
    ['-28167.5', '$ -28.167,50'],
    // A unit price keeps the decimals it has beyond two.
    ['21500.125000', '$ 21.500,125'],
  ])('writes the amount %s with two decimals at least: %s', (value, written) => {
    expect(formatMoney(value)).toBe(written)
  })

  it.each([
    ['9798831.87', '$ 9.798.832'],
    ['9798831.49', '$ 9.798.831'],
    ['999.50', '$ 1.000'],
    ['602140.00', '$ 602.140'],
    ['0.40', '$ 0'],
  ])('rounds the amount %s to whole pesos for the dashboard: %s', (value, written) => {
    expect(formatWholeMoney(value)).toBe(written)
  })

  it('writes nothing for an amount that is missing', () => {
    expect(formatMoney(null)).toBe('')
    expect(formatWholeMoney('')).toBe('')
  })

  it('writes rates in one notation: a space before the sign and a decimal comma', () => {
    expect(formatPercent('19.0000')).toBe('19 %')
    expect(formatPercent('2.5000')).toBe('2,5 %')
    expect(formatPerMil('0.966')).toBe('9,66 ‰')
  })

  it('writes dates in English with the month in letters, so no reader takes the day for the month', () => {
    expect(i18n.global.locale.value).toBe('en')
    expect(formatDate('2026-11-19')).toBe('19 Nov 2026')
    expect(formatDate('2026-09-01')).toBe('1 Sep 2026')
    expect(formatDate(null)).toBe('')
  })

  it('writes an instant in Colombian time, whatever the time zone of the browser', () => {
    // 20:00 UTC is 15:00 in Medellín (UTC-5, no daylight saving time).
    expect(formatDateTime('2026-11-19T20:00:00Z')).toBe('19 Nov 2026, 15:00')
    // 03:30 UTC on the 20th is still the evening of the 19th in Colombia.
    expect(formatDateTime('2026-11-20T03:30:00Z')).toBe('19 Nov 2026, 22:30')
    expect(formatDateTime('not a date')).toBe('')
  })

  it('writes a range of days in the same order as a date', () => {
    expect(formatDateRange('2026-10-01', '2026-10-19')).toBe('1–19 Oct')
    expect(formatDateRange('2026-09-28', '2026-10-03')).toBe('28 Sep – 3 Oct')
  })
})

// Strings with a number take the singular for one.
describe('the plural forms of the interface', () => {
  const { t } = i18n.global

  it.each([
    ['units.pages', 1, '1 page'],
    ['units.pages', 2, '2 pages'],
    ['units.bytes', 1, '1 byte'],
    ['dashboard.electronic.documents', 1, '1 document'],
    ['dashboard.electronic.period', 1, 'the last day'],
    ['dashboard.electronic.period', 30, 'the last 30 days'],
  ])('%s of %i reads "%s"', (key, count, text) => {
    expect(t(key, { n: count }, count)).toBe(text)
  })

  it('says one notification, one attempt and one invoice in the singular', () => {
    expect(t('invoices.view.events.acceptedNotifications', { count: 1, rules: 'ZD05' }, 1)).toBe('With 1 notification: ZD05')
    expect(t('invoices.view.events.acceptedNotifications', { count: 2, rules: 'ZD05, FAD09e' }, 2)).toBe('With 2 notifications: ZD05, FAD09e')
    expect(t('invoices.view.events.transmissionFailed', { attempts: 1 }, 1)).toBe('Transmission failed after 1 attempt')
    expect(t('payments.editor.totalHint', { count: 1 }, 1)).toBe('The sum applied to 1 invoice.')
    expect(t('invoices.payments.daysPastDue', { days: 1 }, 1)).toBe('1 day past due')
    expect(t('settings.dian.mailbox.messages', { count: 0 }, 0)).toBe('No emails in the mailbox')
  })
})
