import type { TooltipRow } from './ChartTooltip.vue'
import { h, render } from 'vue'
import ChartTooltip from './ChartTooltip.vue'

/**
 * The HTML of a chart hover card, as Unovis takes it. Cached by key: the card only changes with
 * its data (colors are theme variables, so a change of theme needs no new HTML).
 */
const cache = new Map<string, string>()

export function tooltipHtml(key: string, title: string, rows: TooltipRow[]): string {
  const cached = cache.get(key)

  if (cached !== undefined) {
    return cached
  }

  const host = document.createElement('div')

  render(h(ChartTooltip, { title, rows }), host)

  const html = host.innerHTML

  render(null, host)
  cache.set(key, html)

  return html
}
