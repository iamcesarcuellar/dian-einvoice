// make cost: docs/cost.md and its Spanish version, docs/es/cost.md, the estimate of what the prod stage would cost a
// month in us-east-1, computed from two files and nothing else: scripts/cost/prices.json (each unit price with its
// official source and the date it was consulted) and scripts/cost/usage.json (each assumption with where it comes
// from). Each text of those files has its Spanish in its "es" field, and the Spanish page writes its figures in
// Colombian notation (US$ 1.234,56). No dependencies, no network. With --check (make docs-check, part of make lint)
// it writes nothing and fails when either page is not what the two files give.
//
//   node scripts/cost/estimate.mjs [--check] [<root of the repository>]
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const args = process.argv.slice(2)
const check = args.includes('--check')
const root = args.find((arg) => !arg.startsWith('--')) ?? fileURLToPath(new URL('../..', import.meta.url))
const prices = JSON.parse(readFileSync(join(root, 'scripts/cost/prices.json'), 'utf8'))
const usage = JSON.parse(readFileSync(join(root, 'scripts/cost/usage.json'), 'utf8'))

function price(key) {
  const found = prices.prices[key]

  if (found === undefined) {
    throw new Error(`scripts/cost/prices.json has no price ${key}`)
  }

  return found
}

function value(key) {
  const found = usage.assumptions[key]

  if (found === undefined) {
    throw new Error(`scripts/cost/usage.json has no assumption ${key}`)
  }

  return found.value
}

const GB = 1e9
const hours = value('hoursPerMonth')
const minutes = hours * 60
const seconds = hours * 3600
const month = usage.storageMonth

/**
 * The lines of the bill of one scenario: each a quantity of one price, under the service it belongs to. The options
 * are the levers: each one changes the stage as its table says.
 */
function bill(scenario, options = {}) {
  const n = scenario.invoices
  const lines = []
  const add = (service, key, quantity) => lines.push({ service, key, quantity })

  // The traffic of the month.
  const sessions = n * value('webSessions')
  const apiCalls = n * value('apiRequests') + sessions * value('webApiRequestsPerSession')
  const edgeRequests = n * value('apiRequests') + sessions * value('webRequestsPerSession')
  const edgeBytes = n * (3 * value('invoiceJsonBytes') + value('pdfBytes')) + sessions * value('webBytesPerSession')
  const subscriptions = value('webhookSubscriptions')
  const chainJobs = n * value('chainMessages')
  const webhookJobs = n * value('webhookEvents') * (1 + subscriptions)

  // Aurora MySQL Serverless v2: the writer and its reader run the same capacity.
  const instances = options.withoutReader ? value('auroraInstances') - 1 : value('auroraInstances')
  const acu = Math.max(value('auroraMinAcu'), scenario.auroraAcu)
  add('Aurora MySQL Serverless v2', 'auroraAcuHour', instances * acu * hours)
  const transient =
    value('webhookEvents') * value('dbWebhookEventBytes') * (30 / 30.4) + value('outboxEvents') * value('dbOutboxEventBytes') * (7 / 30.4) + value('dbTransmissionBytes') * (180 / 30.4)
  const databaseGb = value('auroraBaseGb') + (n * month * value('dbPermanentBytes') + n * transient) / GB
  add('Aurora MySQL Serverless v2', 'auroraStorage', databaseGb)
  add('Aurora MySQL Serverless v2', 'auroraIo', n * (value('dbWriteIos') + value('dbReadIos')))
  const written = (n * (value('dbPermanentBytes') + value('webhookEvents') * value('dbWebhookEventBytes') + value('outboxEvents') * value('dbOutboxEventBytes') + value('dbTransmissionBytes'))) / GB
  add('Aurora MySQL Serverless v2', 'auroraBackup', Math.max(0, written * (35 / 30.4) - databaseGb))

  if (options.withProxy) {
    add('RDS Proxy', 'rdsProxyAcuHour', Math.max(price('rdsProxyAcuHour').minimum, instances * acu) * hours)
  }

  // Lambda: invocations and their duration, on x86_64 unless the lever moves them to arm64.
  const compute = options.arm64 ? 'lambdaGbSecondArm' : 'lambdaGbSecondX86'
  const runs = minutes
  const invocations = apiCalls + chainJobs + webhookJobs + 2 * runs + value('originVerifierCalls')
  const cold = (apiCalls + chainJobs + webhookJobs + 2 * runs) * value('coldStartShare')
  const init = value('initMs') / 1000
  const gbSeconds =
    apiCalls * (value('apiRequestMs') / 1000) * value('memoryApi') +
    chainJobs * (value('chainJobMs') / 1000) * value('memoryChain') +
    n * (value('dianWaitMs') / 1000) * value('memoryChain') +
    webhookJobs * (value('webhookJobMs') / 1000) * value('memoryWebhooks') +
    2 * runs * (value('relayRunMs') / 1000) * value('memoryRelay') +
    value('originVerifierCalls') * (value('originVerifierMs') / 1000) * value('memoryOriginVerifier') +
    (apiCalls * value('memoryApi') + chainJobs * value('memoryChain') + webhookJobs * value('memoryWebhooks') + 2 * runs * value('memoryRelay')) * value('coldStartShare') * init
  add('Lambda', 'lambdaRequest', invocations)
  add('Lambda', compute, gbSeconds)

  // The edge: CloudFront, its firewall and API Gateway behind it.
  // PriceClass_All serves the viewers of Colombia from South America; PriceClass_100 from North America.
  const southAmerica = options.priceClass100 ? 0 : value('southAmericaShare')
  add('CloudFront', 'cloudfrontRequestSouthAmerica', edgeRequests * southAmerica)
  add('CloudFront', 'cloudfrontRequestUnitedStates', edgeRequests * (1 - southAmerica))
  add('CloudFront', 'cloudfrontTransferSouthAmerica', (edgeBytes * southAmerica) / GB)
  add('CloudFront', 'cloudfrontTransferUnitedStates', (edgeBytes * (1 - southAmerica)) / GB)
  add('CloudFront', 'cloudfrontFunction', sessions * (value('webRequestsPerSession') - value('webApiRequestsPerSession')))
  add('AWS WAF', 'wafWebAcl', 1)
  add('AWS WAF', 'wafRule', value('wafRules'))
  add('AWS WAF', 'wafRequest', edgeRequests)
  add('API Gateway', 'httpApiRequest', apiCalls)

  // The network: the NAT gateway and its address, and what goes through it.
  const nats = options.secondNat ? value('natGateways') + 1 : value('natGateways')
  add('NAT gateway and internet', 'natHour', nats * hours)
  add('NAT gateway and internet', 'publicIpv4Hour', nats * hours)
  const awsApiBytes = n * (value('sesBytes') + value('outboxEvents') * value('snsBytesPerEvent'))
  const internetBytes = n * (value('dianBytes') + value('webhookBodyBytes') * subscriptions)
  add('NAT gateway and internet', 'natGb', (internetBytes + (options.interfaceEndpoints ? 0 : awsApiBytes)) / GB)
  add('NAT gateway and internet', 'internetTransfer', internetBytes / GB)

  if (options.interfaceEndpoints) {
    add('Interface endpoints', 'endpointHour', value('interfaceEndpointServices') * value('zones') * hours)
    add('Interface endpoints', 'endpointGb', awsApiBytes / GB)
  }

  // Messaging: the topic of document events and the two queues.
  add('SNS', 'snsRequest', n * value('outboxEvents'))
  add('SQS', 'sqsRequest', (chainJobs + webhookJobs) * value('sqsRequestsPerMessage') + value('sqsIdleRequests'))
  add('EventBridge Scheduler', 'schedulerInvocation', value('schedulesPerMinute') * minutes)

  // Storage: the documents, the edge's access logs, the cache and limits tables, the image of the functions.
  add('S3', 's3Standard', (n * month * value('documentBytes') + edgeRequests * value('edgeLogBytesPerRequest') * value('logRetentionMonths')) / GB)
  add('S3', 's3Put', n * value('objects'))
  add('S3', 's3Get', n * value('s3Gets'))
  add('DynamoDB', 'dynamodbWrite', apiCalls * value('dynamoWritesPerRequest') + minutes * value('dynamoSchedulerWritesPerMinute'))
  add('DynamoDB', 'dynamodbRead', apiCalls * value('dynamoReadsPerRequest'))
  add('ECR', 'ecrStorage', value('ecrImages') * value('imageGb'))

  // Keys and secrets.
  const snsDataKeys = (seconds / 300) * 2 * value('topicPublishers')
  add('KMS', 'kmsKey', value('kmsKeys'))
  add('KMS', 'kmsRequest', n * value('kmsRequests') + snsDataKeys)
  add('Secrets Manager', 'secret', value('secrets'))
  add('Secrets Manager', 'secretRequest', cold * value('secretReadsPerColdStart'))

  // Email.
  add('SES', 'sesEmail', n * value('emails'))
  add('SES', 'sesAttachment', (n * value('emails') * value('zipBytes')) / GB)

  // CloudWatch: the metrics, alarms and dashboard of the observability stack, and every log of the stage.
  if (!options.withoutObservability) {
    add('CloudWatch metrics and alarms', 'cloudwatchMetric', value('customMetrics'))
    add('CloudWatch metrics and alarms', 'cloudwatchAlarm', value('alarmMetrics'))
    add('CloudWatch metrics and alarms', 'cloudwatchCompositeAlarm', value('compositeAlarms'))
    add('CloudWatch metrics and alarms', 'cloudwatchDashboard', value('dashboards'))
  }

  const ingested =
    (n * value('logBytes') +
      invocations * value('platformLogBytes') +
      runs * (value('relayLogBytesPerRun') + value('schedulerLogBytesPerRun')) +
      n * value('flowLogBytes') +
      value('flowLogBytesIdle') +
      value('databaseLogBytesIdle') +
      value('firewallLogBytesIdle')) /
    GB
  add('CloudWatch Logs', 'logsIngestion', ingested)
  add('CloudWatch Logs', 'logsStorage', ingested * Math.min(month, options.retentionMonths ?? value('logRetentionMonths')))

  for (const line of lines) {
    const limit = price(line.key).tierLimit

    if (limit !== undefined && line.quantity > limit) {
      throw new Error(`${scenario.name}: ${line.key} reaches ${line.quantity}, beyond the first tier of its price (${limit}); the estimate prices the first tier only.`)
    }
  }

  return lines
}

/** The cost of a bill, without the free tier or with the always-free amounts of the Price List, shared per pool. */
function total(lines, free = false) {
  const left = new Map()

  return lines.reduce((sum, line) => {
    const unit = price(line.key)
    let quantity = line.quantity

    if (free && unit.free !== undefined) {
      const pool = unit.freePool ?? line.key
      const remaining = left.has(pool) ? left.get(pool) : unit.free
      const used = Math.min(remaining, quantity)
      left.set(pool, remaining - used)
      quantity -= used
    }

    return sum + quantity * unit.usd
  }, 0)
}

const byService = (lines) =>
  lines.reduce((services, line) => {
    services.set(line.service, (services.get(line.service) ?? 0) + line.quantity * price(line.key).usd)

    return services
  }, new Map())

const LOCALES = { en: 'en-US', es: 'es-CO' }
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

/** The Spanish of a text of the data files, from its "es" field: the estimate fails when one is missing. */
function spanish(entry, field, where) {
  const text = field === null ? entry.es : entry.es?.[field]

  if (typeof text !== 'string' || text === '') {
    throw new Error(`${where} has no Spanish${field === null ? '' : ` ${field}`} (its "es" field)`)
  }

  return text
}

/** The page in one language: the same tables, from the same bills, in its words and its notation. */
function page(language) {
  const es = language === 'es'
  const locale = LOCALES[language]
  const number = (amount, options) => amount.toLocaleString(locale, options)
  const usd = (amount) => {
    if (amount > 0 && amount < 0.005) {
      return es ? '< US$ 0,01' : '< $0.01'
    }

    return es ? `US$ ${number(amount, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : amount.toLocaleString('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 })
  }
  const signed = (amount) => (Math.abs(amount) < 0.005 ? usd(0) : `${amount > 0 ? '+' : '−'}${usd(Math.abs(amount))}`)
  const count = (amount) => number(amount, { maximumFractionDigits: 2 })
  const unitPrice = (amount) => {
    if (amount >= 1) {
      return usd(amount)
    }

    const decimals = Math.min(12, Math.max(2, 5 - Math.floor(Math.log10(amount))))
    const text = amount.toFixed(decimals).replace(/(\.\d*?[1-9])0+$/, '$1')
    const padded = /\.\d$/.test(text) ? `${text}0` : text

    return es ? `US$ ${padded.replace('.', ',')}` : `$${padded}`
  }
  const date = (iso) => {
    if (!es) {
      return iso
    }

    const [year, month, day] = iso.split('-').map(Number)

    return `${day} de ${MONTHS[month - 1]} de ${year}`
  }
  const nameOf = (scenario) => (es ? spanish(scenario, null, `scripts/cost/usage.json, scenario ${scenario.name},`) : scenario.name)
  const whatOf = (key, unit) => (es ? spanish(unit, 'what', `scripts/cost/prices.json, price ${key},`) : unit.what)
  const unitOf = (key, unit, file = 'prices.json') => (es ? spanish(unit, 'unit', `scripts/cost/${file}, ${file === 'prices.json' ? 'price' : 'assumption'} ${key},`) : unit.unit)
  const sourceOf = (key, assumption) => (es ? spanish(assumption, 'source', `scripts/cost/usage.json, assumption ${key},`) : assumption.source)
  const service = (name) => (es ? (SERVICES_ES[name] ?? name) : name)
  const T = es ? SPANISH : ENGLISH
  const fixed = total(bill(idle))
  const fixedFree = total(bill(idle), true)
  const out = []

  out.push(
    T.title,
    '',
    es ? '[English](../cost.md) · **Español**' : '**English** · [Español](es/cost.md)',
    '',
    T.generated,
    '',
    T.intro(prices.region, date(prices.consulted)),
    '',
    T.notice,
    '',
    T.how,
    '',
    T.summaryTitle,
    '',
    T.summary(month),
    '',
    T.summaryHeader,
    '| --- | ---: | ---: | ---: | ---: | ---: | ---: |',
  )

  for (const { scenario, lines } of bills) {
    const amount = total(lines)
    const perThousand = scenario.invoices === 0 ? '—' : usd((amount / scenario.invoices) * 1000)
    out.push(`| ${nameOf(scenario)} | ${count(scenario.invoices)} | ${usd(fixed)} | ${usd(amount - fixed)} | **${usd(amount)}** | ${perThousand} | ${usd(total(lines, true))} |`)
  }

  out.push('', T.idle(usd(fixed), usd(fixedFree)), '', T.byServiceTitle, '', T.byService, '', `| ${T.service} | ${scenarios.map(nameOf).join(' | ')} |`, `| --- | ${scenarios.map(() => '---:').join(' | ')} |`)

  const sums = bills.map(({ lines }) => byService(lines))

  for (const name of services) {
    out.push(`| ${service(name)} | ${sums.map((sum) => usd(sum.get(name) ?? 0)).join(' | ')} |`)
  }

  out.push(`| **Total** | ${bills.map(({ lines }) => `**${usd(total(lines))}**`).join(' | ')} |`, '', T.quantitiesTitle, '', T.quantities, '', `| ${T.service} | ${T.price} | ${scenarios.map(nameOf).join(' | ')} |`, `| --- | --- | ${scenarios.map(() => '---:').join(' | ')} |`)

  for (const [index, line] of bills[0].lines.entries()) {
    const unit = price(line.key)
    out.push(`| ${service(line.service)} | ${cell(whatOf(line.key, unit))}, ${T.per} ${unitOf(line.key, unit)} | ${bills.map(({ lines }) => count(lines[index].quantity)).join(' | ')} |`)
  }

  out.push('', T.leversTitle, '', T.levers, '', `| ${T.lever} | ${T.whatChanges} | ${scenarios.map(nameOf).join(' | ')} |`, `| --- | --- | ${scenarios.map(() => '---:').join(' | ')} |`)

  for (const [name, what, options] of T.LEVERS(value('interfaceEndpointServices'), value('zones'), month)) {
    const deltas = scenarios.map((scenario) => total(bill(scenario, options)) - total(bill(scenario)))
    out.push(`| ${name} | ${what} | ${deltas.map(signed).join(' | ')} |`)
  }

  out.push('', T.assumptionsTitle, '', T.assumptions(date(usage.measuredOn)), '', T.capacity(scenarios.map((scenario) => T.acu(count(scenario.auroraAcu), scenario.invoices === 0 ? null : count(scenario.invoices))).join(', ')))

  for (const [basis, title, meaning] of T.BASES) {
    const entries = Object.entries(usage.assumptions).filter(([, assumption]) => assumption.basis === basis)

    if (entries.length === 0) {
      continue
    }

    out.push('', `### ${title}`, '', `${meaning[0].toUpperCase()}${meaning.slice(1)}.`, '', T.assumptionsHeader, '| --- | ---: | --- | --- |')

    for (const [key, assumption] of entries) {
      out.push(`| \`${key}\` | ${number(assumption.value, { maximumFractionDigits: 3 })} | ${cell(unitOf(key, assumption, 'usage.json'))} | ${cell(sourceOf(key, assumption))} |`)
    }
  }

  const unconfirmed = Object.entries(prices.prices).filter(([, unit]) => unit.confirmed !== true)

  out.push('', T.pricesTitle, '', T.prices(prices.region, date(prices.consulted), unconfirmed.map(([key]) => `\`${key}\``)), '', T.pricesHeader, '| --- | --- | ---: | --- | ---: | --- |')

  for (const [key, unit] of Object.entries(prices.prices)) {
    out.push(`| \`${key}\` | ${cell(whatOf(key, unit))} | ${unitPrice(unit.usd)} | ${cell(unitOf(key, unit))} | ${unit.free === undefined ? '' : count(unit.free)} | [${T.pricingPage}](${unit.page}); ${cell(es ? (unit.es?.offer ?? unit.offer) : unit.offer)}${unit.confirmed === true ? '' : T.notConfirmed} |`)
  }

  out.push('', T.outsideTitle, '', ...T.outside.map((item) => `- ${item}`), '')

  return out.join('\n')
}

const cell = (text) => String(text).replaceAll('|', '\\|')

const scenarios = usage.scenarios
const idle = scenarios.find((scenario) => scenario.invoices === 0)

if (idle === undefined) {
  throw new Error('scripts/cost/usage.json needs a scenario of 0 invoices: it gives the fixed cost.')
}

const bills = scenarios.map((scenario) => ({ scenario, lines: bill(scenario) }))
const services = [...new Set(bills.flatMap(({ lines }) => lines.map((line) => line.service)))]

/** The services of the bill, in Spanish where their name has words to translate. */
const SERVICES_ES = {
  'NAT gateway and internet': 'NAT gateway e internet',
  'Interface endpoints': 'Endpoints de interfaz',
  'CloudWatch metrics and alarms': 'Métricas y alarmas de CloudWatch',
}

const ENGLISH = {
  title: '# Cost estimate',
  generated: '<!-- Generated by make cost (scripts/cost/estimate.mjs) from scripts/cost/prices.json and scripts/cost/usage.json. Do not edit: make docs-check fails when this file does not match those two files. -->',
  intro: (region, consulted) => `What the production architecture would cost a month if it were deployed to AWS in ${region} (US East, N. Virginia), at on-demand prices consulted on ${consulted}.`,
  notice:
    '> **An estimate, not a bill.** The prod stage was never deployed: it is synthesized and validated offline ([infrastructure.md](infrastructure.md)), and nothing here was ever charged to an AWS account. The volumes per invoice come from runs of the emulated cloud, the durations and the traffic are assumptions, and each one is declared [below](#assumptions). Taxes, support plans and the AWS account itself are not included.',
  how: 'How it is made: `scripts/cost/estimate.mjs`, a script without dependencies, computes every table on this page from two files: [`scripts/cost/prices.json`](../scripts/cost/prices.json) (each unit price with its official page, the AWS Price List API file it was read from, and the date) and [`scripts/cost/usage.json`](../scripts/cost/usage.json) (each assumption with its basis and its source). `make cost` writes this page and its Spanish version again, and `make docs-check`, part of `make lint`, fails when either one does not match the two files.',
  summaryTitle: '## Summary',
  summary: (month) =>
    `The fixed cost is what the month costs with no invoice at all, and the variable cost is the rest. Storage is priced for month ${month} at a steady volume, so it holds ${month} months of documents, database rows and logs. The free tier is left out, except in the last column. That column subtracts the always-free amounts, which the Price List itself prices at $0: Lambda, SQS, SNS, KMS, CloudWatch, CloudFront, EventBridge Scheduler, DynamoDB storage and 100 GB of data transfer out. It does not count a new account's credits or 12-month offers.`,
  summaryHeader: '| Scenario | Invoices a month | Fixed | Variable | Total | Per 1,000 invoices | Total with the always-free tier |',
  idle: (fixed, fixedFree) => `With no invoice, the bill is the database's minimum capacity on two instances, the NAT gateway, the firewall, the keys, the secrets and observability: ${fixed} a month, or ${fixedFree} with the always-free tier.`,
  byServiceTitle: '## By service',
  byService: `Each service's cost in each scenario, without the free tier.`,
  service: 'Service',
  price: 'Price',
  quantitiesTitle: '### The quantities behind each line',
  quantities: `Each price's quantity in each scenario; the unit price is in [Prices](#prices).`,
  per: 'per',
  leversTitle: '## Levers',
  levers: 'What each change to the prod stage would add (+) or save (−) a month, against the stage as it is, without the free tier.',
  lever: 'Lever',
  whatChanges: 'What changes',
  LEVERS: (endpointServices, zones, month) => [
    ['Remove the reader instance', 'The reader in the second zone goes away: a failover then waits for a new instance instead of promoting the reader ([infrastructure.md](infrastructure.md#when-something-fails)).', { withoutReader: true }],
    ['Turn on RDS Proxy', 'RDS Proxy in front of the database, billed per ACU of the database instances, with a minimum of 8 ACUs.', { withProxy: true }],
    ['Turn on the interface endpoints', `The ${endpointServices} AWS services without a gateway endpoint get an interface endpoint in each of the ${zones} zones; their traffic leaves the NAT gateway.`, { interfaceEndpoints: true }],
    ['Add a second NAT gateway', 'One NAT gateway, with its address, in each zone, so losing one zone still leaves a way out.', { secondNat: true }],
    ['CloudFront PriceClass_100', 'Edge locations in North America, Europe and Israel only: viewers in Colombia are served from North America, at its prices and with more latency.', { priceClass100: true }],
    ['Keep logs 30 days', `Every log group keeps its logs 30 days instead of 365, so storage holds one month of logs instead of ${month}.`, { retentionMonths: 1 }],
    ['Remove the metrics and alarms', 'No custom metric, alarm, composite alarm or dashboard; the logs stay, and nothing pages anyone.', { withoutObservability: true }],
    ['Move the functions to arm64', `Every function on Graviton, priced 20 % lower per GB-second; the functions' image must be built for arm64.`, { arm64: true }],
  ],
  BASES: [
    ['measured', 'Measured', `by running one of this repository's commands, on the emulator (make cloud-check) or on the demo data (make demo)`],
    ['derived', 'Derived', 'computed from measured values or from the code'],
    ['template', 'From the templates', `the prod stage's synthesized templates`],
    ['documented', 'Documented', 'AWS documentation'],
    ['assumed', 'Assumed', 'a choice of this estimate, not measured: a real deployment would replace it'],
  ],
  assumptionsTitle: '## Assumptions',
  assumptions: (measuredOn) =>
    `Every value the estimate uses, from [\`scripts/cost/usage.json\`](../scripts/cost/usage.json), per invoice unless its unit says otherwise. The measurements were taken on ${measuredOn}. The emulator (Floci) runs the local stage on one machine, so its timings are not AWS's: the durations below are assumptions, and the emulator's figures are only their floor.`,
  capacity: (list) => `Each database instance's average capacity is an assumption too, since no load test has measured it: ${list}.`,
  acu: (acu, invoices) => `${acu} ACU ${invoices === null ? 'idle' : `at ${invoices} invoices`}`,
  assumptionsHeader: '| Assumption | Value | Unit | Source |',
  pricesTitle: '## Prices',
  prices: (region, consulted, unconfirmed) =>
    `On-demand prices for ${region}, in US dollars, consulted on ${consulted} on the official pricing pages and in the AWS Price List API (\`https://pricing.us-east-1.amazonaws.com\`), whose file, version and usage type each row names. The first tier of each price applies in every scenario: the script fails if a quantity goes beyond it. ${unconfirmed.length === 0 ? 'Every price was confirmed.' : `Not confirmed: ${unconfirmed.join(', ')}.`}`,
  pricesHeader: '| Price | What | USD | Unit | Always free a month | Source |',
  pricingPage: 'Pricing page',
  notConfirmed: ' (not confirmed)',
  outsideTitle: '## Not in the estimate',
  outside: [
    'Taxes, a support plan, and the costs of the AWS account, its organization and its security services (CloudTrail beyond the free trail, GuardDuty, Security Hub).',
    'The domain and its DNS: no hosted zone is managed here ([deployment.md](deployment.md)). The ACM certificate is free.',
    'Data transfer between the two zones, within the region: the functions run in both and the database writer in one.',
    `Retries, failures and drills: the scenarios count each invoice's happy path, as make cloud-check measures it.`,
    'The deployments: continuous integration, the image builds and the web app uploads.',
    `Savings Plans, reserved capacity, and a new account's 12-month offers and credits.`,
  ],
}

const SPANISH = {
  title: '# Estimación de costos',
  generated: '<!-- Generado por make cost (scripts/cost/estimate.mjs) a partir de scripts/cost/prices.json y scripts/cost/usage.json. No lo edites: make docs-check falla cuando este archivo no coincide con esos dos archivos. -->',
  intro: (region, consulted) => `Lo que costaría al mes la arquitectura de producción si se desplegara en AWS en ${region} (US East, N. Virginia), a precios bajo demanda consultados el ${consulted}.`,
  notice:
    '> **Una estimación, no una factura.** La etapa de producción nunca se desplegó: se sintetiza y se valida sin conexión ([infrastructure.md](infrastructure.md)), y nada de esto se le cobró nunca a una cuenta de AWS. Los volúmenes por factura salen de ejecuciones de la nube emulada, las duraciones y el tráfico son supuestos, y cada uno se declara [más abajo](#supuestos). No incluye los impuestos, los planes de soporte ni la cuenta de AWS en sí.',
  how: 'Cómo se hace: `scripts/cost/estimate.mjs`, un guion sin dependencias, calcula cada tabla de esta página a partir de dos archivos: [`scripts/cost/prices.json`](../../scripts/cost/prices.json) (cada precio unitario con su página oficial, el archivo de la AWS Price List API de donde se leyó y la fecha) y [`scripts/cost/usage.json`](../../scripts/cost/usage.json) (cada supuesto con su base y su fuente). `make cost` vuelve a escribir esta página y su versión en inglés, y `make docs-check`, parte de `make lint`, falla cuando alguna de las dos no coincide con los dos archivos.',
  summaryTitle: '## Resumen',
  summary: (month) =>
    `El costo fijo es lo que cuesta el mes sin ninguna factura, y el costo variable es el resto. El almacenamiento se calcula para el mes ${month} con un volumen estable, así que guarda ${month} meses de documentos, filas de la base de datos y registros. La capa gratuita queda por fuera, salvo en la última columna. Esa columna resta los montos siempre gratis, que la propia Price List cotiza en US$ 0: Lambda, SQS, SNS, KMS, CloudWatch, CloudFront, EventBridge Scheduler, el almacenamiento de DynamoDB y 100 GB de transferencia de datos de salida. No cuenta los créditos ni las ofertas de 12 meses de una cuenta nueva.`,
  summaryHeader: '| Escenario | Facturas al mes | Fijo | Variable | Total | Por cada 1.000 facturas | Total con la capa siempre gratis |',
  idle: (fixed, fixedFree) => `Sin ninguna factura, AWS cobra la capacidad mínima de la base de datos en dos instancias, el NAT gateway, el firewall, las llaves, los secretos y la observabilidad: ${fixed} al mes, o ${fixedFree} con la capa siempre gratis.`,
  byServiceTitle: '## Por servicio',
  byService: 'El costo de cada servicio en cada escenario, sin la capa gratuita.',
  service: 'Servicio',
  price: 'Precio',
  quantitiesTitle: '### Las cantidades de cada línea',
  quantities: 'La cantidad de cada precio en cada escenario; el precio unitario está en [Precios](#precios).',
  per: 'por',
  leversTitle: '## Palancas',
  levers: 'Lo que cada cambio en la etapa de producción sumaría (+) o ahorraría (−) al mes, frente a la etapa tal como está, sin la capa gratuita.',
  lever: 'Palanca',
  whatChanges: 'Qué cambia',
  LEVERS: (endpointServices, zones, month) => [
    ['Quitar la instancia de lectura', 'Desaparece la instancia de lectura de la segunda zona: un failover espera entonces una instancia nueva en vez de promover la de lectura ([infrastructure.md](infrastructure.md#cuando-algo-falla)).', { withoutReader: true }],
    ['Activar RDS Proxy', 'RDS Proxy delante de la base de datos, cobrado por ACU de las instancias de la base de datos, con un mínimo de 8 ACU.', { withProxy: true }],
    ['Activar los endpoints de interfaz', `Los ${endpointServices} servicios de AWS sin endpoint de gateway reciben un endpoint de interfaz en cada una de las ${zones} zonas; su tráfico deja de pasar por el NAT gateway.`, { interfaceEndpoints: true }],
    ['Agregar un segundo NAT gateway', 'Un NAT gateway, con su dirección, en cada zona, para que, si se pierde una zona, todavía quede una salida.', { secondNat: true }],
    ['CloudFront PriceClass_100', 'Ubicaciones de borde solo en Norteamérica, Europa e Israel: a los visitantes en Colombia se les atiende desde Norteamérica, a sus precios y con más latencia.', { priceClass100: true }],
    ['Conservar los registros 30 días', `Cada grupo de registros conserva sus registros 30 días en vez de 365, así que el almacenamiento guarda un mes de registros en vez de ${month}.`, { retentionMonths: 1 }],
    ['Quitar las métricas y las alarmas', 'Sin métricas personalizadas, alarmas, alarmas compuestas ni tablero; los registros se quedan, y nada le avisa a nadie.', { withoutObservability: true }],
    ['Pasar las funciones a arm64', 'Todas las funciones en Graviton, con un precio 20 % menor por GB-segundo; la imagen de las funciones tiene que construirse para arm64.', { arm64: true }],
  ],
  BASES: [
    ['measured', 'Medidos', 'al correr uno de los comandos de este repositorio, en el emulador (make cloud-check) o con los datos de ejemplo (make demo)'],
    ['derived', 'Derivados', 'calculados a partir de valores medidos o del código'],
    ['template', 'De las plantillas', 'las plantillas sintetizadas de la etapa de producción'],
    ['documented', 'Documentados', 'la documentación de AWS'],
    ['assumed', 'Asumidos', 'una decisión de esta estimación, no una medición: un despliegue real la reemplazaría'],
  ],
  assumptionsTitle: '## Supuestos',
  assumptions: (measuredOn) =>
    `Cada valor que usa la estimación, de [\`scripts/cost/usage.json\`](../../scripts/cost/usage.json), por factura salvo que su unidad diga otra cosa. Las mediciones se tomaron el ${measuredOn}. El emulador (Floci) corre la etapa local en una sola máquina, así que sus tiempos no son los de AWS: las duraciones de abajo son supuestos, y las cifras del emulador son solo su piso.`,
  capacity: (list) => `La capacidad promedio de cada instancia de la base de datos también es un supuesto, porque ninguna prueba de carga la ha medido: ${list}.`,
  acu: (acu, invoices) => `${acu} ACU ${invoices === null ? 'sin facturas' : `con ${invoices} facturas`}`,
  assumptionsHeader: '| Supuesto | Valor | Unidad | Fuente |',
  pricesTitle: '## Precios',
  prices: (region, consulted, unconfirmed) =>
    `Precios bajo demanda para ${region}, en dólares estadounidenses, consultados el ${consulted} en las páginas oficiales de precios y en la AWS Price List API (\`https://pricing.us-east-1.amazonaws.com\`), cuyo archivo, versión y tipo de uso nombra cada fila. El primer tramo de cada precio se aplica en todos los escenarios: el guion falla si una cantidad lo supera. ${unconfirmed.length === 0 ? 'Todos los precios se confirmaron.' : `Sin confirmar: ${unconfirmed.join(', ')}.`}`,
  pricesHeader: '| Precio | Qué | USD | Unidad | Siempre gratis al mes | Fuente |',
  pricingPage: 'Página de precios',
  notConfirmed: ' (sin confirmar)',
  outsideTitle: '## Lo que no está en la estimación',
  outside: [
    'Los impuestos, un plan de soporte, y los costos de la cuenta de AWS, de su organización y de sus servicios de seguridad (CloudTrail más allá del trail gratuito, GuardDuty, Security Hub).',
    'El dominio y su DNS: aquí no se administra ninguna zona alojada ([deployment.md](deployment.md)). El certificado de ACM es gratis.',
    'La transferencia de datos entre las dos zonas, dentro de la región: las funciones corren en las dos y la instancia de escritura de la base de datos en una.',
    'Los reintentos, las fallas y los simulacros: los escenarios cuentan el camino feliz de cada factura, como lo mide make cloud-check.',
    'Los despliegues: la integración continua, la construcción de las imágenes y las subidas de la aplicación web.',
    'Los Savings Plans, la capacidad reservada, y las ofertas de 12 meses y los créditos de una cuenta nueva.',
  ],
}

const PAGES = [
  ['docs/cost.md', 'en'],
  ['docs/es/cost.md', 'es'],
]
const texts = PAGES.map(([path, language]) => [path, page(language)])

if (check) {
  const stale = texts.filter(([path, text]) => {
    try {
      return readFileSync(join(root, path), 'utf8') !== text
    } catch {
      return true
    }
  })

  if (stale.length > 0) {
    console.error(`${stale.map(([path]) => path).join(' and ')} ${stale.length === 1 ? 'does' : 'do'} not match scripts/cost/prices.json and scripts/cost/usage.json: run make cost.`)
    process.exit(1)
  }

  console.log(`cost: docs/cost.md and docs/es/cost.md match scripts/cost/prices.json (${Object.keys(prices.prices).length} prices) and scripts/cost/usage.json (${Object.keys(usage.assumptions).length} assumptions, ${scenarios.length} scenarios).`)
} else {
  for (const [path, text] of texts) {
    writeFileSync(join(root, path), text)
  }

  const usd = (amount) => amount.toLocaleString('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 })
  console.log(`docs/cost.md and docs/es/cost.md written: ${scenarios.map(({ name }, index) => `${name} ${usd(total(bills[index].lines))}`).join(', ')}.`)
}
