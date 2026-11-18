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

/** The parsed document, or null when the text is not well-formed XML. */
export function parseXml(text: string): XmlDocument | null {
  let failed = false

  try {
    const parser = new DOMParser({
      onError: (level: string) => {
        if (level !== 'warning') {
          failed = true
        }
      },
    })
    const document = parser.parseFromString(text, 'text/xml') as unknown as XmlDocument

    return failed || !document?.documentElement ? null : document
  } catch {
    return null
  }
}

export function children(parent: XmlNode | undefined, namespace: string, name: string): XmlNode[] {
  const found: XmlNode[] = []

  if (!parent) {
    return found
  }

  for (let index = 0; index < parent.childNodes.length; index++) {
    const node = parent.childNodes[index]

    if (node.nodeType === ELEMENT_NODE && node.localName === name && node.namespaceURI === namespace) {
      found.push(node)
    }
  }

  return found
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
  if (!parent) {
    return undefined
  }

  for (let index = 0; index < parent.childNodes.length; index++) {
    if (parent.childNodes[index].nodeType === ELEMENT_NODE) {
      return parent.childNodes[index]
    }
  }

  return undefined
}

/** Every descendant element with that namespace and local name, in document order. */
export function descendants(parent: XmlNode | undefined, namespace: string, name: string, found: XmlNode[] = []): XmlNode[] {
  if (!parent) {
    return found
  }

  for (let index = 0; index < parent.childNodes.length; index++) {
    const node = parent.childNodes[index]

    if (node.nodeType !== ELEMENT_NODE) {
      continue
    }

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
