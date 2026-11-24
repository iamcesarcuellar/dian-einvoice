import { describe, expect, it } from 'vitest'
import { holderFirst } from '@/lib/certificate'

describe('holderFirst', () => {
  it('puts the holder before the rest of the subject, as the API orders it', () => {
    expect(holderFirst('serialNumber=990000001, CN=Demo Comercial SAS TEST CERTIFICATE, O=Demo Comercial SAS, C=CO')).toBe(
      'Demo Comercial SAS TEST CERTIFICATE · serialNumber=990000001, O=Demo Comercial SAS, C=CO',
    )
  })

  it('keeps the order when the holder already comes first', () => {
    expect(holderFirst('CN=Demo Servicios SAS, O=Demo Servicios SAS, C=CO')).toBe('Demo Servicios SAS · O=Demo Servicios SAS, C=CO')
  })

  it('keeps a comma inside the holder’s name', () => {
    expect(holderFirst('serialNumber=1, CN=Andes, Café y Cacao SAS, C=CO')).toBe('Andes, Café y Cacao SAS · serialNumber=1, C=CO')
  })

  it('gives the name alone when the subject has nothing else', () => {
    expect(holderFirst('CN=Demo Comercial SAS')).toBe('Demo Comercial SAS')
  })

  it('leaves a subject without a holder as it is', () => {
    expect(holderFirst('O=Demo Comercial SAS, C=CO')).toBe('O=Demo Comercial SAS, C=CO')
    expect(holderFirst('')).toBe('')
  })
})
