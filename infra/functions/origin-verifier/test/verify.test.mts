// The decisions of the origin verifier (verify.mts), with a secret answered from memory and a clock of the test.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { constantTimeEquals, matches, OriginVerifier, type SecretValues } from '../verify.mts'

const OLD = 'old-value-of-the-secret-of-the-edge-0000000000000000000000000000'
const NEW = 'new-value-of-the-secret-of-the-edge-1111111111111111111111111111'

/** A secret whose values the test changes, counting its reads, and a clock the test moves. */
function fixture(values: SecretValues): { verifier: OriginVerifier, reads: () => number, rotate: (next: SecretValues) => void, advance: (milliseconds: number) => void, fail: (failing: boolean) => void } {
  let current = values
  let reads = 0
  let now = 1_000_000
  let failing = false
  const verifier = new OriginVerifier(async () => {
    reads++

    if (failing) {
      throw new Error('Secrets Manager does not answer.')
    }

    return current
  }, { keepMilliseconds: 30_000, refreshMilliseconds: 5_000, now: () => now })

  return {
    verifier,
    reads: () => reads,
    rotate: (next) => { current = next },
    advance: (milliseconds) => { now += milliseconds },
    fail: (next) => { failing = next },
  }
}

describe('the origin verifier', () => {
  it('compares in constant time: equal values only, whatever their length', () => {
    assert.equal(constantTimeEquals(NEW, NEW), true)
    assert.equal(constantTimeEquals(NEW, OLD), false)
    assert.equal(constantTimeEquals('', NEW), false)
    assert.equal(constantTimeEquals(`${NEW}x`, NEW), false)
    assert.equal(constantTimeEquals(NEW.slice(0, -1), NEW), false)
  })

  it('accepts the current and the previous value of the secret, and nothing else', async () => {
    const { verifier } = fixture({ current: NEW, previous: OLD })
    assert.equal(await verifier.verify(NEW), true)
    assert.equal(await verifier.verify(OLD), true)
    assert.equal(await verifier.verify('a-value-the-client-made-up'), false)
    assert.equal(await verifier.verify(''), false)
    assert.equal(await verifier.verify(undefined), false)
    assert.equal(matches(NEW, { current: NEW, previous: null }), true)
    assert.equal(matches(OLD, { current: NEW, previous: '' }), false)
  })

  it('keeps the values in memory for a while, reading again only when they are old or a value is missing', async () => {
    const { verifier, reads, advance } = fixture({ current: NEW, previous: null })
    assert.equal(await verifier.verify(NEW), true)
    assert.equal(await verifier.verify(NEW), true)
    assert.equal(reads(), 1)

    // A wrong value right after a read does not read again: a flood of them reads at most once per interval.
    assert.equal(await verifier.verify('wrong'), false)
    assert.equal(await verifier.verify('wrong'), false)
    assert.equal(reads(), 1)
    advance(5_000)
    assert.equal(await verifier.verify('wrong'), false)
    assert.equal(reads(), 2)

    advance(29_999)
    assert.equal(await verifier.verify(NEW), true)
    assert.equal(reads(), 2)
    advance(1)
    assert.equal(await verifier.verify(NEW), true)
    assert.equal(reads(), 3)
  })

  it('sees a rotation at once: the new value reads the secret again, and the old one stays valid as the previous', async () => {
    const { verifier, reads, rotate, advance } = fixture({ current: OLD, previous: null })
    assert.equal(await verifier.verify(OLD), true)

    // The secret rotates; the distribution starts sending the new value while some edges still send the old one.
    rotate({ current: NEW, previous: OLD })
    advance(6_000)
    assert.equal(await verifier.verify(NEW), true)
    assert.equal(reads(), 2)
    assert.equal(await verifier.verify(OLD), true)

    // After a second rotation the first value is refused.
    rotate({ current: 'third-value-of-the-secret-of-the-edge-22222222222222222222222', previous: NEW })
    advance(30_000)
    assert.equal(await verifier.verify(OLD), false)
    assert.equal(await verifier.verify(NEW), true)
  })

  it('keeps the values it holds when Secrets Manager fails, and fails without any', async () => {
    const empty = fixture({ current: NEW, previous: null })
    empty.fail(true)
    await assert.rejects(empty.verifier.verify(NEW), /does not answer/)

    const { verifier, fail, advance } = fixture({ current: NEW, previous: null })
    assert.equal(await verifier.verify(NEW), true)
    fail(true)
    advance(60_000)
    assert.equal(await verifier.verify(NEW), true)
    assert.equal(await verifier.verify('wrong'), false)
  })

  it('refuses a secret without a current value', async () => {
    const { verifier } = fixture({ current: '', previous: OLD })
    await assert.rejects(verifier.verify(OLD), /no current value/)
  })
})
