// The licenses a dependency of this project may have, compatible with its MIT License: the SPDX identifier of each
// with its kind. A license not listed here fails make security and make licenses until someone decides on it.

/** Permissive licenses: they ask to keep their notice, nothing else. */
const PERMISSIVE = ['0BSD', 'Apache-2.0', 'BlueOak-1.0.0', 'BSD-2-Clause', 'BSD-3-Clause', 'CC0-1.0', 'ISC', 'MIT', 'MIT-0', 'PSF-2.0', 'Python-2.0', 'Unlicense', 'Zlib']

/**
 * Weak copyleft, for libraries used as they are: their own files stay under their license and must stay available,
 * which their packages do, while the code of this project stays MIT. A change to one of them would be published under
 * its own license. LGPL-2.1 and LGPL-3.0 are the deprecated SPDX names of the -only versions.
 */
const WEAK_COPYLEFT = ['EPL-2.0', 'LGPL-2.1', 'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'MPL-2.0']

/** Fonts, bundled or embedded: the font itself stays under its license, the documents that use it do not. */
const FONTS = ['OFL-1.1']

export const KINDS = new Map([
  ...PERMISSIVE.map((license) => [license, 'permissive']),
  ...WEAK_COPYLEFT.map((license) => [license, 'weak copyleft, as a library']),
  ...FONTS.map((license) => [license, 'font']),
])

/** The alternatives of an SPDX expression, each a list of the licenses it joins with AND. */
export function alternatives(expression) {
  return expression
    .replace(/[()]/g, ' ')
    .split(/\s+OR\s+/i)
    .map((alternative) =>
      alternative
        .split(/\s+AND\s+/i)
        .map((part) => part.trim())
        .filter(Boolean),
    )
    .filter((parts) => parts.length > 0)
}

/** The alternative this project takes: the first one whose every license is compatible, or null. */
export function chosen(expression) {
  return alternatives(expression).find((parts) => parts.every((license) => KINDS.has(license))) ?? null
}

export function compatible(expression) {
  return expression.trim() !== '' && chosen(expression) !== null
}
