// The two identities of the database, each with its secret in Secrets Manager, in both stages:
//  - migrations: may change the schema. Only the console function uses it, which also provisions the other
//    one (php artisan db:application-identity). In prod it is the master user of the cluster.
//  - application: reads and writes data and nothing of the schema. Every other function uses it.
// In prod they live in the stack database, next to the cluster whose rotation functions change them; in the
// stage local, which has no managed database, in the stack security, for the MySQL of compose, where make
// cloud-up creates the migrations user and the console function the application user. The secrets use the
// format of Amazon RDS (username, password, dbname, and in prod host, port and engine once attached to the
// cluster); in prod the application one names the migrations one as its masterarn, the superuser the rotation
// with alternating users clones and alters the second user with.
import { Stack, Validations } from 'aws-cdk-lib'
import { DatabaseSecret } from 'aws-cdk-lib/aws-rds'
import { Construct } from 'constructs'
import { Published } from './published.ts'
import { PROJECT, removalPolicy, type StageConfig } from './stage.ts'

/** What the generated passwords leave out: the default of Amazon RDS, which keeps them safe in SQL and in a shell. */
export const PASSWORD_EXCLUDED = ' %+~`#$&*()|[]{}:;<>?!\'/@"\\'

export class DatabaseIdentities extends Construct {
  readonly migrations: DatabaseSecret
  readonly application: DatabaseSecret

  constructor(scope: Construct, id: string, stage: StageConfig) {
    super(scope, id)
    const { database } = stage

    this.migrations = new DatabaseSecret(this, 'Migrations', {
      secretName: `${PROJECT}/${stage.name}/database/migrations`,
      username: database.migrationsUser,
      dbname: database.name,
      excludeCharacters: PASSWORD_EXCLUDED,
    })

    // The masterarn names the superuser the rotation function of AWS clones and alters the second user with,
    // so only a stage with a managed database (and its rotation functions) has it; the emulator would not
    // apply a template built with it either (docs/limitations.md).
    this.application = new DatabaseSecret(this, 'Application', {
      secretName: `${PROJECT}/${stage.name}/database/application`,
      username: database.applicationUser,
      dbname: database.name,
      ...(database.managed === null ? {} : { masterSecret: this.migrations }),
      excludeCharacters: PASSWORD_EXCLUDED,
    })

    for (const secret of [this.migrations, this.application]) {
      secret.applyRemovalPolicy(removalPolicy(stage))

      if (database.managed === null) {
        Validations.of(secret).acknowledge({
          id: 'AwsSolutions::AwsSolutions-SMG4',
          reason: 'The emulator cannot run the rotation functions of AWS (applications of the Serverless Application Repository): make cloud-check rotates the application identity the way they do, alternating users, and prod rotates both identities with them.',
        })
      }
    }

    const published = new Published(Stack.of(this), stage, 'database')
    published.add('DatabaseSecretArn', 'secret-arn', this.application.secretArn, 'Secret of the application identity of the database (reads and writes data, no schema permissions), for every function but the console')
    published.add('DatabaseMigrationsSecretArn', 'migrations-secret-arn', this.migrations.secretArn, 'Secret of the migrations identity of the database, for the console function only')
  }
}
