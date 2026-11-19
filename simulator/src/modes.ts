// Failure modes, set through the admin API for the requests of one NIT, or for every NIT with "*".
// Each mode can be limited to the next N requests; then the NIT is back to normal.
import type { Mode, ModeName, State } from './state.ts'

export const MODE_NAMES: readonly ModeName[] = ['normal', 'reject', 'no_response', 'error_500', 'slow', 'intermittent']

export const ALL = '*'

export function normalMode(): Mode {
  return { mode: 'normal', rule: null, delay_ms: null, failure_rate: null, remaining: null, updated_at: new Date().toISOString() }
}

/** The mode a NIT sees now, its own or the one for every NIT. */
export function currentMode(state: State, nit: string): Mode & { scope: 'company' | 'all' | 'default' } {
  if (state.modes[nit]) {
    return { ...state.modes[nit], scope: 'company' }
  }

  if (state.modes[ALL]) {
    return { ...state.modes[ALL], scope: 'all' }
  }

  return { ...normalMode(), scope: 'default' }
}

/**
 * The mode that applies to one request, or null for normal behavior; counting it down. A
 * rejection only applies to SendBillSync, since GetStatus does not validate anything.
 */
export function takeMode(state: State, nit: string | null, operation: string): Mode | null {
  const key = nit !== null && state.modes[nit] ? nit : ALL
  const mode = state.modes[key]

  if (mode === undefined || mode.mode === 'normal' || (mode.mode === 'reject' && operation !== 'SendBillSync')) {
    return null
  }

  const applied = { ...mode }

  if (mode.remaining !== null) {
    mode.remaining -= 1

    if (mode.remaining <= 0) {
      delete state.modes[key]
    }
  }

  return applied
}
