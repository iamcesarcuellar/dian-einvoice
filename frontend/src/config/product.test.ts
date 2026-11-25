import { describe, expect, it } from 'vitest'
import { product } from './product'

describe('product', () => {
  it('links the API reference in the language of the interface', () => {
    expect(product.apiReferenceUrl('en')).toBe(`${product.apiUrl}/docs/api`)
    expect(product.apiReferenceUrl('es')).toBe(`${product.apiUrl}/docs/api/es`)
  })
})
