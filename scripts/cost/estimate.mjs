// make cost: docs/cost.md, the estimate of what the prod stage would cost a month in us-east-1, computed from two files
// and nothing else: scripts/cost/prices.json (each unit price with its official source and the date it was consulted)
// and scripts/cost/usage.json (each assumption with where it comes from). No dependencies, no network.
// With --check (make docs-check, part of make lint) it writes nothing and fails when docs/cost.md is not what the two
// files give.
//
//   node scripts/cost/estimate.mjs [--check] [<root of the repository>]
import { readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const args = process.argv.slice(2)
const check = args.includes('--check')
const root = args.find((arg) => !arg.startsWith('--')) ?? fileURLToPath(new URL('../..', import.meta.url))
const target = join(root, 'docs/cost.md')
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

const usd = (amount) => (amount > 0 && amount < 0.005 ? '< $0.01' : amount.toLocaleString('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const signed = (amount) => (Math.abs(amount) < 0.005 ? '$0.00' : `${amount > 0 ? '+' : '−'}${usd(Math.abs(amount))}`)
const count = (amount) => amount.toLocaleString('en-US', { maximumFractionDigits: 2 })
function unitPrice(amount) {
  if (amount >= 1) {
    return usd(amount)
  }

  const decimals = Math.min(12, Math.max(2, 5 - Math.floor(Math.log10(amount))))
  const text = amount.toFixed(decimals).replace(/(\.\d*?[1-9])0+$/, '$1')

  return `$${/\.\d$/.test(text) ? `${text}0` : text}`
}
const cell = (text) => String(text).replaceAll('|', '\\|')

const scenarios = usage.scenarios
const idle = scenarios.find((scenario) => scenario.invoices === 0)

if (idle === undefined) {
  throw new Error('scripts/cost/usage.json needs a scenario of 0 invoices: it gives the fixed cost.')
}

const fixed = total(bill(idle))
const fixedFree = total(bill(idle), true)
const bills = scenarios.map((scenario) => ({ scenario, lines: bill(scenario) }))
const services = [...new Set(bills.flatMap(({ lines }) => lines.map((line) => line.service)))]
const out = []

out.push(
  '# Cost estimate',
  '',
  '<!-- Generated by make cost (scripts/cost/estimate.mjs) from scripts/cost/prices.json and scripts/cost/usage.json. Do not edit: make docs-check fails when this file does not match those two files. -->',
  '',
  `What the production architecture would cost a month if it were deployed to AWS in us-east-1 (US East, N. Virginia), at on-demand prices consulted on ${prices.consulted}.`,
  '',
  '> **An estimate, not a bill.** The prod stage was never deployed: it is synthesized and validated offline ([infrastructure.md](infrastructure.md)), and nothing here was ever charged to an AWS account. The volumes per invoice come from runs of the emulated cloud, the durations and the traffic are assumptions, and each one is declared [below](#assumptions). Taxes, support plans and the AWS account itself are not included.',
  '',
  `How it is made: \`scripts/cost/estimate.mjs\`, a script without dependencies, computes every table on this page from two files: [\`scripts/cost/prices.json\`](../scripts/cost/prices.json) (each unit price with its official page, the AWS Price List API file it was read from, and the date) and [\`scripts/cost/usage.json\`](../scripts/cost/usage.json) (each assumption with its basis and its source). \`make cost\` writes this page again, and \`make docs-check\`, part of \`make lint\`, fails when the page does not match the two files.`,
  '',
  '## Summary',
  '',
  `The fixed cost is what the month costs with no invoice at all, and the variable cost is the rest. Storage is priced for month ${month} at a steady volume, so it holds ${month} months of documents, database rows and logs. The free tier is left out, except in the last column. That column subtracts the always-free amounts, which the Price List itself prices at $0: Lambda, SQS, SNS, KMS, CloudWatch, CloudFront, EventBridge Scheduler, DynamoDB storage and 100 GB of data transfer out. It does not count a new account's credits or 12-month offers.`,
  '',
  '| Scenario | Invoices a month | Fixed | Variable | Total | Per 1,000 invoices | Total with the always-free tier |',
  '| --- | ---: | ---: | ---: | ---: | ---: | ---: |',
)

for (const { scenario, lines } of bills) {
  const amount = total(lines)
  const perThousand = scenario.invoices === 0 ? '—' : usd((amount / scenario.invoices) * 1000)
  out.push(`| ${scenario.name} | ${count(scenario.invoices)} | ${usd(fixed)} | ${usd(amount - fixed)} | **${usd(amount)}** | ${perThousand} | ${usd(total(lines, true))} |`)
}

out.push(
  '',
  `With no invoice, the bill is the database's minimum capacity on two instances, the NAT gateway, the firewall, the keys, the secrets and observability: ${usd(fixed)} a month, or ${usd(fixedFree)} with the always-free tier.`,
  '',
  '## By service',
  '',
  `Each service's cost in each scenario, without the free tier.`,
  '',
  `| Service | ${scenarios.map((scenario) => scenario.name).join(' | ')} |`,
  `| --- | ${scenarios.map(() => '---:').join(' | ')} |`,
)

const sums = bills.map(({ lines }) => byService(lines))

for (const service of services) {
  out.push(`| ${service} | ${sums.map((sum) => usd(sum.get(service) ?? 0)).join(' | ')} |`)
}

out.push(`| **Total** | ${bills.map(({ lines }) => `**${usd(total(lines))}**`).join(' | ')} |`, '', '### The quantities behind each line', '', `Each price's quantity in each scenario; the unit price is in [Prices](#prices).`, '', `| Service | Price | ${scenarios.map((scenario) => scenario.name).join(' | ')} |`, `| --- | --- | ${scenarios.map(() => '---:').join(' | ')} |`)

for (const [index, line] of bills[0].lines.entries()) {
  const unit = price(line.key)
  out.push(`| ${line.service} | ${cell(unit.what)}, per ${unit.unit} | ${bills.map(({ lines }) => count(lines[index].quantity)).join(' | ')} |`)
}

// The levers: each one against the stage as it is, in each scenario.
const LEVERS = [
  ['Remove the reader instance', 'The reader in the second zone goes away: a failover then waits for a new instance instead of promoting the reader ([infrastructure.md](infrastructure.md#when-something-fails)).', { withoutReader: true }],
  ['Turn on RDS Proxy', 'RDS Proxy in front of the database, billed per ACU of the database instances, with a minimum of 8 ACUs.', { withProxy: true }],
  ['Turn on the interface endpoints', `The ${value('interfaceEndpointServices')} AWS services without a gateway endpoint get an interface endpoint in each of the ${value('zones')} zones; their traffic leaves the NAT gateway.`, { interfaceEndpoints: true }],
  ['Add a second NAT gateway', 'One NAT gateway, with its address, in each zone, so losing one zone still leaves a way out.', { secondNat: true }],
  ['CloudFront PriceClass_100', 'Edge locations in North America, Europe and Israel only: viewers in Colombia are served from North America, at its prices and with more latency.', { priceClass100: true }],
  ['Keep logs 30 days', `Every log group keeps its logs 30 days instead of 365, so storage holds one month of logs instead of ${month}.`, { retentionMonths: 1 }],
  ['Remove the metrics and alarms', 'No custom metric, alarm, composite alarm or dashboard; the logs stay, and nothing pages anyone.', { withoutObservability: true }],
  ['Move the functions to arm64', `Every function on Graviton, priced 20 % lower per GB-second; the functions' image must be built for arm64.`, { arm64: true }],
]

out.push('', '## Levers', '', 'What each change to the prod stage would add (+) or save (−) a month, against the stage as it is, without the free tier.', '', `| Lever | What changes | ${scenarios.map((scenario) => scenario.name).join(' | ')} |`, `| --- | --- | ${scenarios.map(() => '---:').join(' | ')} |`)

for (const [name, what, options] of LEVERS) {
  const deltas = scenarios.map((scenario) => total(bill(scenario, options)) - total(bill(scenario)))
  out.push(`| ${name} | ${what} | ${deltas.map(signed).join(' | ')} |`)
}

const BASES = [
  ['measured', 'Measured', `by running one of this repository's commands, on the emulator (make cloud-check) or on the demo data (make demo)`],
  ['derived', 'Derived', 'computed from measured values or from the code'],
  ['template', 'From the templates', `the prod stage's synthesized templates`],
  ['documented', 'Documented', 'AWS documentation'],
  ['assumed', 'Assumed', 'a choice of this estimate, not measured: a real deployment would replace it'],
]

out.push(
  '',
  '## Assumptions',
  '',
  `Every value the estimate uses, from [\`scripts/cost/usage.json\`](../scripts/cost/usage.json), per invoice unless its unit says otherwise. The measurements were taken on ${usage.measuredOn}. The emulator (Floci) runs the local stage on one machine, so its timings are not AWS's: the durations below are assumptions, and the emulator's figures are only their floor.`,
  '',
  `Each database instance's average capacity is an assumption too, since no load test has measured it: ${scenarios.map((scenario) => `${scenario.auroraAcu} ACU ${scenario.invoices === 0 ? 'idle' : `at ${count(scenario.invoices)} invoices`}`).join(', ')}.`,
)

for (const [basis, title, meaning] of BASES) {
  const entries = Object.entries(usage.assumptions).filter(([, assumption]) => assumption.basis === basis)

  if (entries.length === 0) {
    continue
  }

  out.push('', `### ${title}`, '', `${meaning[0].toUpperCase()}${meaning.slice(1)}.`, '', '| Assumption | Value | Unit | Source |', '| --- | ---: | --- | --- |')

  for (const [key, assumption] of entries) {
    out.push(`| \`${key}\` | ${assumption.value.toLocaleString('en-US', { maximumFractionDigits: 3 })} | ${cell(assumption.unit)} | ${cell(assumption.source)} |`)
  }
}

const unconfirmed = Object.entries(prices.prices).filter(([, unit]) => unit.confirmed !== true)

out.push(
  '',
  '## Prices',
  '',
  `On-demand prices for ${prices.region}, in US dollars, consulted on ${prices.consulted} on the official pricing pages and in the AWS Price List API (\`https://pricing.us-east-1.amazonaws.com\`), whose file, version and usage type each row names. The first tier of each price applies in every scenario: the script fails if a quantity goes beyond it. ${unconfirmed.length === 0 ? 'Every price was confirmed.' : `Not confirmed: ${unconfirmed.map(([key]) => `\`${key}\``).join(', ')}.`}`,
  '',
  '| Price | What | USD | Unit | Always free a month | Source |',
  '| --- | --- | ---: | --- | ---: | --- |',
)

for (const [key, unit] of Object.entries(prices.prices)) {
  out.push(`| \`${key}\` | ${cell(unit.what)} | ${unitPrice(unit.usd)} | ${cell(unit.unit)} | ${unit.free === undefined ? '' : count(unit.free)} | [Pricing page](${unit.page}); ${cell(unit.offer)}${unit.confirmed === true ? '' : ' (not confirmed)'} |`)
}

out.push(
  '',
  '## Not in the estimate',
  '',
  '- Taxes, a support plan, and the costs of the AWS account, its organization and its security services (CloudTrail beyond the free trail, GuardDuty, Security Hub).',
  '- The domain and its DNS: no hosted zone is managed here ([deployment.md](deployment.md)). The ACM certificate is free.',
  '- Data transfer between the two zones, within the region: the functions run in both and the database writer in one.',
  `- Retries, failures and drills: the scenarios count each invoice's happy path, as make cloud-check measures it.`,
  '- The deployments: continuous integration, the image builds and the web app uploads.',
  `- Savings Plans, reserved capacity, and a new account's 12-month offers and credits.`,
  '',
)

const text = out.join('\n')

if (check) {
  let current = ''

  try {
    current = readFileSync(target, 'utf8')
  } catch {
    current = ''
  }

  if (current !== text) {
    console.error('docs/cost.md does not match scripts/cost/prices.json and scripts/cost/usage.json: run make cost.')
    process.exit(1)
  }

  console.log(`cost: docs/cost.md matches scripts/cost/prices.json (${Object.keys(prices.prices).length} prices) and scripts/cost/usage.json (${Object.keys(usage.assumptions).length} assumptions, ${scenarios.length} scenarios).`)
} else {
  writeFileSync(target, text)
  console.log(`docs/cost.md written: ${scenarios.map(({ name }, index) => `${name} ${usd(total(bills[index].lines))}`).join(', ')}.`)
}
