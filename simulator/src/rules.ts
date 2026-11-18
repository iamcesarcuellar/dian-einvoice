// The validation rules this simulator implements, with the code, kind and message of the annex
// 1.9 (sections 8.2 to 8.4, 8.7 and 8.8): FA for invoices, CA and CB for credit notes, DA and DB for
// debit notes. Messages are DIAN data and stay in Spanish; "check" says in English what the
// simulator verifies. ZD05 is a rejection at the DIAN; here it is only a
// notification, so documents signed with synthetic test certificates can be accepted.

export type Kind = 'rejection' | 'notification'

export interface Rule {
  code: string
  kind: Kind
  message: string
  check: string
}

export interface Finding {
  code: string | null
  kind: Kind
  message: string
  /** Set when a failure mode, not the document, caused it. */
  forced?: boolean
}

export const RULES: readonly Rule[] = [
  { code: 'ZB01', kind: 'rejection', message: 'Fallo en el esquema XML del archivo', check: 'The XML is well formed and valid against the XSD of its type: UBL-Invoice-2.1.xsd, UBL-CreditNote-2.1.xsd or UBL-DebitNote-2.1.xsd.' },
  { code: 'FAB24b', kind: 'rejection', message: 'Identificador del software asignado cuando el software se activa en el Sistema de Facturación Electrónica no corresponde a un software autorizado para este OFE', check: 'The issuer NIT is registered and the software ID is its software.' },
  { code: 'FAB27b', kind: 'rejection', message: 'Huella no corresponde a un software autorizado para este OFE', check: 'The software security code is SHA-384 of software ID, PIN and number.' },
  { code: 'FAB05b', kind: 'rejection', message: 'Número de la autorización de la numeración no corresponde a un número de autorización de este contribuyente emisor para este Proveedor de Autorización', check: 'The resolution is one of the ranges registered for the issuer.' },
  { code: 'FAB07b', kind: 'rejection', message: 'Fecha inicial del rango de numeración informado NO corresponde a la fecha inicial de los rangos vigente para el contribuyente.', check: 'The start date of the range matches the registered one.' },
  { code: 'FAB08b', kind: 'rejection', message: 'Fecha final del rango de numeración informado no corresponde a la fecha final de los rangos vigente para el contribuyente', check: 'The end date of the range matches the registered one.' },
  { code: 'FAB10b', kind: 'rejection', message: 'El prefijo no corresponde al prefijo de la autorización de numeración', check: 'The prefix matches the registered range and starts the number.' },
  { code: 'FAB11b', kind: 'rejection', message: 'Valor inicial del rango de numeración informado no corresponde a un valor inicial de los rangos vigentes para el contribuyente emisor', check: 'The first number of the range matches the registered one.' },
  { code: 'FAB12b', kind: 'rejection', message: 'Valor final del rango de numeración informado no corresponde a un valor final de los rangos vigentes para el contribuyente emisor', check: 'The last number of the range matches the registered one.' },
  { code: 'FAD05b', kind: 'rejection', message: 'Número de factura es inferior al número inicial del rango de numeración autorizado', check: 'The number is not below the range.' },
  { code: 'FAD05c', kind: 'rejection', message: 'Número de factura es superior al número final del rango de numeración autorizado', check: 'The number is not above the range.' },
  { code: 'FAD06', kind: 'rejection', message: 'Valor del CUFE no está calculado correctamente', check: 'The CUFE recalculated with the technical key of the range equals cbc:UUID.' },
  { code: 'FAD09a', kind: 'rejection', message: 'Fecha de emisión anterior a la fecha de inicio de la autorización de la numeración', check: 'The issue date is not before the validity of the range.' },
  { code: 'FAD09b', kind: 'rejection', message: 'Fecha de emisión posterior a la fecha final de la autorización de la numeración', check: 'The issue date is not after the validity of the range.' },
  { code: 'FAU02', kind: 'rejection', message: 'El Valor Bruto antes de tributos no es igual a la suma de los valores de las líneas de la factura que contienen el valor comercial', check: 'LineExtensionAmount is the sum of the lines.' },
  { code: 'FAU04', kind: 'rejection', message: 'Base Imponible es distinto a la suma de los valores de las bases imponibles de todas líneas de detalle.', check: 'TaxExclusiveAmount is the sum of the taxable amounts of the lines.' },
  { code: 'FAU06', kind: 'rejection', message: 'Valor Bruto más tributos es diferente a Valor Bruto de la factura que contienen el valor comercial más la Suma de los Tributos de todas las líneas de detalle.', check: 'TaxInclusiveAmount is LineExtensionAmount plus the taxes.' },
  { code: 'FAU14', kind: 'rejection', message: 'Valor a Pagar de Factura es distinto de la Suma de Valor Bruto más tributos - Valor del Descuento Total + Valor del Cargo Total', check: 'PayableAmount is TaxInclusiveAmount minus allowances plus charges minus prepaid amounts.' },
  { code: 'CAB24b', kind: 'rejection', message: 'Identificador del software asignado cuando el software se activa en el Sistema de Facturación Electrónica no corresponde a un software autorizado para este OFE', check: 'Credit note: the issuer NIT is registered and the software ID is its software.' },
  { code: 'CAB27b', kind: 'rejection', message: 'Huella no corresponde a un software autorizado para este OFE', check: 'Credit note: the software security code is SHA-384 of software ID, PIN and number.' },
  { code: 'CAD06', kind: 'rejection', message: 'Valor del CUDE No está calculado correctamente', check: 'Credit note: the CUDE recalculated with the PIN of the software equals cbc:UUID.' },
  { code: 'CBG01', kind: 'rejection', message: 'Nota tipo uno a uno y no se informa BillingReference', check: 'Credit note: it carries the BillingReference of the invoice it corrects.' },
  { code: 'CBG03', kind: 'rejection', message: 'ID de FE de referencia no relacionada', check: 'Credit note: the referenced ID is the number of the referenced invoice.' },
  { code: 'CBG04', kind: 'rejection', message: 'CUFE de FE de referenciada no existe', check: 'Credit note: the referenced CUFE is an invoice of the same issuer the simulator accepted.' },
  { code: 'CBG05', kind: 'rejection', message: 'Algoritmo no corresponde', check: 'Credit note: the schemeName of the referenced CUFE is CUFE-SHA384.' },
  { code: 'CBG06', kind: 'notification', message: 'Fecha FE referenciada posterior a fecha de la Nota crédito', check: 'Credit note: the referenced invoice is not dated after the note.' },
  { code: 'CAU02', kind: 'rejection', message: 'El Valor Bruto antes de tributos no es igual a la suma de los valores de las líneas de la factura que contienen el valor comercial.', check: 'Credit note: LineExtensionAmount is the sum of the lines.' },
  { code: 'CAU04', kind: 'rejection', message: 'Base Imponible, es distinto a la suma de los valores de las bases imponibles de todas líneas de detalle.', check: 'Credit note: TaxExclusiveAmount is the sum of the taxable amounts of the lines.' },
  { code: 'CAU06', kind: 'rejection', message: 'Valor Bruto más tributos es diferente a Valor Bruto de la factura que contienen el valor comercial más la Suma de los Tributos de todas las líneas de detalle.', check: 'Credit note: TaxInclusiveAmount is LineExtensionAmount plus the taxes.' },
  { code: 'CAU14', kind: 'rejection', message: 'Valor a Pagar de Factura es distinto de la Suma de Valor Bruto más tributos - Valor del Descuento Total + Valor del Cargo Total', check: 'Credit note: PayableAmount is TaxInclusiveAmount minus allowances plus charges.' },
  { code: 'DAB24b', kind: 'rejection', message: 'Identificador del software asignado cuando el software se activa en el Sistema de Facturación Electrónica no corresponde a un software autorizado para este OFE', check: 'Debit note: the issuer NIT is registered and the software ID is its software.' },
  { code: 'DAB27b', kind: 'rejection', message: 'Huella no corresponde a un software autorizado para este OFE', check: 'Debit note: the software security code is SHA-384 of software ID, PIN and number.' },
  { code: 'DAD06', kind: 'rejection', message: 'Valor del CUDE no está calculado correctamente.', check: 'Debit note: the CUDE recalculated with the PIN of the software equals cbc:UUID.' },
  { code: 'DBG01', kind: 'rejection', message: 'No fue informado el grupo', check: 'Debit note: it carries the BillingReference of the invoice it corrects.' },
  { code: 'DBG03', kind: 'rejection', message: 'ID de ND de referencia no relacionada.', check: 'Debit note: the referenced ID is the number of the referenced invoice.' },
  { code: 'DBG04', kind: 'rejection', message: 'CUFE de Nota Débito referenciada no existe', check: 'Debit note: the referenced CUFE is an invoice of the same issuer the simulator accepted.' },
  { code: 'DBG06', kind: 'rejection', message: 'Fecha Factura Electrónica referenciada posterior a fecha de la Nota Débito.', check: 'Debit note: the referenced invoice is not dated after the note.' },
  { code: 'DAU02', kind: 'rejection', message: 'El Valor Bruto antes de tributos NO es igual a la suma de los valores de las líneas de la factura que contienen el valor comercial.', check: 'Debit note: LineExtensionAmount is the sum of the lines.' },
  { code: 'DAU04', kind: 'rejection', message: 'Base Imponible, es distinto a la suma de los valores de las bases imponibles de todas líneas de detalle.', check: 'Debit note: TaxExclusiveAmount is the sum of the taxable amounts of the lines.' },
  { code: 'DAU06', kind: 'rejection', message: 'Valor Bruto más tributos, es diferente a Valor Bruto de la factura que contienen el valor comercial más la suma de los tributos de todas las líneas de detalle.', check: 'Debit note: TaxInclusiveAmount is LineExtensionAmount plus the taxes.' },
  { code: 'DAU14', kind: 'rejection', message: 'Valor a Pagar de Factura, es distinto de la Suma de Valor Bruto más tributos - Valor del Descuento Total + Valor del Cargo Total', check: 'Debit note: PayableAmount is TaxInclusiveAmount minus allowances plus charges.' },
  { code: 'ZD01', kind: 'rejection', message: 'Certificado de Firma inexistente en el archivo', check: 'The document carries a signature with its certificate.' },
  { code: 'ZE02', kind: 'rejection', message: 'Valor de la Firma inválido', check: 'xmlsec1 verifies the digests and the signature value.' },
  { code: 'ZD02', kind: 'rejection', message: 'Certificado de la Firma con data de validez inválida', check: 'The certificate is valid at the signing time and now.' },
  { code: 'ZD05', kind: 'notification', message: 'Cadena de confianza del certificado digital es incorrecta, certificado no fue emitido por una ECD avalada por la ONAC.', check: 'A self-signed test certificate: only a notification in this simulator.' },
  { code: '90', kind: 'rejection', message: 'Documento procesado anteriormente', check: 'The number was already accepted for the issuer, in a document of the same type with another CUFE or CUDE.' },
]

export function rule(code: string): Rule | undefined {
  return RULES.find((candidate) => candidate.code === code)
}

/** A finding of a rule, with an optional detail after its message. */
export function finding(code: string, detail?: string, forced = false): Finding {
  const known = rule(code)
  const message = known?.message ?? code

  return {
    code,
    kind: known?.kind ?? 'rejection',
    message: detail ? `${message} - ${detail}` : message,
    ...(forced ? { forced: true } : {}),
  }
}

/** "Regla: FAD06, Rechazo: Valor del CUFE no está calculado correctamente", as the DIAN writes them. */
export function formatFinding(item: Finding): string {
  const kind = item.kind === 'rejection' ? 'Rechazo' : 'Notificación'

  return item.code === null ? item.message : `Regla: ${item.code}, ${kind}: ${item.message}`
}
