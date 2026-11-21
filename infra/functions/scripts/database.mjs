// make cloud-up: prints the SQL that creates or updates the migrations identity of the database of the emulated
// cloud on the MySQL of compose, which make cloud-up runs as the MySQL root user, as Aurora creates the master
// user of a cluster from its secret. The user, its password and the database come from the secret of the
// identity in the Secrets Manager of the emulator (dian-einvoice/local/database/migrations, stack security of
// the CDK app); the console function then creates the application identity with it (php artisan
// db:application-identity). The output holds a password: make cloud-up pipes it to MySQL, never to the terminal.
//
//   node scripts/database.mjs migrations-identity
import { GetSecretValueCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'

const STAGE = process.env.STAGE ?? 'local'
const action = process.argv[2] ?? ''

if (action !== 'migrations-identity') {
  process.stderr.write('usage: node scripts/database.mjs migrations-identity\n')
  process.exit(2)
}

const name = `dian-einvoice/${STAGE}/database/migrations`
const { SecretString: value } = await new SecretsManagerClient({}).send(new GetSecretValueCommand({ SecretId: name }))
const secret = JSON.parse(value ?? '{}')

for (const [field, pattern] of [['username', /^[A-Za-z0-9_]{1,32}$/], ['dbname', /^[A-Za-z0-9_]{1,64}$/]]) {
  if (typeof secret[field] !== 'string' || !pattern.test(secret[field])) {
    process.stderr.write(`The secret ${name} has no plain ${field}.\n`)
    process.exit(1)
  }
}

if (typeof secret.password !== 'string' || secret.password.length < 20) {
  process.stderr.write(`The secret ${name} has no password.\n`)
  process.exit(1)
}

const account = `'${secret.username}'@'%'`
// Secrets Manager leaves quotes and backslashes out of the password (PASSWORD_EXCLUDED in the CDK app); escaped anyway.
const password = `'${secret.password.replaceAll('\\', '\\\\').replaceAll("'", "''")}'`

process.stdout.write([
  `CREATE USER IF NOT EXISTS ${account} IDENTIFIED BY ${password} REQUIRE SSL;`,
  `ALTER USER ${account} IDENTIFIED BY ${password} REQUIRE SSL;`,
  // The schema of its database, and the privileges it passes on to the application identity, which it creates; PROCESS,
  // which the master user of Aurora holds too, lets php artisan db:connections see who holds the connections.
  `GRANT ALL PRIVILEGES ON \`${secret.dbname}\`.* TO ${account} WITH GRANT OPTION;`,
  `GRANT CREATE USER, PROCESS ON *.* TO ${account};`,
  '',
].join('\n'))
