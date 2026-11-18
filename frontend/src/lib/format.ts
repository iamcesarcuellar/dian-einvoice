// Colombian number formatting done on strings: money, quantities and rates never become floats.

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
