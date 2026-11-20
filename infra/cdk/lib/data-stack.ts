// Data: the tables of DynamoDB of the cache, the web sessions and the rate limits, in the format of
// Laravel's DynamoDB store (partition key "key", automatic expiry on "expires_at").
import { Stack, type StackProps, Validations } from 'aws-cdk-lib'
import { AttributeType, BillingMode, Table, TableEncryption } from 'aws-cdk-lib/aws-dynamodb'
import { ManagedPolicy, PolicyStatement } from 'aws-cdk-lib/aws-iam'
import type { Construct } from 'constructs'
import { Published } from './published.ts'
import { resourceName, type StageConfig } from './stage.ts'

export interface DataStackProps extends StackProps {
  readonly stage: StageConfig
}

export type TableUse = 'cache' | 'sessions' | 'limits'

const USES: Readonly<Record<TableUse, string>> = {
  cache: 'the cache',
  sessions: 'the sessions of the web app',
  limits: 'the rate limits',
}

export class DataStack extends Stack {
  readonly tables: Readonly<Record<TableUse, Table>>

  constructor(scope: Construct, id: string, props: DataStackProps) {
    super(scope, id, props)
    const { stage } = props
    const published = new Published(this, stage, 'data')
    const tables: Partial<Record<TableUse, Table>> = {}

    for (const use of Object.keys(USES) as TableUse[]) {
      const name = use.charAt(0).toUpperCase() + use.slice(1)

      // Everything here expires on its own and is rebuilt on use: no backups, and a table can be
      // recreated empty.
      const table = new Table(this, `${name}Table`, {
        tableName: resourceName(stage, use),
        partitionKey: { name: 'key', type: AttributeType.STRING },
        timeToLiveAttribute: 'expires_at',
        billingMode: BillingMode.PAY_PER_REQUEST,
        encryption: TableEncryption.AWS_MANAGED,
      })

      Validations.of(table).acknowledge({
        id: 'AwsSolutions::AwsSolutions-DDB3',
        reason: `The table holds ${USES[use]} only: every item expires within hours and is rebuilt on use, so a point-in-time restore has nothing worth restoring.`,
      })

      // Laravel's DynamoDB store reads, writes, increments and deletes single items, and reads and writes
      // a few at once; it never scans or queries.
      const policy = new ManagedPolicy(this, `Use${name}TablePolicy`, {
        managedPolicyName: resourceName(stage, `use-${use}-table`),
        description: `Read and write ${USES[use]} of dian-einvoice (${stage.name})`,
        statements: [new PolicyStatement({
          actions: ['dynamodb:GetItem', 'dynamodb:BatchGetItem', 'dynamodb:PutItem', 'dynamodb:BatchWriteItem', 'dynamodb:UpdateItem', 'dynamodb:DeleteItem', 'dynamodb:DescribeTable'],
          resources: [table.tableArn],
        })],
      })

      tables[use] = table
      published.add(`${name}TableName`, `${use}-table-name`, table.tableName, `Table of ${USES[use]}`)
      published.add(`Use${name}TablePolicyArn`, `policies/use-${use}-table`, policy.managedPolicyArn, `Managed policy for the processes that use ${USES[use]}`)
    }

    this.tables = tables as Record<TableUse, Table>
  }
}
