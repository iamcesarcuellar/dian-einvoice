// Small helpers over @xmldom/xmldom: namespace-aware parsing, child lookup by namespace and local
// name, and escaping. Only what the simulator needs.
import { DOMParser } from '@xmldom/xmldom'

export const NS = {
  soap: 'http://www.w3.org/2003/05/soap-envelope',
  wcf: 'http://wcf.dian.colombia',
  wsa: 'http://www.w3.org/2005/08/addressing',
  invoice: 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
  creditNote: 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
  debitNote: 'urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2',
  cac: 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
  cbc: 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
  ext: 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
  sts: 'dian:gov:co:facturaelectronica:Structures-2-1',
  ds: 'http://www.w3.org/2000/09/xmldsig#',
  xades: 'http://uri.etsi.org/01903/v1.3.2#',
} as const

/** The part of a DOM node the simulator reads. */
export interface XmlNode {
  nodeType: number
  localName?: string | null
  namespaceURI?: string | null
  textContent: string | null
  childNodes: ArrayLike<XmlNode>
  getAttribute?: (name: string) => string | null
}

export interface XmlDocument {
  documentElement: XmlNode | null
}

const ELEMENT_NODE = 1

/**
 * The parsed document, or null when the text is not well-formed XML or declares a DTD. No document
 * the simulator reads (SOAP 1.2 envelopes, UBL 2.1) may carry a DOCTYPE, so none reaches xmllint or
 * xmlsec1 either: no external subset, no entity declared, nothing expanded. @xmldom/xmldom itself
 * never loads anything from the network or the disk.
 */
export function parseXml(text: string): XmlDocument | null {
  if (/<!DOCTYPE/i.test(text)) {
    return null
  }

  let failed = false

  try {
    const parser = new DOMParser({
      onError: (level: string) => {
        if (level !== 'warning') {
          failed = true
        }
      },
    })
    const document = parser.parseFromString(text, 'text/xml') as unknown as XmlDocument & { doctype?: unknown }

    return failed || !document?.documentElement || (document.doctype ?? null) !== null ? null : document
  } catch {
    return null
  }
}

/** The child elements, in document order. */
function elements(parent: XmlNode | undefined): XmlNode[] {
  return parent ? Array.from(parent.childNodes).filter((node) => node.nodeType === ELEMENT_NODE) : []
}

export function children(parent: XmlNode | undefined, namespace: string, name: string): XmlNode[] {
  return elements(parent).filter((node) => node.localName === name && node.namespaceURI === namespace)
}

export function child(parent: XmlNode | undefined, namespace: string, name: string): XmlNode | undefined {
  return children(parent, namespace, name)[0]
}

/** Follows child elements: path(invoice, [NS.cac, 'LegalMonetaryTotal'], [NS.cbc, 'PayableAmount']). */
export function path(start: XmlNode | undefined, ...steps: Array<readonly [string, string]>): XmlNode | undefined {
  let current = start

  for (const [namespace, name] of steps) {
    current = child(current, namespace, name)
  }

  return current
}

/** The first child element, whatever its name. */
export function firstElement(parent: XmlNode | undefined): XmlNode | undefined {
  return elements(parent)[0]
}

/** Every descendant element with that namespace and local name, in document order. */
export function descendants(parent: XmlNode | undefined, namespace: string, name: string, found: XmlNode[] = []): XmlNode[] {
  for (const node of elements(parent)) {
    if (node.localName === name && node.namespaceURI === namespace) {
      found.push(node)
    }

    descendants(node, namespace, name, found)
  }

  return found
}

/** Trimmed text of an element; undefined when it is missing or empty. */
export function text(node: XmlNode | undefined): string | undefined {
  const value = node?.textContent?.trim()

  return value === undefined || value === '' ? undefined : value
}

export function attribute(node: XmlNode | undefined, name: string): string | undefined {
  const value = node?.getAttribute?.(name)

  return value === null || value === undefined || value === '' ? undefined : value
}

export function escapeXml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;')
}
