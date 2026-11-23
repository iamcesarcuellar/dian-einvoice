import type { Calculation, InvoiceInput, NumberTemplate, Paginated, Reference, Tax } from '@/api/types'
import type { VueWrapper } from '@vue/test-utils'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import InvoiceEditor from '@/components/invoice/InvoiceEditor.vue'
import TotalsPanel from '@/components/invoice/TotalsPanel.vue'
import { i18n } from '@/i18n'
import { useSessionStore } from '@/stores/session'

// The API of the editor, faked: the catalogs it loads and the preview that gets the draft. The
// preview charges 1.000 per line it gets, so the totals on screen tell how many lines were sent.
const fake = vi.hoisted(() => {
  function page<T>(data: T[]): Paginated<T> {
    return { data, meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length, from: data.length > 0 ? 1 : null, to: data.length } }
  }

  function tax(id: string, name: string, rate: string, dianCode = '01'): Tax {
    return { id, dian_code: dianCode, name, rate, is_active: true, is_system: true, created_at: '2026-11-01T00:00:00Z', updated_at: '2026-11-01T00:00:00Z' }
  }

  function calculation(input: InvoiceInput): Calculation {
    const amount = (input.lines.length * 1000).toFixed(2)

    return {
      lines: input.lines.map((_, index) => ({ position: index + 1, discount_amount: '0.00', line_extension_amount: '1000.00', tax_amount: '0.00' })),
      taxes: [],
      withholdings: [],
      totals: {
        subtotal_amount: amount,
        discount_total: '0.00',
        line_extension_amount: amount,
        tax_exclusive_amount: amount,
        tax_total: '0.00',
        tax_inclusive_amount: amount,
        payable_amount: amount,
        withholding_total: '0.00',
        amount_due: amount,
      },
    }
  }

  const template: NumberTemplate = {
    id: 'template-setp',
    document_type: 'invoice',
    prefix: 'SETP',
    from_number: 990000000,
    to_number: 995000000,
    next_number: 990000046,
    resolution_number: '18760000001',
    resolution_date: '2019-01-19',
    valid_from: '2019-01-19',
    valid_to: '2030-01-19',
    technical_key_set: true,
    technical_key_last4: 'c3e0',
    is_default: true,
    is_active: true,
    status: 'active',
    usage: { used: 45, total: 5000001 },
    created_at: '2026-11-01T00:00:00Z',
    updated_at: '2026-11-01T00:00:00Z',
  }

  const reference: Reference = {
    identification_types: [],
    organization_types: [],
    fiscal_responsibilities: [],
    tax_schemes: [],
    tax_types: [],
    withholding_types: [],
    units: [{ code: '94', name: 'Unit' }],
    payment_forms: [
      { code: '1', name: 'Cash' },
      { code: '2', name: 'Credit' },
    ],
    payment_means: [{ code: '10', name: 'Cash' }],
    credit_note_concepts: [],
    debit_note_concepts: [],
    departments: [],
  }

  return {
    page,
    // Sorted by rate, as the API sends them: new lines take the first active IVA.
    taxes: [tax('tax-iva-19', 'IVA 19 %', '19.0000'), tax('tax-iva-5', 'IVA 5 %', '5.0000'), tax('tax-inc-8', 'INC 8 %', '8.0000', '04')],
    template,
    reference,
    preview: vi.fn((input: InvoiceInput) => Promise.resolve(calculation(input))),
  }
})

vi.mock('@/api/resources', () => ({
  contactsApi: { list: () => Promise.resolve(fake.page([])) },
  itemsApi: { list: () => Promise.resolve(fake.page([])) },
  numberTemplatesApi: { list: () => Promise.resolve(fake.page([fake.template])) },
  taxesApi: { list: () => Promise.resolve(fake.page(fake.taxes)) },
  withholdingsApi: { list: () => Promise.resolve(fake.page([])) },
  documentsApi: () => ({ preview: fake.preview, create: vi.fn(), update: vi.fn(), issue: vi.fn(), remove: vi.fn() }),
}))

vi.mock('@/api/reference', () => ({
  referenceApi: { get: () => Promise.resolve(fake.reference) },
}))

enableAutoUnmount(afterEach)

// The draft goes to the preview 350 ms after the last change: the tests move the clock instead.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

afterEach(() => {
  vi.useRealTimers()
})

/** Lets the debounce of the preview run, and the answer reach the screen. */
async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(400)
  await flushPromises()
}

/** The editor of a new invoice at its route, with the plugins of the app. */
async function openNewInvoice(): Promise<VueWrapper> {
  const Elsewhere = defineComponent({ render: () => null })
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/invoices', name: 'invoices', component: Elsewhere },
      { path: '/invoices/new', name: 'invoice-new', component: InvoiceEditor, props: { invoice: null } },
      { path: '/invoices/:id', name: 'invoice', component: Elsewhere },
    ],
  })
  const pinia = createPinia()
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  useSessionStore(pinia).selectCompany('company-demo-comercial')
  await router.push('/invoices/new')

  const wrapper = mount(RouterView, { global: { plugins: [router, pinia, i18n, [VueQueryPlugin, { queryClient }]] } })
  await settle()

  return wrapper
}

function descriptionInput(wrapper: VueWrapper, line: number) {
  const input = wrapper.findAll<HTMLInputElement>('input[id$="-description"]')[line - 1]

  if (!input) {
    throw new Error(`There is no line ${line}`)
  }

  return input
}

function descriptions(wrapper: VueWrapper): string[] {
  return wrapper.findAll<HTMLInputElement>('input[id$="-description"]').map((input) => input.element.value)
}

async function typeDescription(wrapper: VueWrapper, line: number, text: string): Promise<void> {
  await descriptionInput(wrapper, line).setValue(text)
  await settle()
}

async function addLine(wrapper: VueWrapper): Promise<void> {
  const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Add line')

  if (!button) {
    throw new Error('There is no Add line button')
  }

  await button.trigger('click')
  await settle()
}

async function removeLine(wrapper: VueWrapper, line: number): Promise<void> {
  const button = wrapper.findAll('button[aria-label="Remove line"]')[line - 1]

  if (!button) {
    throw new Error(`There is no line ${line}`)
  }

  await button.trigger('click')
  await settle()
}

/** The lines of the last draft the editor sent to the preview. */
function previewedLines(): Array<Pick<InvoiceInput['lines'][number], 'description' | 'tax_id'>> {
  const input = fake.preview.mock.lastCall?.[0]

  return (input?.lines ?? []).map((line) => ({ description: line.description, tax_id: line.tax_id }))
}

function subtotal(wrapper: VueWrapper): string | undefined {
  const row = wrapper
    .getComponent(TotalsPanel)
    .findAll('dl > div')
    .find((candidate) => candidate.get('dt').text() === 'Subtotal')

  return row?.get('dd').text()
}

describe('InvoiceEditor lines', () => {
  it('adds lines at the end and below the current one, removes one, and sends the rest in order', async () => {
    const wrapper = await openNewInvoice()

    expect(descriptions(wrapper)).toEqual([''])

    await typeDescription(wrapper, 1, 'Ergonomic office chair')
    await addLine(wrapper)
    await typeDescription(wrapper, 2, 'Letter paper, 500 sheets')

    // Enter in a line adds a blank one right below it; a blank line is not sent.
    await descriptionInput(wrapper, 1).trigger('keydown', { key: 'Enter' })
    await settle()

    expect(descriptions(wrapper)).toEqual(['Ergonomic office chair', '', 'Letter paper, 500 sheets'])
    expect(previewedLines().map((line) => line.description)).toEqual(['Ergonomic office chair', 'Letter paper, 500 sheets'])

    await typeDescription(wrapper, 2, 'Local delivery')
    await removeLine(wrapper, 1)

    expect(descriptions(wrapper)).toEqual(['Local delivery', 'Letter paper, 500 sheets'])
    // Every new line took the default tax, the first active IVA.
    expect(previewedLines()).toEqual([
      { description: 'Local delivery', tax_id: 'tax-iva-19' },
      { description: 'Letter paper, 500 sheets', tax_id: 'tax-iva-19' },
    ])
    expect(subtotal(wrapper)).toBe('$ 2.000,00')
  })

  it('keeps one blank line when the last one is removed, and its amount leaves the totals', async () => {
    const wrapper = await openNewInvoice()

    await typeDescription(wrapper, 1, 'Ergonomic office chair')

    expect(subtotal(wrapper)).toBe('$ 1.000,00')

    await removeLine(wrapper, 1)

    expect(descriptions(wrapper)).toEqual([''])
    expect(subtotal(wrapper)).toBe('$ 0,00')
  })
})
