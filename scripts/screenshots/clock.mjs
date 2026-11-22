// make screenshots only (scripts/screenshots/compose.yaml, through NODE_OPTIONS=--import): the story clock of the
// DIAN simulator, loaded before its code. Date.now() and new Date() move by SCREENSHOTS_CLOCK_OFFSET seconds, as the
// clock of the backend does, so the moments the simulator stamps (its activity, its answers) and its check of the
// timestamps of webhooks tell the same day; the clock keeps ticking.
const offset = Number.parseInt(process.env.SCREENSHOTS_CLOCK_OFFSET ?? '0', 10) * 1000

if (Number.isSafeInteger(offset) && offset !== 0) {
  const RealDate = Date
  const now = () => RealDate.now() + offset

  globalThis.Date = new Proxy(RealDate, {
    construct: (target, args, newTarget) => Reflect.construct(target, args.length === 0 ? [now()] : args, newTarget),
    apply: () => new RealDate(now()).toString(),
    get: (target, property, receiver) => (property === 'now' ? now : Reflect.get(target, property, receiver)),
  })
}
