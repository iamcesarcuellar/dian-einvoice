import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MoneyInput from '@/components/MoneyInput.vue'

/** A MoneyInput bound with v-model, as the editors use it: the model is the API decimal string. */
function mountMoney(modelValue: string) {
  const wrapper = mount(MoneyInput, {
    props: {
      modelValue,
      'onUpdate:modelValue': (value: string) => wrapper.setProps({ modelValue: value }),
    },
  })

  return { wrapper, input: wrapper.get('input') }
}

/** Types a text the way a user does: focus, input, then leave the field. */
async function type(text: string) {
  const { wrapper, input } = mountMoney('')

  await input.trigger('focus')
  await input.setValue(text)
  await flushPromises()
  const typed = wrapper.props('modelValue')
  await input.trigger('blur')
  await flushPromises()

  return { typed, model: wrapper.props('modelValue'), shown: input.element.value }
}

describe('MoneyInput', () => {
  it('shows an API amount the Colombian way, after the peso sign', () => {
    const { wrapper, input } = mountMoney('1234567.5')

    expect(input.element.value).toBe('1.234.567,50')
    expect(wrapper.text()).toContain('$')
  })

  it.each([
    ['1.234.567,5', '1234567.5', '1.234.567,50'],
    ['1234567,5', '1234567.5', '1.234.567,50'],
    ['1234567.5', '1234567.5', '1.234.567,50'],
    ['1.234', '1234', '1.234'],
    ['2500', '2500', '2.500'],
    ['$ 45.000', '45000', '45.000'],
    ['0,05', '0.05', '0,05'],
    ['', '', ''],
  ])('reads "%s" as %s and shows %s once the field is left', async (text, model, shown) => {
    const result = await type(text)

    expect(result.typed).toBe(model)
    expect(result.model).toBe(model)
    expect(result.shown).toBe(shown)
  })

  // The API answers what is wrong with it; the field never turns it into another amount.
  it.each(['abc', '-5', '1,234.56', '12.34,5', '1,5,0', '0,1234567'])('keeps "%s" as typed: it is not an amount', async (text) => {
    const result = await type(text)

    expect(result.model).toBe(text)
    expect(result.shown).toBe(text)
  })

  it('shows the plain number while it is being edited', async () => {
    const { input } = mountMoney('1234567.5')

    await input.trigger('focus')

    expect(input.element.value).toBe('1234567,5')
  })
})
