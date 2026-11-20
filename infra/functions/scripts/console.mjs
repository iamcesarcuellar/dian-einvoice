// Runs php artisan in the console function of a stage (the console runtime of Bref) and prints what it
// printed: make cloud-up migrates with it, make cloud-demo seeds, make cloud-check runs its steps.
//
//   node scripts/console.mjs migrate --force --no-interaction
//
// The command line goes as the event of the invocation; the function runs it within its timeout (900 s).
// An invocation is never retried: a step of make cloud-check must not run twice.
import { InvokeCommand, LambdaClient } from '@aws-sdk/client-lambda'

const stage = process.env.STAGE ?? 'local'
const command = process.argv.slice(2).join(' ')

if (command === '') {
  process.stderr.write('usage: node scripts/console.mjs <artisan command and options>\n')
  process.exit(2)
}

const lambda = new LambdaClient({ maxAttempts: 1, requestHandler: { connectionTimeout: 5_000, requestTimeout: 960_000 } })
const result = await lambda.send(new InvokeCommand({ FunctionName: `dian-einvoice-${stage}-console`, Payload: JSON.stringify(command) }))
const text = Buffer.from(result.Payload ?? new Uint8Array()).toString('utf8')
const payload = text === '' ? null : JSON.parse(text)

if (result.FunctionError !== undefined) {
  // The console runtime fails the invocation when the command exits with an error, with its output.
  process.stdout.write(typeof payload?.errorMessage === 'string' ? `${payload.errorMessage}\n` : `${text}\n`)
  process.stderr.write(`php artisan ${command}: the console function failed (${result.FunctionError}).\n`)
  process.exit(1)
}

process.stdout.write(typeof payload?.output === 'string' ? payload.output : `${text}\n`)
process.exit(typeof payload?.exitCode === 'number' ? payload.exitCode : 0)
