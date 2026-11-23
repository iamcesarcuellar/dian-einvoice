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

/**
 * Colombian pesos, always with two decimals so a column of amounts lines up: "$ 1.234.567,50",
 * "$ 602.140,00". A unit price keeps the decimals it has beyond two: "$ 21.500,125".
 */
export function formatMoney(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return ''
  }

  const decimal = parts(value)

  if (!decimal) {
    return `$ ${value}`
  }

  const fraction = decimal.fraction.replace(/0+$/, '').padEnd(2, '0')

  return `$ ${decimal.negative ? '-' : ''}${group(decimal.integer)}${DECIMALS}${fraction}`
}

/**
 * An amount a document takes off, such as a discount or a withholding: "− $ 45.000,00". Zero takes no sign:
 * "$ 0,00".
 */
export function formatDeduction(value: string | null | undefined): string {
  const money = formatMoney(value || '0')
  const decimal = parts(value || '0')

  return decimal !== null && /^0+$/.test(decimal.integer) && /^0*$/.test(decimal.fraction) ? money : `− ${money}`
}

/**
 * Whole pesos, for the figures of the dashboard: "9798831.87" becomes "$ 9.798.832", rounded half
 * away from zero. The exact amount (formatMoney) goes in the tooltip of each figure.
 */
export function formatWholeMoney(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return ''
  }

  const decimal = parts(value)

  if (!decimal) {
    return `$ ${value}`
  }

  const integer = (decimal.fraction[0] ?? '0') >= '5' ? (BigInt(decimal.integer) + 1n).toString() : decimal.integer

  return `$ ${decimal.negative && integer !== '0' ? '-' : ''}${group(integer)}`
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

/**
 * A value of a loosely typed payload, such as the payload of a timeline event, as text: a string or
 * a number as it is, anything else (missing, or an object) as the fallback.
 */
export function payloadText(value: unknown, fallback = ''): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value) : fallback
}

/** "990000000" becomes "990.000.000". */
export function formatInteger(value: number): string {
  return group(String(value))
}

/** A text of the messages of the interface language, such as the pattern of a date. */
function message(key: string, values: Record<string, string>): string {
  return i18n.global.t(key, values)
}

/**
 * "2030-01-19" as the language of the interface writes a calendar date: "19 Jan 2030" in English,
 * with the month in letters so that no reader takes the day for the month. The pattern and the
 * month names are messages of the language (format.date and format.months), and no Date or time
 * zone is involved.
 */
export function formatDate(value: string | null | undefined): string {
  const match = value ? /^(\d{4})-(\d{2})-(\d{2})/.exec(value) : null

  if (!match) {
    return ''
  }

  const [, year = '', mm = '', dd = ''] = match

  return message('format.date', { day: String(Number(dd)), dd, month: message(`format.months.${mm}`, {}), mm, year })
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
    // A decimal comma, with any dots before it grouping thousands three digits at a time. Anything
    // else, such as "1,234.56", is not taken for some other amount.
    const match = /^(\d+|\d{1,3}(?:\.\d{3})+),(\d*)$/.exec(value)

    if (!match) {
      return null
    }

    value = `${(match[1] ?? '').replace(/\./g, '')}.${match[2] ?? ''}`
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

/** An instant in Colombian time, as the language of the interface writes it: "17 Nov 2026, 10:15". */
export function formatDateTime(value: string | null | undefined): string {
  const instant = value ? new Date(value) : null

  if (instant === null || Number.isNaN(instant.getTime())) {
    return ''
  }

  const local = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: COLOMBIA,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(instant)
      .map((part) => [part.type, part.value]),
  )

  return message('format.dateTime', {
    date: formatDate(`${local.year}-${local.month}-${local.day}`),
    time: `${local.hour}:${local.minute}`,
  })
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
  const options: Intl.DateTimeFormatOptions = style === 'short' ? { month: 'short', timeZone: 'UTC' } : { month: 'long', year: 'numeric', timeZone: 'UTC' }

  return new Intl.DateTimeFormat(interfaceLocale(), options).format(new Date(Date.UTC(year, number - 1, 1)))
}

/**
 * "2026-10-01" to "2026-10-19" becomes "1–19 Oct", and "2026-09-28" to "2026-10-03" "28 Sep – 3 Oct":
 * calendar dates in the order and with the month names of format.date, without time zones.
 */
export function formatDateRange(from: string, to: string): string {
  const day = (value: string) => {
    const [, month = '01', date = '01'] = value.split('-')

    return { day: String(Number(date)), dd: date, month: message(`format.months.${month}`, {}), mm: month }
  }
  const start = day(from)
  const end = day(to)

  return from.slice(0, 7) === to.slice(0, 7)
    ? message('format.sameMonthRange', { from: start.day, to: end.day, month: end.month })
    : message('format.range', { from: message('format.dayMonth', start), to: message('format.dayMonth', end) })
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
