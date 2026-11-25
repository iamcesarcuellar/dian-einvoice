// The check of the translations (translations.mjs) on small documents written here: what it compares, what it lets
// through, and the cases it must fail on (a code span that is missing, a figure that changed, an old stamp, a paragraph
// left in English, among others). make test-tools runs it.
import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import {
  checkTranslations,
  codeShape,
  compareMarkdown,
  compareNotice,
  Exceptions,
  figuresOf,
  glossaryOf,
  looksEnglish,
  mermaidShape,
  NOTICE_LINES,
  number,
  pairsOf,
  sha256,
  stampLine,
  stampOf,
  withoutShellComment,
  withStamp,
} from './translations.mjs'

const ORIGINAL = `# Example

**English** · [Español](es/example.md)

Run \`make demo\`: it took 58 seconds on 2026-11-19, and 1,122 records loaded in 2.7 s. See [the guide](api-guide.md#first-steps) and [Floci](https://github.com/floci-io/floci).

## First steps

| Step | Command |
| --- | --- |
| Start | \`make up\` |
| Check | \`make checks\` |

- one item, with \`make test\`;
- another item.

\`\`\`sh
KEY=dei_...   # the key make demo printed
curl -s "$API/health" -H "Authorization: Bearer $KEY"
\`\`\`

\`\`\`mermaid
flowchart LR
  browser["Web app"] -->|"HTTP"| api["API"]
\`\`\`
`

const TRANSLATION = `# Ejemplo

[English](../example.md) · **Español**

Corre \`make demo\`: tardó 58 segundos el 19 de noviembre de 2026, y 1.122 registros cargaron en 2,7 s. Mira [la guía](api-guide.md#primeros-pasos) y [Floci](https://github.com/floci-io/floci).

## Primeros pasos

| Paso | Comando |
| --- | --- |
| Arrancar | \`make up\` |
| Revisar | \`make checks\` |

- un elemento, con \`make test\`;
- otro elemento.

\`\`\`sh
KEY=dei_...   # la llave que imprimió make demo
curl -s "$API/health" -H "Authorization: Bearer $KEY"
\`\`\`

\`\`\`mermaid
flowchart LR
  browser["Aplicación web"] -->|"HTTP"| api["API"]
\`\`\`
`

const GUIDE = '# API guide\n\n**English** · [Español](es/api-guide.md)\n\n## First steps\n\nText of the guide, with the steps.\n'
const GUIDE_ES = '# Guía de la API\n\n[English](../api-guide.md) · **Español**\n\n## Primeros pasos\n\nTexto de la guía, con los pasos.\n'

/** compareMarkdown over docs/example.md and docs/es/example.md, with the guide as the pair the links name. */
function compare(originalText, translationText, exceptions = new Exceptions()) {
  const anchors = { 'docs/api-guide.md': ['api-guide', 'first-steps'], 'docs/es/api-guide.md': ['guía-de-la-api', 'primeros-pasos'] }

  return compareMarkdown({
    original: 'docs/example.md',
    translation: 'docs/es/example.md',
    originalText,
    translationText,
    pairOf: (path) => ({ 'docs/api-guide.md': 'docs/es/api-guide.md', 'docs/example.md': 'docs/es/example.md' })[path] ?? null,
    exists: () => true,
    anchorsFor: (path) => anchors[path] ?? [],
    exceptions,
  }).problems
}

/** A repository in a temporary folder, with the files given. */
function repository(files) {
  const root = mkdtempSync(join(tmpdir(), 'translations-'))

  for (const [path, text] of Object.entries(files)) {
    mkdirSync(dirname(join(root, path)), { recursive: true })
    writeFileSync(join(root, path), text)
  }

  return root
}

const sealed = (original, translation, originalPath = 'docs/example.md') => withStamp(translation, originalPath, sha256(original))

describe('figures', () => {
  it('read the same in both notations', () => {
    assert.equal(number('6,756', 'en'), number('6.756', 'es'))
    assert.equal(number('2.7', 'en'), '2.7')
    assert.equal(number('2,7', 'es'), '2.7')
    assert.equal(number('1,000,000', 'en'), number('1.000.000', 'es'))
    assert.equal(number('153.55', 'en'), number('153,55', 'es'))
  })

  it('read a figure in Colombian notation that English text quotes', () => {
    assert.equal(number('2,5', 'en'), number('2,5', 'es'))
    assert.equal(number('1.234,56', 'en'), '1234.56')
    assert.equal(number('1,122', 'en'), '1122')
  })

  it('keep a version, an address or a section as written', () => {
    assert.equal(number('8.4.8', 'en'), number('8.4.8', 'es'))
    assert.equal(number('1.9', 'en'), number('1.9', 'es'))
    assert.equal(number('127.0.0.1', 'es'), '127.0.0.1')
  })

  it('read a date in prose in either language', () => {
    assert.deepEqual(figuresOf('Thursday, November 19, 2026, at 15:00', 'en'), ['2026-11-19', '15:00'])
    assert.deepEqual(figuresOf('el jueves 19 de noviembre de 2026, a las 15:00', 'es'), ['2026-11-19', '15:00'])
    assert.deepEqual(figuresOf('Resolución 000165 of 1 November 2023', 'en'), ['2023-11-01', '000165'])
    assert.deepEqual(figuresOf('on 2026-11-22', 'en'), figuresOf('el 22 de noviembre de 2026', 'es'))
  })

  it('read a figure in the plural as the figure', () => {
    assert.deepEqual(figuresOf('Application 500s from a given minute on', 'en'), figuresOf('Errores 500 de la aplicación', 'es'))
  })

  it('leave out the digits that are part of a name', () => {
    assert.deepEqual(figuresOf('IPv4, S3, x86_64 and arm64 in 46 s', 'en'), ['46'])
  })
})

describe('code blocks', () => {
  it('keep their shell commands, their comments aside', () => {
    assert.equal(withoutShellComment('KEY=dei_...   # the key'), 'KEY=dei_...')
    assert.equal(withoutShellComment(`grep -o '"id":"#x"' file # count`), `grep -o '"id":"#x"' file`)
    assert.equal(withoutShellComment('echo ${#list}'), 'echo ${#list}')
    assert.equal(codeShape({ language: 'sh', code: '# One.\necho 1   # one\n' }), codeShape({ language: 'sh', code: '# Uno, en dos líneas.\n# La segunda.\necho 1   # uno\n' }))
  })

  it('keep what a Mermaid diagram draws, its labels aside', () => {
    assert.equal(mermaidShape('flowchart LR\n  a["Web app"] -->|"session"| b'), mermaidShape('flowchart LR\n  a["Aplicación web"] -->|"sesión"| b'))
    assert.equal(mermaidShape('stateDiagram-v2\n  [*] --> Draft\n  Draft --> Issued: number'), mermaidShape('stateDiagram-v2\n  [*] --> Borrador\n  Borrador --> Emitida: número'))
    assert.equal(mermaidShape('sequenceDiagram\n  participant API as api function\n  API->>DB: one transaction'), mermaidShape('sequenceDiagram\n  participant API as función api\n  API->>DB: una transacción'))
    assert.notEqual(mermaidShape('flowchart LR\n  a --> b'), mermaidShape('flowchart LR\n  a --> c'))
  })
})

describe('a translation against its original', () => {
  it('passes when it keeps the code, the links, the figures and the structure', () => {
    assert.deepEqual(compare(ORIGINAL, TRANSLATION), [])
  })

  it('fails when an inline code span is missing', () => {
    const problems = compare(ORIGINAL, TRANSLATION.replace('con `make test`', 'con las pruebas'))
    assert.ok(problems.some((problem) => problem.includes('the inline code `make test`') && problem.includes('is missing')), problems.join('\n'))
  })

  it('fails when a figure changes', () => {
    const problems = compare(ORIGINAL, TRANSLATION.replace('tardó 58 segundos', 'tardó 85 segundos'))
    assert.ok(problems.some((problem) => problem.includes('the figure 58') && problem.includes('is missing')), problems.join('\n'))
    assert.ok(problems.some((problem) => problem.includes('the figure 85 is not in')), problems.join('\n'))
  })

  it('fails when a figure keeps the English notation and so reads as another value', () => {
    const problems = compare(ORIGINAL, TRANSLATION.replace('1.122 registros', '1,122 registros'))
    assert.ok(problems.some((problem) => problem.includes('the figure 1122')), problems.join('\n'))
  })

  it('fails when a command of a code block changes', () => {
    const problems = compare(ORIGINAL, TRANSLATION.replace('curl -s "$API/health"', 'curl -sf "$API/health"'))
    assert.ok(problems.some((problem) => problem.includes('this sh block is not the one of')), problems.join('\n'))
  })

  it('fails when a link goes elsewhere, or to the English document instead of its pair', () => {
    const elsewhere = compare(ORIGINAL, TRANSLATION.replace('https://github.com/floci-io/floci', 'https://example.com'))
    assert.ok(elsewhere.some((problem) => problem.includes('no link to https://github.com/floci-io/floci')), elsewhere.join('\n'))
    const english = compare(ORIGINAL, TRANSLATION.replace('(api-guide.md#primeros-pasos)', '(../api-guide.md#first-steps)'))
    assert.ok(english.some((problem) => problem.includes('no link to docs/es/api-guide.md#primeros-pasos')), english.join('\n'))
  })

  it('links the API reference in the other language, as served and as exported, and fails on the one of the original', () => {
    const original = `${ORIGINAL}\nThe [reference](http://localhost:8600/docs/api), its [document](http://localhost:8600/docs/api.json) and [openapi.json](../openapi.json); [in Spanish](http://localhost:8600/docs/api/es).\n`
    const translation = `${TRANSLATION}\nLa [referencia](http://localhost:8600/docs/api/es), su [documento](http://localhost:8600/docs/api/es.json) y [openapi.es.json](../../openapi.es.json); [en inglés](http://localhost:8600/docs/api).\n`
    assert.deepEqual(compare(original, translation), [])

    const english = compare(original, translation.replace('(http://localhost:8600/docs/api/es)', '(http://localhost:8600/docs/api)'))
    assert.ok(english.some((problem) => problem.includes('no link to http://localhost:8600/docs/api/es')), english.join('\n'))
    const file = compare(original, translation.replace('(../../openapi.es.json)', '(../../openapi.json)'))
    assert.ok(file.some((problem) => problem.includes('no link to openapi.es.json')), file.join('\n'))
  })

  it('fails when a table loses a row, a list an item or a heading its place', () => {
    assert.ok(compare(ORIGINAL, TRANSLATION.replace('| Revisar | `make checks` |\n', '')).some((problem) => problem.includes('the tables')))
    assert.ok(compare(ORIGINAL, TRANSLATION.replace('- otro elemento.\n', '')).some((problem) => problem.includes('the lists')))
    assert.ok(compare(ORIGINAL, TRANSLATION.replace('## Primeros pasos', '### Primeros pasos')).some((problem) => problem.includes('its headings are not those of')))
  })

  it('lets a difference through when an exception declares it, and only then', () => {
    const changed = TRANSLATION.replace('con `make test`', 'con `make test` y `LOCALE=es`')
    assert.ok(compare(ORIGINAL, changed).some((problem) => problem.includes('`LOCALE=es`')))
    const exceptions = new Exceptions([{ translation: 'docs/es/example.md', kind: 'inline-code', value: 'LOCALE=es', reason: 'The Spanish page says how to load the demo in Spanish.' }])
    assert.deepEqual(compare(ORIGINAL, changed, exceptions), [])
    assert.deepEqual(exceptions.problems('translations.json'), [])
    const unused = new Exceptions([{ translation: 'docs/es/example.md', kind: 'figure', value: '99', reason: 'An exception that nothing needs any more.' }])
    compare(ORIGINAL, TRANSLATION, unused)
    assert.ok(unused.problems('translations.json').some((problem) => problem.includes('matches nothing')))
  })
})

describe('Spanish that is not half done', () => {
  it('tells a paragraph left in English from Spanish with English names in it', () => {
    assert.equal(looksEnglish('The relay publishes the events that are left behind, once a minute, through the queue.'), true)
    assert.equal(looksEnglish('El relay publica cada minuto los eventos del outbox que quedaron atrás, a través de SNS y SQS.'), false)
    assert.equal(looksEnglish('AWS CDK en TypeScript con cdk-nag, osls 4.8.1, cfn-lint, actionlint y Floci.'), false)
    assert.equal(looksEnglish('Secrets Manager, Price List API, Logs Insights, Standard Webhooks'), false)
  })

  it('reads the replaced terms from the glossary', () => {
    const glossary = glossaryOf('# Documentación\n\n| Inglés | Español | En vez de |\n| --- | --- | --- |\n| API key | Llave de API | clave de API, API key |\n| Journey | Recorrido | viaje |\n')
    assert.deepEqual(
      glossary.map((entry) => entry.variant),
      ['clave de API', 'API key', 'viaje'],
    )
    assert.ok(glossary[0].pattern.test('Crea dos claves de API.'))
  })
})

describe('the pairs and the stamps of a repository', () => {
  const glossary = '# Documentación\n\n[English](../README.md) · **Español**\n\n| Inglés | Español | En vez de |\n| --- | --- | --- |\n| API key | Llave de API | clave de API |\n'
  const index = '# Documentation\n\n**English** · [Español](es/README.md)\n\n| English | Spanish | Instead of |\n| --- | --- | --- |\n| API key | Llave de API | clave de API |\n'
  const base = (example, extra = {}) => ({
    'docs/README.md': index,
    'docs/es/README.md': sealed(index, glossary, 'docs/README.md'),
    'docs/api-guide.md': GUIDE,
    'docs/es/api-guide.md': sealed(GUIDE, GUIDE_ES, 'docs/api-guide.md'),
    'docs/example.md': ORIGINAL,
    'docs/es/example.md': example,
    ...extra,
  })
  const check = (files) => checkTranslations(repository(files), Object.keys(files), { exceptions: [] }).problems

  it('pass when every document has its translation and every translation follows its original', () => {
    assert.deepEqual(check(base(sealed(ORIGINAL, TRANSLATION))), [])
  })

  it('fail when a document has no translation, or a translation no original', () => {
    const files = base(sealed(ORIGINAL, TRANSLATION), { 'docs/deployment.md': '# Deployment\n', 'docs/es/runbooks/orphan.md': '# Huérfano\n' })
    const problems = check(files)
    assert.ok(problems.includes('docs/deployment.md: no Spanish translation (docs/es/deployment.md)'), problems.join('\n'))
    assert.ok(problems.includes('docs/es/runbooks/orphan.md: its original docs/runbooks/orphan.md does not exist'), problems.join('\n'))
    assert.deepEqual(pairsOf(['NOTICE', 'NOTICE.es.txt', 'LICENSE']).pairs, [{ original: 'NOTICE', translation: 'NOTICE.es.txt', generated: false }])
  })

  it('fail when the original changed after its translation was sealed', () => {
    const files = base(sealed(ORIGINAL, TRANSLATION))
    files['docs/example.md'] = ORIGINAL.replace('another item', 'another item, changed')
    const problems = check(files)
    assert.ok(problems.includes('docs/es/example.md: translates an older docs/example.md: bring the translation up to date, then run make docs-stamp'), problems.join('\n'))
  })

  it('fail when a translation has no stamp', () => {
    assert.ok(check(base(TRANSLATION)).includes('docs/es/example.md: no stamp of its original (docs/example.md): translate it, then run make docs-stamp'))
  })

  it('fail on a paragraph left in English and on a replaced term', () => {
    const english = sealed(ORIGINAL, `${TRANSLATION}\nThe relay publishes the events that are left behind, once a minute, through the queue.\n`)
    assert.ok(check(base(english)).some((problem) => problem.startsWith('docs/es/example.md:') && problem.includes('still in English')))
    const term = sealed(ORIGINAL, TRANSLATION.replace('otro elemento.', 'otro elemento, con su clave de API.'))
    assert.ok(check(base(term)).some((problem) => problem.includes('"clave de API", which the glossary replaces with "Llave de API"')))
  })

  it('fail when a document does not name its pair right under its title', () => {
    const problems = check(base(sealed(ORIGINAL, TRANSLATION.replace('[English](../example.md) · **Español**', '[Inglés](../example.md)'))))
    assert.ok(problems.includes('docs/es/example.md: the line right under its title is not "[English](../example.md) · **Español**"'), problems.join('\n'))
  })

  it('seal a translation with the SHA-256 of its original, under its language line', () => {
    const text = sealed(ORIGINAL, TRANSLATION)
    assert.equal(stampOf(text), sha256(ORIGINAL))
    assert.equal(text.split('\n')[4], stampLine('docs/example.md', sha256(ORIGINAL)))
    assert.equal(stampOf(withStamp(text, 'docs/example.md', 'a'.repeat(64))), 'a'.repeat(64))
  })
})

describe('NOTICE and its translation', () => {
  const notice = `dian-einvoice\n\n${NOTICE_LINES.original}\n\nThis repository is under the MIT License.\n\n1. Inter 4.1 fonts\n\n   backend/resources/fonts/inter/Inter-Regular.ttf\n\n   Unchanged, from the Inter 4.1 release (https://github.com/rsms/inter).\n\n   The fonts are the work of The Inter Project Authors, 2016\n`
  const translation = `dian-einvoice\n\n${NOTICE_LINES.translation}\n\nEste repositorio está bajo la licencia MIT.\n\n1. Fuentes Inter 4.1\n\n   backend/resources/fonts/inter/Inter-Regular.ttf\n\n   Sin cambios, de la versión 4.1 de Inter (https://github.com/rsms/inter).\n\n   > The fonts are the work of The Inter Project Authors, 2016\n`

  it('pass when the translation lists the same files and quotes the notices word for word', () => {
    assert.deepEqual(compareNotice({ originalText: notice, translationText: translation }), [])
    assert.deepEqual(compareNotice({ originalText: notice, translationText: withStamp(translation, 'NOTICE', sha256(notice)) }), [], 'the stamp line is the translation\'s own')
  })

  it('fail when a quoted notice is not the one of NOTICE, or a file is missing', () => {
    assert.ok(compareNotice({ originalText: notice, translationText: translation.replace('work of The Inter', 'work of the Inter') }).some((problem) => problem.includes('a quoted notice that NOTICE does not have')))
    assert.ok(compareNotice({ originalText: notice, translationText: translation.replace('   backend/resources/fonts/inter/Inter-Regular.ttf\n', '') }).some((problem) => problem.includes('does not list backend/resources/fonts/inter/Inter-Regular.ttf')))
  })

  it('fail on a paragraph of the project left in English', () => {
    const files = { NOTICE: notice, 'NOTICE.es.txt': withStamp(translation.replace('Este repositorio está bajo la licencia MIT.', 'This repository is under the MIT License, except for the files listed below.'), 'NOTICE', sha256(notice)) }
    const problems = checkTranslations(repository(files), Object.keys(files), { exceptions: [] }).problems
    assert.ok(problems.some((problem) => problem.includes('a paragraph in English that is not a quoted notice')), problems.join('\n'))
  })
})
