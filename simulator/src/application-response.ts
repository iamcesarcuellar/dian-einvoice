// The ApplicationResponse returned in XmlBase64Bytes, with the structure of the official examples
// of the toolbox (DIAN extensions, sender and receiver, DocumentResponse with one LineResponse per
// rule). It is NOT signed yet, and it says what it is: a response of the local simulator, which the
// DIAN never issued.
import { sha384 } from './money.ts'
import { formatFinding } from './rules.ts'
import type { DocumentRecord } from './state.ts'
import { escapeXml } from './xml.ts'

const AGENCY = 'schemeAgencyID="195" schemeAgencyName="CO, DIAN (Dirección de Impuestos y Aduanas Nacionales)"'
const DIAN_NIT = '800197268'
const DIAN_CHECK_DIGIT = '4'
const SIMULATOR_SOFTWARE_ID = '00000000-0000-4000-8000-00000000d1a5'
const COLOMBIA_OFFSET_MS = 5 * 60 * 60 * 1000

/** Table 13.1.3 of annex 1.9: 01 electronic sales invoice, 91 credit note, 92 debit note. */
const DOCUMENT_TYPE = { invoice: '01', credit_note: '91', debit_note: '92' } as const

/** Date and time in Colombia (UTC-05:00), as the IssueDate and IssueTime of UBL documents. */
export function colombianDateTime(at: Date): { date: string, time: string } {
  const local = new Date(at.getTime() - COLOMBIA_OFFSET_MS).toISOString()

  return { date: local.slice(0, 10), time: `${local.slice(11, 19)}-05:00` }
}

export function applicationResponse(record: DocumentRecord): string {
  const issued = colombianDateTime(new Date(record.responded_at))
  const accepted = record.status === 'accepted'
  const id = String(record.response_number)
  const cude = sha384(`${id}${record.cufe}${issued.date}${issued.time}`)
  const findings = record.findings.length > 0
    ? record.findings.map((item, index) => line(index + 1, item.code ?? '0', formatFinding(item)))
    : [line(1, '0000', '0')]

  return `<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:sts="dian:gov:co:facturaelectronica:Structures-2-1" xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" xmlns:xades141="http://uri.etsi.org/01903/v1.4.1#">
  <ext:UBLExtensions>
    <ext:UBLExtension>
      <ext:ExtensionContent>
        <sts:DianExtensions>
          <sts:InvoiceSource>
            <cbc:IdentificationCode listAgencyID="6" listAgencyName="United Nations Economic Commission for Europe" listSchemeURI="urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1">CO</cbc:IdentificationCode>
          </sts:InvoiceSource>
          <sts:SoftwareProvider>
            <sts:ProviderID schemeID="${DIAN_CHECK_DIGIT}" schemeName="31" ${AGENCY}>${DIAN_NIT}</sts:ProviderID>
            <sts:SoftwareID ${AGENCY}>${SIMULATOR_SOFTWARE_ID}</sts:SoftwareID>
          </sts:SoftwareProvider>
          <sts:SoftwareSecurityCode ${AGENCY}>${sha384(`${SIMULATOR_SOFTWARE_ID}${id}`)}</sts:SoftwareSecurityCode>
          <sts:AuthorizationProvider>
            <sts:AuthorizationProviderID schemeID="${DIAN_CHECK_DIGIT}" schemeName="31" ${AGENCY}>${DIAN_NIT}</sts:AuthorizationProviderID>
          </sts:AuthorizationProvider>
        </sts:DianExtensions>
      </ext:ExtensionContent>
    </ext:UBLExtension>
  </ext:UBLExtensions>
  <cbc:UBLVersionID>UBL 2.1</cbc:UBLVersionID>
  <cbc:CustomizationID>1</cbc:CustomizationID>
  <cbc:ProfileID>DIAN 2.1</cbc:ProfileID>
  <cbc:ProfileExecutionID>2</cbc:ProfileExecutionID>
  <cbc:ID>${id}</cbc:ID>
  <cbc:UUID schemeID="2" schemeName="CUDE-SHA384">${cude}</cbc:UUID>
  <cbc:IssueDate>${issued.date}</cbc:IssueDate>
  <cbc:IssueTime>${issued.time}</cbc:IssueTime>
  <cbc:Note>SIMULADO: lo produjo el simulador local de la DIAN de dian-einvoice, una herramienta de desarrollo. La DIAN no lo emitió, no está firmado y el documento no quedó registrado ante la DIAN.</cbc:Note>
  <cac:SenderParty>
    <cac:PartyTaxScheme>
      <cbc:RegistrationName>Simulador local de la DIAN (herramienta de desarrollo, no es la DIAN)</cbc:RegistrationName>
      <cbc:CompanyID schemeID="${DIAN_CHECK_DIGIT}" schemeName="31" ${AGENCY}>${DIAN_NIT}</cbc:CompanyID>
      <cac:TaxScheme>
        <cbc:ID>01</cbc:ID>
        <cbc:Name>IVA</cbc:Name>
      </cac:TaxScheme>
    </cac:PartyTaxScheme>
  </cac:SenderParty>
  <cac:ReceiverParty>
    <cac:PartyTaxScheme>
      <cbc:RegistrationName>${escapeXml(record.issuer_name ?? record.nit)}</cbc:RegistrationName>
      <cbc:CompanyID${record.issuer_check_digit ? ` schemeID="${escapeXml(record.issuer_check_digit)}"` : ''} schemeName="31" ${AGENCY}>${escapeXml(record.nit)}</cbc:CompanyID>
      <cac:TaxScheme>
        <cbc:ID>01</cbc:ID>
        <cbc:Name>IVA</cbc:Name>
      </cac:TaxScheme>
    </cac:PartyTaxScheme>
  </cac:ReceiverParty>
  <cac:DocumentResponse>
    <cac:Response>
      <cbc:ResponseCode>${accepted ? '02' : '04'}</cbc:ResponseCode>
      <cbc:Description>${accepted ? 'Aceptado por el simulador local de la DIAN; no quedó registrado ante la DIAN' : 'Rechazado por el simulador local de la DIAN; no quedó registrado ante la DIAN'}</cbc:Description>
    </cac:Response>
    <cac:DocumentReference>
      <cbc:ID>${escapeXml(record.number)}</cbc:ID>
      <cbc:UUID schemeName="${(record.kind ?? 'invoice') === 'invoice' ? 'CUFE-SHA384' : 'CUDE-SHA384'}">${escapeXml(record.cufe)}</cbc:UUID>
      <cbc:DocumentTypeCode>${DOCUMENT_TYPE[record.kind ?? 'invoice']}</cbc:DocumentTypeCode>
    </cac:DocumentReference>
${findings.join('\n')}
  </cac:DocumentResponse>
</ApplicationResponse>
`
}

function line(position: number, code: string, description: string): string {
  return `    <cac:LineResponse>
      <cac:LineReference>
        <cbc:LineID>${position}</cbc:LineID>
      </cac:LineReference>
      <cac:Response>
        <cbc:ResponseCode>${escapeXml(code)}</cbc:ResponseCode>
        <cbc:Description>${escapeXml(description)}</cbc:Description>
      </cac:Response>
    </cac:LineResponse>`
}
