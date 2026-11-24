/**
 * A certificate's subject for a line that may be cut: the holder's name (the value of its CN) first, then the rest of
 * the subject as the API gives it, so the line starts with something a reader recognizes. "serialNumber=990000001,
 * CN=Demo Comercial SAS TEST CERTIFICATE, O=Demo Comercial SAS, C=CO" reads "Demo Comercial SAS TEST CERTIFICATE ·
 * serialNumber=990000001, O=Demo Comercial SAS, C=CO". A subject without a CN stays as it is.
 */
export function holderFirst(subject: string): string {
  // The CN runs until the next attribute (", X=") or the end; a comma inside the name stays in it.
  const match = /(^|, )CN=(.*?)(?=, [A-Za-z][\w.-]*=|$)/.exec(subject)
  const name = match?.[2]?.trim()

  if (match === null || !name) {
    return subject
  }

  const before = subject.slice(0, match.index)
  const after = subject.slice(match.index + match[0].length)
  const rest = (before === '' ? after.replace(/^, /, '') : `${before}${after}`).trim()

  return rest === '' ? name : `${name} · ${rest}`
}
