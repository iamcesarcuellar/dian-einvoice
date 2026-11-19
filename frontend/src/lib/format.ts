import { i18n } from '@/i18n'

// The one formatter of the app: amounts, numbers and dates are shown only through these functions.
// Colombian number formatting is done on strings: money, quantities and rates never become floats.

const THOUSANDS = '.'
const DECIMALS = ','

interface DecimalParts {
  negative: boolean
  integer: string
  fraction: string
}

function parts(value: string): DecimalParts | null {
  const match = /^(-)?(\d+)(?:\.(\d+))?$/.exec(value.trim())

  if (!match) {
    return null
  }

  return {
    negative: match[1] === '-',
    integer: (match[2] ?? '0').replace(/^0+(?=\d)/, ''),
    fraction: match[3] ?? '',
  }
}

function group(integer: string): string {
  return integer.replace(/\B(?=(\d{3})+(?!\d))/g, THOUSANDS)
}

/**
 * "1234567.500000" becomes "1.234.567,5". Trailing zeros are dropped; when decimals remain,
 * at least minFraction are shown.
 */
export function formatDecimal(value: string | null | undefined, minFraction = 0): string {
  if (value === null || value === undefined || value === '') {
    return ''
  }

  const decimal = parts(value)

  if (!decimal) {
    return value
  }

  let fraction = decimal.fraction.replace(/0+$/, '')

  if (fraction !== '' && fraction.length < minFraction) {
    fraction = fraction.padEnd(minFraction, '0')
  }

  return `${decimal.negative ? '-' : ''}${group(decimal.integer)}${fraction ? DECIMALS + fraction : ''}`
}

/** Colombian pesos: "$ 1.234.567,50". */
export function formatMoney(value: string | null | undefined): string {
  const formatted = formatDecimal(value, 2)

  return formatted ? `$ ${formatted}` : ''
}

/** "19 %", "2,5 %". */
export function formatPercent(value: string | null | undefined): string {
  const formatted = formatDecimal(value)

  return formatted ? `${formatted} %` : ''
}

/**
 * A percentage written per thousand, as ReteICA rates are usually given and as the DIAN writes them
 * in the XML: "0.966" (percent) is "9,66 ‰".
 */
export function formatPerMil(value: string | null | undefined): string {
  const percent = Number(value)

  if (value === null || value === undefined || value === '' || !Number.isFinite(percent)) {
    return ''
  }

  // Ten times the percentage, without floating point drift: shift the decimal point by one.
  const [whole = '0', fraction = ''] = value.split('.')
  const shifted = `${whole}${(fraction + '0').slice(0, 1)}.${fraction.slice(1)}`.replace(/^0+(?=\d)/, '')

  return `${formatDecimal(shifted.endsWith('.') ? shifted.slice(0, -1) : shifted)} ‰`
}

/** "990000000" becomes "990.000.000". */
export function formatInteger(value: number): string {
  return group(String(value))
}

/** "2030-01-19" becomes "19/01/2030", without going through Date and its time zones. */
export function formatDate(value: string | null | undefined): string {
  const match = value ? /^(\d{4})-(\d{2})-(\d{2})/.exec(value) : null

  return match ? `${match[3]}/${match[2]}/${match[1]}` : ''
}

/**
 * Reads a number typed the Colombian way ("1.234.567,5") or with a decimal point ("1234567.5")
 * and returns it as an API decimal string ("1234567.5"). Returns null when it is not a number.
 */
export function parseDecimal(text: string, scale: number): string | null {
  let value = text.replace(/[\s$%]/g, '')

  if (value === '') {
    return ''
  }

  if (value.includes(',')) {
    value = value.replace(/\./g, '').replace(',', '.')
  } else if (/^\d{1,3}(\.\d{3})+$/.test(value)) {
    value = value.replace(/\./g, '')
  }

  const pattern = new RegExp(`^\\d+(\\.\\d{0,${scale}})?$`)

  if (!pattern.test(value)) {
    return null
  }

  return value.endsWith('.') ? value.slice(0, -1) : value
}

const COLOMBIA = 'America/Bogota'

/** Today's date in Colombia, as YYYY-MM-DD. */
export function todayInColombia(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: COLOMBIA, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
}

/** "2026-11-17" plus 30 days, as YYYY-MM-DD. */
export function addDays(date: string, days: number): string {
  const [year = 1970, month = 1, day = 1] = date.split('-').map(Number)

  return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10)
}

/** An instant shown in Colombian time: "17/11/2026, 10:15". */
export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return ''
  }

  return new Intl.DateTimeFormat('es-CO', {
    timeZone: COLOMBIA,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(value))
}

/** "10:15:30" becomes "10:15". */
export function formatTime(value: string | null | undefined): string {
  return value ? value.slice(0, 5) : ''
}

/** A decimal string as the user edits it: "1234567.5" becomes "1234567,5". */
export function toEditableDecimal(value: string): string {
  const decimal = parts(value)

  if (!decimal) {
    return value
  }

  const fraction = decimal.fraction.replace(/0+$/, '')

  return `${decimal.negative ? '-' : ''}${decimal.integer}${fraction ? DECIMALS + fraction : ''}`
}

/** The language of the interface, for month and day names. */
function interfaceLocale(): string {
  return String(i18n.global.locale.value)
}

/** "2026-06" becomes "Jun" (short) or "June 2026" (long), in the language of the interface. */
export function formatMonth(month: string, style: 'short' | 'long' = 'short'): string {
  const [year = 1970, number = 1] = month.split('-').map(Number)
  const options: Intl.DateTimeFormatOptions = style === 'short'
    ? { month: 'short', timeZone: 'UTC' }
    : { month: 'long', year: 'numeric', timeZone: 'UTC' }

  return new Intl.DateTimeFormat(interfaceLocale(), options).format(new Date(Date.UTC(year, number - 1, 1)))
}

/** "2026-10-01" to "2026-10-19" becomes "Oct 1–19", calendar dates without time zones. */
export function formatDateRange(from: string, to: string): string {
  const toDate = (value: string) => {
    const [year = 1970, month = 1, day = 1] = value.split('-').map(Number)

    return new Date(Date.UTC(year, month - 1, day))
  }

  return new Intl.DateTimeFormat(interfaceLocale(), { month: 'short', day: 'numeric', timeZone: 'UTC' }).formatRange(toDate(from), toDate(to))
}

/** An amount for the axis of a chart, in Colombian compact notation: "$ 12 M", "$ 450 mil". */
export function formatCompactMoney(value: number): string {
  return `$ ${new Intl.NumberFormat('es-CO', { notation: 'compact', maximumFractionDigits: 1 }).format(value)}`
}

/** A percent change as the API sends it ("-12.5") with its sign: "−12,5 %", "+3 %". */
export function formatChange(value: string): string {
  const negative = value.startsWith('-')

  return `${negative ? '−' : '+'}${formatPercent(negative ? value.slice(1) : value)}`
}
