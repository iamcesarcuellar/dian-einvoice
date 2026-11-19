import type { Calculation } from '@/api/types'
import type { VueWrapper } from '@vue/test-utils'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TotalsPanel from '@/components/invoice/TotalsPanel.vue'
import { i18n } from '@/i18n'

// An answer of POST /invoices/preview of the local API, for the synthetic demo data of Demo
// Comercial: 2 office chairs with a 5 % discount and 10 packs of paper at IVA 19 %, 3 packs of
// coffee at IVA 5 %, with ReteFuente 2.5 % and ReteIVA 15 %.
const preview: Calculation = {
  lines: [
    { position: 1, discount_amount: '45000.00', line_extension_amount: '855000.00', tax_amount: '162450.00' },
    { position: 2, discount_amount: '0.00', line_extension_amount: '215000.00', tax_amount: '40850.00' },
    { position: 3, discount_amount: '0.00', line_extension_amount: '56700.00', tax_amount: '2835.00' },
  ],
  taxes: [
    { dian_code: '01', name: 'IVA', rate: '19.0000', taxable_amount: '1070000.00', tax_amount: '203300.00' },
    { dian_code: '01', name: 'IVA', rate: '5.0000', taxable_amount: '56700.00', tax_amount: '2835.00' },
  ],
  withholdings: [
    {
      withholding_id: '01m7tj4gqdcbgmhxnm2prt0y52',
      dian_code: '06',
      name: 'ReteFuente 2.5% (purchases)',
      rate: '2.5000',
      base_amount: '1126700.00',
      amount: '28167.50',
    },
    { withholding_id: '01m7tj4gqgsgzj1z5tfzgzrm1f', dian_code: '05', name: 'ReteIVA 15%', rate: '15.0000', base_amount: '206135.00', amount: '30920.25' },
  ],
  totals: {
    subtotal_amount: '1171700.00',
    discount_total: '45000.00',
    line_extension_amount: '1126700.00',
    tax_exclusive_amount: '1126700.00',
    tax_total: '206135.00',
    tax_inclusive_amount: '1332835.00',
    payable_amount: '1332835.00',
    withholding_total: '59087.75',
    amount_due: '1273747.25',
  },
}

/** Each row of the panel: its texts (name, and the base it applies to when shown) and its amount. */
function rows(wrapper: VueWrapper): string[][] {
  return wrapper.findAll('dl > div').map((row) => {
    const parts = row.findAll('dt > span')
    const name = parts.length > 0 ? parts.map((part) => part.text()) : [row.get('dt').text()]

    return [...name, row.get('dd').text()]
  })
}

describe('TotalsPanel', () => {
  it('shows the totals of a preview as the API calculated them: one row per tax rate, and the withholdings', () => {
    const wrapper = mount(TotalsPanel, {
      props: { totals: preview.totals, taxes: preview.taxes, withholdings: preview.withholdings },
      global: { plugins: [i18n] },
    })

    expect(rows(wrapper)).toEqual([
      ['Subtotal', '$ 1.171.700'],
      ['Discounts', '− $ 45.000'],
      ['IVA 19 %', 'on $ 1.070.000', '$ 203.300'],
      ['IVA 5 %', 'on $ 56.700', '$ 2.835'],
      ['Total', '$ 1.332.835'],
      ['ReteFuente 2.5% (purchases)', 'on $ 1.126.700', '− $ 28.167,50'],
      ['ReteIVA 15%', 'on $ 206.135', '− $ 30.920,25'],
      ['Net to receive', '$ 1.273.747,25'],
    ])
  })

  it('shows zeros before the first preview, and a notice instead of the figures when the draft cannot be calculated', async () => {
    const wrapper = mount(TotalsPanel, { props: { totals: null, taxes: [], withholdings: [] }, global: { plugins: [i18n] } })

    expect(rows(wrapper)).toEqual([
      ['Subtotal', '$ 0'],
      ['Discounts', '− $ 0'],
      ['Total', '$ 0'],
      ['Net to receive', '$ 0'],
    ])

    await wrapper.setProps({ pending: true, notice: 'Fix the highlighted values to see the totals.' })

    expect(wrapper.find('dl').exists()).toBe(false)
    expect(wrapper.text()).toContain('Fix the highlighted values to see the totals.')
    expect(wrapper.get('[role="status"]').text()).toBe('Calculating…')
  })
})
