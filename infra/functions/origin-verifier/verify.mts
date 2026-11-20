// What the origin verifier decides, apart from Lambda and Secrets Manager, so it is tested without either: a request
// comes through the edge when its X-Origin-Verify header holds the current or the previous value of the secret of
// the edge. Both are accepted, so a rotation of the secret cuts no request while the distribution moves from one to
// the other (docs/infrastructure.md). The values are kept in memory for a few seconds and read again sooner when a
// request brings a value they do not hold, at most once per refresh interval, so a rotation is seen at once and a
// flood of wrong values reads the secret no more often.
import { createHash, timingSafeEqual } from 'node:crypto'

/** The values of the secret of the edge: AWSCURRENT, and AWSPREVIOUS until a second rotation drops it. */
export interface SecretValues {
  readonly current: string
  readonly previous: string | null
}

export type ReadSecret = () => Promise<SecretValues>

export interface VerifierOptions {
  /** How long the values read are trusted. */
  readonly keepMilliseconds: number
  /** The least time between two reads caused by a value the cached ones do not hold. */
  readonly refreshMilliseconds: number
  readonly now?: () => number
}

/**
 * Whether two strings are equal, in a time that depends on neither: both are reduced to their SHA-256 first, so even
 * their lengths do not show.
 */
export function constantTimeEquals(candidate: string, expected: string): boolean {
  return timingSafeEqual(createHash('sha256').update(candidate, 'utf8').digest(), createHash('sha256').update(expected, 'utf8').digest())
}

/** Whether a value is the current or the previous one, comparing it with both whatever the first comparison gave. */
export function matches(candidate: string, values: SecretValues): boolean {
  const current = constantTimeEquals(candidate, values.current)
  const previous = values.previous !== null && values.previous !== '' && constantTimeEquals(candidate, values.previous)

  return current || previous
}

export class OriginVerifier {
  private readonly read: ReadSecret
  private readonly keep: number
  private readonly refresh: number
  private readonly now: () => number
  private values: SecretValues | null = null
  private readAt = Number.NEGATIVE_INFINITY

  constructor(read: ReadSecret, options: VerifierOptions) {
    this.read = read
    this.keep = options.keepMilliseconds
    this.refresh = options.refreshMilliseconds
    this.now = options.now ?? Date.now
  }

  /** Whether the header holds a value of the secret; an empty or missing header never does. */
  async verify(candidate: string | undefined): Promise<boolean> {
    if (candidate === undefined || candidate === '') {
      return false
    }

    let values = await this.current(false)

    if (!matches(candidate, values) && this.now() - this.readAt >= this.refresh) {
      values = await this.current(true)
    }

    return matches(candidate, values)
  }

  /**
   * The values, read again when they are older than the time they are kept for or when forced. When a read fails,
   * the values held are used until the next attempt, so a hiccup of Secrets Manager refuses nothing that came
   * through the edge; without values held, the error stands and API Gateway answers an error.
   */
  private async current(force: boolean): Promise<SecretValues> {
    if (this.values !== null && !force && this.now() - this.readAt < this.keep) {
      return this.values
    }

    try {
      const values = await this.read()

      if (values.current === '') {
        throw new Error('The secret of the edge has no current value.')
      }

      this.values = values
    } catch (error) {
      if (this.values === null) {
        throw error
      }

      console.error(JSON.stringify({ message: 'The secret of the edge could not be read again; the values held are used.', error: error instanceof Error ? error.message : String(error) }))
    }

    this.readAt = this.now()

    return this.values
  }
}
