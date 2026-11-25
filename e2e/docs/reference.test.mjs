// The check of the API reference in Spanish (reference.mjs) on small documents written here: what it reads as a text,
// how it splits a text into blocks, and the cases it must fail on (a block without its translation, an entry that
// matches nothing, a figure that changed, a contract that changed, a document that is not the catalog's translation,
// among others). make test-tools runs it.
import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { blocksOf, checkReference, firstDifference, referenceProblems, textsOf, translate, unitsOf } from './reference.mjs'
import { glossaryOf } from './translations.mjs'

const ORIGIN = 'http://localhost:8601'

const ENGLISH = {
  openapi: '3.1.0',
  info: {
    title: 'dian-einvoice',
    version: '1.0.0',
    description: `**English** · [Español](${ORIGIN}/docs/api/es)\n\nThe API of the project. Every response carries \`X-Correlation-Id\`.\n\n## Idempotency\n\n- A key is kept 24 hours.\n- See [Standard Webhooks](https://www.standardwebhooks.com).`,
  },
  servers: [{ url: `${ORIGIN}/api/v1` }],
  tags: [{ name: 'Invoices', description: 'Electronic sales invoices.' }],
  paths: {
    '/invoices': {
      get: {
        operationId: 'invoices.index',
        tags: ['Invoices'],
        summary: 'List documents',
        description: 'Drafts first, then the issued documents\nfrom the newest number down.',
        parameters: [{ name: 'per_page', in: 'query', description: 'Between 1 and 100.', schema: { type: 'integer', examples: [25] } }],
        responses: {
          200: {
            description: '`DocumentResource`',
            content: {
              'application/json': {
                schema: {
                  type: 'object',
                  properties: {
                    description: { type: 'string', description: 'The text of the line.' },
                    status: { type: 'string', enum: ['draft', 'issued'], default: 'draft', examples: ['A sentence in English as data.'] },
                  },
                },
              },
            },
          },
        },
      },
    },
  },
}

const SPANISH = {
  ...ENGLISH,
  info: {
    ...ENGLISH.info,
    description: `[English](${ORIGIN}/docs/api) · **Español**\n\nLa API del proyecto. Cada respuesta lleva \`X-Correlation-Id\`.\n\n## Idempotencia\n\n- Una llave se guarda 24 horas.\n- Consulta [Standard Webhooks](https://www.standardwebhooks.com).`,
  },
  tags: [{ name: 'Facturas', description: 'Facturas electrónicas de venta.' }],
  paths: {
    '/invoices': {
      get: {
        ...ENGLISH.paths['/invoices'].get,
        tags: ['Facturas'],
        summary: 'Listar documentos',
        description: 'Primero los borradores y luego los documentos emitidos, del número más reciente hacia atrás.',
        parameters: [{ ...ENGLISH.paths['/invoices'].get.parameters[0], description: 'Entre 1 y 100.' }],
        responses: {
          200: {
            description: '`DocumentResource`',
            content: {
              'application/json': {
                schema: {
                  type: 'object',
                  properties: {
                    description: { type: 'string', description: 'El texto de la línea.' },
                    status: { type: 'string', enum: ['draft', 'issued'], default: 'draft', examples: ['A sentence in English as data.'] },
                  },
                },
              },
            },
          },
        },
      },
    },
  },
}

const CATALOG = {
  description: 'The Spanish of a small reference.',
  page: { title: 'dian-einvoice - Referencia de la API' },
  same: { '`DocumentResource`': 'The name of a schema, which stays as the code writes it.' },
  translations: {
    'The API of the project. Every response carries `X-Correlation-Id`.': 'La API del proyecto. Cada respuesta lleva `X-Correlation-Id`.',
    Idempotency: 'Idempotencia',
    'A key is kept 24 hours.': 'Una llave se guarda 24 horas.',
    'See [Standard Webhooks](https://www.standardwebhooks.com).': 'Consulta [Standard Webhooks](https://www.standardwebhooks.com).',
    Invoices: 'Facturas',
    'Electronic sales invoices.': 'Facturas electrónicas de venta.',
    'List documents': 'Listar documentos',
    'Drafts first, then the issued documents from the newest number down.': 'Primero los borradores y luego los documentos emitidos, del número más reciente hacia atrás.',
    'Between 1 and 100.': 'Entre 1 y 100.',
    'The text of the line.': 'El texto de la línea.',
  },
}

const copy = (value) => structuredClone(value)
const problemsOf = ({ english = ENGLISH, spanish = SPANISH, catalog = CATALOG, glossary = [] } = {}) => referenceProblems({ english, spanish, catalog, glossary }).problems
const withTranslation = (english, spanish) => {
  const catalog = copy(CATALOG)
  catalog.translations[english] = spanish

  return catalog
}
const fails = (problems, pattern) => assert.ok(problems.some((problem) => pattern.test(problem)), `expected a problem matching ${pattern}, got:\n${problems.join('\n')}`)

describe('the texts of a reference', () => {
  it('are its summaries, its descriptions and the names of its groups, never its data or the names of its fields', () => {
    const texts = textsOf(ENGLISH).map(({ pointer, kind }) => `${kind} ${pointer}`)

    assert.deepEqual(texts, [
      'text /info/description',
      'tag /tags/0/name',
      'text /tags/0/description',
      'tag /paths/~1invoices/get/tags/0',
      'text /paths/~1invoices/get/summary',
      'text /paths/~1invoices/get/description',
      'text /paths/~1invoices/get/parameters/0/description',
      'text /paths/~1invoices/get/responses/200/description',
      'text /paths/~1invoices/get/responses/200/content/application~1json/schema/properties/description/description',
    ])
  })

  it('split into blocks as ReferenceCatalog splits them: paragraphs with their lines joined, list items, table rows and headings', () => {
    assert.deepEqual(unitsOf('## Errors'), [{ prefix: '## ', text: 'Errors', suffix: '' }])
    assert.deepEqual(unitsOf('Filters: ?status=\nor ?from=.\n- one item\n  that goes on\n- another'), [
      { prefix: '', text: 'Filters: ?status= or ?from=.', suffix: '' },
      { prefix: '- ', text: 'one item that goes on', suffix: '' },
      { prefix: '- ', text: 'another', suffix: '' },
    ])
    assert.deepEqual(blocksOf('First.\n\n## Second\n\n- Third.\n\n'), ['First.', 'Second', 'Third.'])

    // Scramble writes the cases of an enum as a table right under its description: each row is a unit of its own, and
    // a row without a letter stays as it is.
    const table = 'The states.\n| |\n|---|\n| `draft` <br/> Not issued yet. |'
    assert.deepEqual(unitsOf(table), [
      { prefix: '', text: 'The states.', suffix: '' },
      { prefix: '| ', text: '', suffix: '|' },
      { prefix: '|', text: '---', suffix: '|' },
      { prefix: '| ', text: '`draft` <br/> Not issued yet.', suffix: ' |' },
    ])
    assert.deepEqual(blocksOf(table), ['The states.', '`draft` <br/> Not issued yet.'])
    assert.equal(translate(table, new Map([['The states.', 'Los estados.'], ['`draft` <br/> Not issued yet.', '`draft` <br/> Aún sin emitir.']])), 'Los estados.\n| |\n|---|\n| `draft` <br/> Aún sin emitir. |')
    assert.equal(translate('Drafts first.\nThen more.\n\n- A key.', new Map([['Drafts first. Then more.', 'Primero.'], ['A key.', 'Una llave.']])), 'Primero.\n\n- Una llave.')
  })

  it('differ at the first pointer where their values or the order of their keys differ', () => {
    assert.equal(firstDifference({ a: [1, { b: 2 }] }, { a: [1, { b: 2 }] }), null)
    assert.equal(firstDifference({ a: [1, { b: 2 }] }, { a: [1, { b: 3 }] }), '/a/1/b')
    assert.equal(firstDifference({ a: 1, b: 2 }, { b: 2, a: 1 }), '/a')
  })
})

describe('the Spanish reference', () => {
  it('passes when it is the catalog applied to the English one, with the same contract', () => {
    assert.deepEqual(problemsOf(), [])
  })

  it('fails when a block of the English reference has no translation', () => {
    const catalog = copy(CATALOG)
    delete catalog.translations['Between 1 and 100.']

    fails(problemsOf({ catalog }), /no translation of "Between 1 and 100\." \(openapi\.json#\/paths\/~1invoices\/get\/parameters\/0\/description\)/)
  })

  it('fails when an entry of the catalog matches no text of the English reference', () => {
    fails(problemsOf({ catalog: withTranslation('A sentence nobody writes any more.', 'Una frase que ya nadie escribe.') }), /matches no text of openapi\.json/)

    const catalog = copy(CATALOG)
    catalog.same['`OldResource`'] = 'The name of a schema, which stays as the code writes it.'
    fails(problemsOf({ catalog }), /same declares "`OldResource`", which no text/)
  })

  it('fails when a translation is the English text itself, or a block is declared the same without its reason', () => {
    fails(problemsOf({ catalog: withTranslation('Invoices', 'Invoices') }), /is the same text: declare it in same/)

    const catalog = copy(CATALOG)
    catalog.same['`DocumentResource`'] = 'A name.'
    fails(problemsOf({ catalog }), /without its reason/)
  })

  it("fails when a translation does not keep its original's inline code, links and figures", () => {
    fails(problemsOf({ catalog: withTranslation('The API of the project. Every response carries `X-Correlation-Id`.', 'La API del proyecto. Cada respuesta lleva el id de correlación.') }), /lacks its inline code `X-Correlation-Id`/)
    fails(problemsOf({ catalog: withTranslation('See [Standard Webhooks](https://www.standardwebhooks.com).', 'Consulta [Standard Webhooks](https://www.standardwebhooks.com/es).') }), /lacks its link to https:\/\/www\.standardwebhooks\.com\b/)
    fails(problemsOf({ catalog: withTranslation('A key is kept 24 hours.', 'Una llave se guarda 48 horas.') }), /lacks the figure 24/)
  })

  it('fails when a translation reads as English, or says a term the glossary replaces', () => {
    fails(problemsOf({ catalog: withTranslation('Between 1 and 100.', 'Between 1 and 100, in the API of the reference.') }), /still reads as English/)

    const glossary = glossaryOf('| Inglés | Español | En vez de |\n| --- | --- | --- |\n| Key | Llave | clave |\n')
    fails(problemsOf({ catalog: withTranslation('A key is kept 24 hours.', 'Una clave se guarda 24 horas.'), glossary }), /says "clave", which the glossary replaces with "Llave"/)
  })

  it('fails when the Spanish page has no title', () => {
    const catalog = copy(CATALOG)
    catalog.page.title = ''

    fails(problemsOf({ catalog }), /page\.title, the title of the Spanish page, is empty/)
  })

  it('fails when the contract changes, a value or a name translated with the texts', () => {
    const spanish = copy(SPANISH)
    spanish.paths['/invoices'].get.responses[200].content['application/json'].schema.properties.status.enum = ['borrador', 'emitido']

    fails(problemsOf({ spanish }), /not the contract of openapi\.json: they differ at \/paths\/~1invoices\/get\/responses\/200\/content\/application~1json\/schema\/properties\/status\/enum\/0/)

    const renamed = copy(SPANISH)
    renamed.paths['/invoices'].get.parameters[0].name = 'por_pagina'
    fails(problemsOf({ spanish: renamed }), /not the contract of openapi\.json: they differ at \/paths\/~1invoices\/get\/parameters\/0\/name/)
  })

  it("fails when openapi.es.json does not hold the catalog's translation of a text", () => {
    const spanish = copy(SPANISH)
    spanish.paths['/invoices'].get.summary = 'Ver los documentos'

    fails(problemsOf({ spanish }), /openapi\.es\.json#\/paths\/~1invoices\/get\/summary: not the catalog's translation of openapi\.json: run make openapi/)
  })

  it('fails when a reference does not link to the other one on its first line', () => {
    const english = copy(ENGLISH)
    english.info.description = english.info.description.replace(`${ORIGIN}/docs/api/es`, '/docs/api/es')
    fails(problemsOf({ english }), /openapi\.json: the first line of info\.description is not/)

    const spanish = copy(SPANISH)
    spanish.info.description = spanish.info.description.replace(`[English](${ORIGIN}/docs/api) · **Español**`, '**Español**')
    fails(problemsOf({ spanish }), /openapi\.es\.json: the first line of info\.description is not/)
  })
})

describe('the check over a repository', () => {
  it('fails while the Spanish reference has not been exported', () => {
    const root = mkdtempSync(join(tmpdir(), 'reference-'))
    writeFileSync(join(root, 'openapi.json'), JSON.stringify(ENGLISH))

    const { problems } = checkReference(root)
    assert.ok(problems.includes('openapi.es.json is missing: run make openapi'), problems.join('\n'))
    assert.ok(problems.some((problem) => problem.startsWith('backend/resources/api-reference/es.json is missing')), problems.join('\n'))
  })
})
