// The SOAP 1.2 contract of the DIAN web service (annex 1.9, sections 7.6, 7.10 and 7.11): the
// request envelope with the operation in the wcf namespace, and the answers with the DianResponse
// result (ErrorMessage, IsValid, StatusCode, StatusDescription, StatusMessage, XmlBase64Bytes,
// XmlBytes, XmlDocumentKey, XmlFileName). The WS-Security header is not checked, and the
// Timestamp of the answer is not signed.
import { child, escapeXml, firstElement, NS, parseXml, text } from './xml.ts'

export interface SoapRequest {
  operation: string
  action: string | undefined
  fileName: string | undefined
  contentFile: string | undefined
  trackId: string | undefined
}

export interface DianResult {
  isValid: boolean
  statusCode: string
  statusDescription: string | null
  statusMessage: string | null
  errorMessages: string[]
  applicationResponse: string | null
  documentKey: string | null
  fileName: string | null
}

const ACTION = 'http://wcf.dian.colombia/IWcfDianCustomerServices/'

export function parseRequest(body: string): SoapRequest | null {
  const envelope = parseXml(body)?.documentElement ?? undefined

  if (!envelope || envelope.localName !== 'Envelope' || envelope.namespaceURI !== NS.soap) {
    return null
  }

  const operation = firstElement(child(envelope, NS.soap, 'Body'))

  if (!operation?.localName) {
    return null
  }

  return {
    operation: operation.localName,
    action: text(child(child(envelope, NS.soap, 'Header'), NS.wsa, 'Action')),
    fileName: text(child(operation, NS.wcf, 'fileName')),
    contentFile: text(child(operation, NS.wcf, 'contentFile')),
    trackId: text(child(operation, NS.wcf, 'trackId')),
  }
}

export function resultEnvelope(operation: string, result: DianResult): string {
  const created = new Date()
  const expires = new Date(created.getTime() + 5 * 60 * 1000)
  const value = (name: string, content: string | null): string => content === null
    ? `<b:${name} i:nil="true"/>`
    : `<b:${name}>${escapeXml(content)}</b:${name}>`
  const errors = result.errorMessages.length === 0
    ? '<b:ErrorMessage xmlns:c="http://schemas.microsoft.com/2003/10/Serialization/Arrays"/>'
    : `<b:ErrorMessage xmlns:c="http://schemas.microsoft.com/2003/10/Serialization/Arrays">${result.errorMessages.map((message) => `<c:string>${escapeXml(message)}</c:string>`).join('')}</b:ErrorMessage>`
  const base64 = result.applicationResponse === null ? null : Buffer.from(result.applicationResponse, 'utf8').toString('base64')

  return `<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://www.w3.org/2005/08/addressing" xmlns:u="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
<s:Header>
<a:Action s:mustUnderstand="1">${ACTION}${operation}Response</a:Action>
<o:Security s:mustUnderstand="1" xmlns:o="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
<u:Timestamp u:Id="_0">
<u:Created>${created.toISOString()}</u:Created>
<u:Expires>${expires.toISOString()}</u:Expires>
</u:Timestamp>
</o:Security>
</s:Header>
<s:Body>
<${operation}Response xmlns="http://wcf.dian.colombia">
<${operation}Result xmlns:b="http://schemas.datacontract.org/2004/07/DianResponse" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">
${errors}
<b:IsValid>${result.isValid ? 'true' : 'false'}</b:IsValid>
<b:StatusCode>${escapeXml(result.statusCode)}</b:StatusCode>
${value('StatusDescription', result.statusDescription)}
${value('StatusMessage', result.statusMessage)}
${value('XmlBase64Bytes', base64)}
<b:XmlBytes i:nil="true"/>
${value('XmlDocumentKey', result.documentKey)}
${value('XmlFileName', result.fileName)}
</${operation}Result>
</${operation}Response>
</s:Body>
</s:Envelope>
`
}

/** A SOAP 1.2 fault: Sender for a bad request, Receiver for a failure of the service. */
export function faultEnvelope(code: 'Sender' | 'Receiver', reason: string): string {
  return `<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">
<s:Body>
<s:Fault>
<s:Code><s:Value>s:${code}</s:Value></s:Code>
<s:Reason><s:Text xml:lang="es-CO">${escapeXml(reason)}</s:Text></s:Reason>
</s:Fault>
</s:Body>
</s:Envelope>
`
}
