import type { Calculation, Invoice, InvoiceInput, NumberTemplate, Paginated, Reference, Tax } from '@/api/types'
import type { VueWrapper } from '@vue/test-utils'
import type { Router } from 'vue-router'
import { VueQueryPlugin } from '@tanstack/vue-query'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import InvoiceEditor from '@/components/invoice/InvoiceEditor.vue'
import TotalsPanel from '@/components/invoice/TotalsPanel.vue'
import { useConfirmState } from '@/composables/useConfirm'
import { i18n } from '@/i18n'
import { queryClient } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

// The API of the editor, faked: the catalogs it loads, the preview that gets the draft, and the
// draft saved and issued. The preview charges 1.000 per line it gets, so the totals on screen tell
// how many lines were sent.
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

  // The numbering and the taxes answer at once, unless a test holds them back, as a cold function would.
  const late = { numbering: Promise.resolve(), taxes: Promise.resolve() }

  /** Holds back the numbering or the taxes until the returned function is called. */
  function hold(options: keyof typeof late): () => void {
    let release!: () => void
    late[options] = new Promise<void>((resolve) => {
      release = resolve
    })

    return release
  }

  return {
    page,
    // Sorted by rate, as the API sends them: new lines take the first active IVA.
    taxes: [tax('tax-iva-19', 'IVA 19 %', '19.0000'), tax('tax-iva-5', 'IVA 5 %', '5.0000'), tax('tax-inc-8', 'INC 8 %', '8.0000', '04')],
    template,
    reference,
    late,
    hold,
    preview: vi.fn((input: InvoiceInput) => Promise.resolve(calculation(input))),
    create: vi.fn<(input: InvoiceInput) => Promise<Invoice>>(() => Promise.resolve({ id: 'invoice-draft', status: 'draft', full_number: null } as Invoice)),
    issue: vi.fn((id: string) => Promise.resolve({ id, status: 'issued', full_number: 'SETP990000046' } as Invoice)),
  }
})

vi.mock('@/api/resources', () => ({
  contactsApi: { list: () => Promise.resolve(fake.page([])) },
  itemsApi: { list: () => Promise.resolve(fake.page([])) },
  numberTemplatesApi: { list: () => fake.late.numbering.then(() => fake.page([fake.template])) },
  taxesApi: { list: () => fake.late.taxes.then(() => fake.page(fake.taxes)) },
  withholdingsApi: { list: () => Promise.resolve(fake.page([])) },
  documentsApi: () => ({ preview: fake.preview, create: fake.create, update: vi.fn(), issue: fake.issue, remove: vi.fn() }),
}))

vi.mock('@/api/reference', () => ({
  referenceApi: { get: () => Promise.resolve(fake.reference) },
}))

enableAutoUnmount(afterEach)

// The draft goes to the preview 350 ms after the last change: the tests move the clock instead.
beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

// The query client is the app's, so what the editor refreshes after a save or an issue is refetched
// as in the app; each test starts with it empty, and answers a question left open.
afterEach(() => {
  vi.useRealTimers()
  queryClient.clear()
  fake.late.numbering = Promise.resolve()
  fake.late.taxes = Promise.resolve()
  useConfirmState().settle(false)
})

/** The numbering and the taxes answer two seconds after the editor opens, as cold functions do, or when the test lets them. */
function lateOptions(): { numbering: () => void; taxes: () => void } {
  const late = { numbering: fake.hold('numbering'), taxes: fake.hold('taxes') }
  setTimeout(late.numbering, 2_000)
  setTimeout(late.taxes, 2_000)

  return late
}

/** Lets the debounce of the preview run, and the answer reach the screen. */
async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(400)
  await flushPromises()
}

/** The editor of a new invoice at its route, with the plugins of the app. */
async function openNewInvoice(): Promise<{ wrapper: VueWrapper; router: Router }> {
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

  useSessionStore(pinia).selectCompany('company-demo-comercial')
  await router.push('/invoices/new')

  // Attached to the document, where the dialogs open.
  const wrapper = mount(RouterView, { attachTo: document.body, global: { plugins: [router, pinia, i18n, [VueQueryPlugin, { queryClient }]] } })
  await settle()

  return { wrapper, router }
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

/** A button of the editor's header, by its text. */
function headerButton(wrapper: VueWrapper, name: string): HTMLButtonElement {
  const button = wrapper.findAll<HTMLButtonElement>('button').find((candidate) => candidate.text() === name)

  if (!button) {
    throw new Error(`There is no ${name} button`)
  }

  return button.element
}

/** A button of the open dialog, by its text. */
function dialogButton(name: string): HTMLButtonElement {
  const button = [...document.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find((candidate) => candidate.textContent?.trim() === name)

  if (!button) {
    throw new Error(`There is no ${name} button in a dialog`)
  }

  return button
}

/** Clicks a button as soon as it is enabled, as someone who does not wait for the page would; the clock moves until then. */
async function clickWhenEnabled(button: HTMLButtonElement): Promise<void> {
  for (let waited = 0; button.disabled; waited += 100) {
    if (waited >= 5_000) {
      throw new Error(`${button.textContent?.trim()} stayed disabled`)
    }

    await vi.advanceTimersByTimeAsync(100)
    await flushPromises()
  }

  button.click()
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
    const { wrapper } = await openNewInvoice()

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
    const { wrapper } = await openNewInvoice()

    await typeDescription(wrapper, 1, 'Ergonomic office chair')

    expect(subtotal(wrapper)).toBe('$ 1.000,00')

    await removeLine(wrapper, 1)

    expect(descriptions(wrapper)).toEqual([''])
    expect(subtotal(wrapper)).toBe('$ 0,00')
  })
})

// The numbering and the taxes answer late, and the user saves or issues as soon as the editor lets
// them. A line written by hand takes the default tax, the first active IVA, and a new invoice the
// default numbering.
describe('InvoiceEditor while its numbering and taxes load', () => {
  const sent = { number_template_id: 'template-setp', lines: [{ description: 'Consulting, November', tax_id: 'tax-iva-19' }] }

  it('saves a draft only once they are in, with them, and opens it without asking to leave', async () => {
    const late = lateOptions()
    const { wrapper, router } = await openNewInvoice()

    await typeDescription(wrapper, 1, 'Consulting, November')
    await clickWhenEnabled(headerButton(wrapper, 'Save draft'))
    // Whatever is still late answers after the save.
    late.numbering()
    late.taxes()
    await settle()

    expect.soft(fake.create).toHaveBeenCalledTimes(1)
    expect.soft(fake.create.mock.calls[0]?.[0]).toMatchObject(sent)
    expect.soft(useConfirmState().state.open, 'the "Leave without saving?" question').toBe(false)
    expect.soft(router.currentRoute.value.fullPath).toBe('/invoices/invoice-draft')
  })

  it('issues an invoice only once they are in, with them, and opens it without asking to leave', async () => {
    const late = lateOptions()
    const { wrapper, router } = await openNewInvoice()

    await typeDescription(wrapper, 1, 'Consulting, November')
    await clickWhenEnabled(headerButton(wrapper, 'Issue invoice'))
    await clickWhenEnabled(dialogButton('Issue invoice'))
    // Whatever is still late answers after the issue: first the numbering, which the editor
    // refreshes before it opens the invoice, then the taxes.
    late.numbering()
    await settle()
    late.taxes()
    await settle()

    expect.soft(fake.create).toHaveBeenCalledTimes(1)
    expect.soft(fake.create.mock.calls[0]?.[0]).toMatchObject(sent)
    expect.soft(fake.issue).toHaveBeenCalledWith('invoice-draft')
    expect.soft(useConfirmState().state.open, 'the "Leave without saving?" question').toBe(false)
    expect.soft(router.currentRoute.value.fullPath).toBe('/invoices/invoice-draft')
  })

  it('takes them as defaults, not as changes: leaving asks nothing', async () => {
    lateOptions()
    const { router } = await openNewInvoice()

    await vi.advanceTimersByTimeAsync(2_000)
    await settle()

    expect(fake.preview.mock.lastCall?.[0].number_template_id).toBe('template-setp')

    await router.push('/invoices')
    await settle()

    expect(useConfirmState().state.open).toBe(false)
    expect(router.currentRoute.value.fullPath).toBe('/invoices')
  })
})
