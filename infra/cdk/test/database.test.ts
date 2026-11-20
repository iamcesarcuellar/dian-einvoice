// Assertions on the stack database of the stage prod as synthesized (never deployed, not even to the emulator):
// Aurora MySQL 8.4 Serverless v2 in the isolated subnets and never public; encrypted with a rotating key of its
// own, protected against deletion, kept with the stack, backed up, with a maintenance window and its logs; TLS
// required; more connections than the functions hold plus a margin, tied to the template of the functions as
// osls packaged it; and the two identities of the application with their secrets and the rotation functions
// of AWS. Then the identities of the stage local, which has no managed database.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { AURORA_MYSQL_MAX_CONNECTIONS, CONNECTION_MARGIN, DATABASE_LOGS, maxConnections, type DatabaseStack } from '../lib/database-stack.ts'
import type { NetworkStack } from '../lib/network-stack.ts'
import { DATABASE_PARAMETERS, STAGES, type ManagedDatabaseSettings, type NetworkSettings } from '../lib/stage.ts'
import { connectionBudget, stageOf, template } from './support.ts'

type Resources = Record<string, { Type: string, Properties: Record<string, unknown>, DeletionPolicy?: string, UpdateReplacePolicy?: string, DependsOn?: string[] }>

const prod = STAGES.prod
const settings = prod.database.managed as ManagedDatabaseSettings
const zones = (prod.network as NetworkSettings).availabilityZones
const stacks = stageOf('prod')
const database = stacks.database as DatabaseStack
const network = stacks.network as NetworkStack
const body = template(database)
const resources = (body.toJSON() as { Resources: Resources }).Resources
const networkBody = template(network).toJSON() as { Resources: Resources, Outputs: Record<string, { Value: unknown }> }

function only(type: string): [string, Resources[string]] {
  const found = Object.entries(resources).filter(([, resource]) => resource.Type === type)
  assert.equal(found.length, 1, `one ${type}`)

  return found[0] as [string, Resources[string]]
}

/** The logical id in the stack network a cross-stack reference of the stack database points at. */
function inNetwork(reference: unknown): string {
  const output = (reference as { 'Fn::GetStackOutput': { OutputName: string } })['Fn::GetStackOutput']
  const value = networkBody.Outputs[output.OutputName]?.Value as { Ref?: string, 'Fn::GetAtt'?: [string] }

  return value.Ref ?? value['Fn::GetAtt']?.[0] ?? ''
}

describe('database of the stage prod', () => {
  const [clusterId, cluster] = only('AWS::RDS::DBCluster')

  it('is Aurora MySQL 8.4 Serverless v2, with the capacity of the stage, in the isolated subnets and never public', () => {
    assert.equal(cluster.Properties.Engine, 'aurora-mysql')
    assert.match(String(cluster.Properties.EngineVersion), /^8\.4\.mysql_aurora\.8\.4\.\d+$/)
    assert.deepEqual(cluster.Properties.ServerlessV2ScalingConfiguration, { MinCapacity: settings.minCapacity, MaxCapacity: settings.maxCapacity })
    body.hasResourceProperties('AWS::RDS::DBClusterParameterGroup', { Family: 'aurora-mysql8.4' })

    // The subnet group holds exactly the isolated subnets of the network.
    const [, group] = only('AWS::RDS::DBSubnetGroup')
    const isolated = Object.entries(networkBody.Resources)
      .filter(([, resource]) => resource.Type === 'AWS::EC2::Subnet' && (resource.Properties.Tags as { Key: string, Value: string }[]).some((tag) => tag.Key === 'aws-cdk:subnet-name' && tag.Value === 'isolated'))
      .map(([id]) => id)
    assert.deepEqual((group.Properties.SubnetIds as unknown[]).map(inNetwork).sort(), isolated.sort())

    // A writer in the first zone and, by default, a reader in the second one that scales with it (tier 1).
    const instances = Object.values(resources).filter((resource) => resource.Type === 'AWS::RDS::DBInstance').map((resource) => resource.Properties)
    assert.deepEqual(instances.map((instance) => [instance.DBInstanceIdentifier, instance.AvailabilityZone, instance.PromotionTier]), [
      ['dian-einvoice-prod-database-writer', zones[0], 0],
      ...(settings.reader ? [['dian-einvoice-prod-database-reader', zones[1], 1]] : []),
    ])

    for (const instance of instances) {
      assert.equal(instance.DBInstanceClass, 'db.serverless')
      assert.equal(instance.PubliclyAccessible, false)
      assert.equal(instance.CACertificateIdentifier, 'rds-ca-rsa2048-g1', 'signed by a CA of the RDS bundle in the image of the functions')
      assert.deepEqual(instance.DBClusterIdentifier, { Ref: clusterId })
    }
  })

  it('is encrypted with a rotating key of its own, protected against deletion, kept with the stack, with backups and a maintenance window', () => {
    const [keyId, key] = only('AWS::KMS::Key')
    assert.equal(key.Properties.EnableKeyRotation, true)
    assert.equal(key.DeletionPolicy, 'Retain')
    assert.equal(cluster.Properties.StorageEncrypted, true)
    assert.deepEqual(cluster.Properties.KmsKeyId, { 'Fn::GetAtt': [keyId, 'Arn'] })
    assert.equal(cluster.Properties.DeletionProtection, true)
    assert.equal(cluster.DeletionPolicy, 'Retain')
    assert.equal(cluster.UpdateReplacePolicy, 'Retain')
    assert.ok(Object.values(resources).filter((resource) => resource.Type === 'AWS::RDS::DBInstance').every((instance) => instance.DeletionPolicy === 'Retain'))
    assert.equal(cluster.Properties.BackupRetentionPeriod, settings.backupRetention.toDays())
    assert.equal(cluster.Properties.PreferredBackupWindow, settings.backupWindow)
    assert.equal(cluster.Properties.PreferredMaintenanceWindow, settings.maintenanceWindow)
    assert.equal(cluster.Properties.CopyTagsToSnapshot, true)

    // The backups run on any day, and must not overlap the weekly maintenance window.
    const minutes = (time: string) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5))
    const [backupStart, backupEnd] = settings.backupWindow.split('-').map(minutes) as [number, number]
    const [maintenanceStart, maintenanceEnd] = settings.maintenanceWindow.split('-').map((part) => minutes(part.slice(4))) as [number, number]
    assert.ok(backupEnd <= maintenanceStart || maintenanceEnd <= backupStart, 'the backup window and the maintenance window do not overlap')
  })

  it('requires TLS 1.2 or 1.3 and exports its error, slow query and audit logs to log groups kept a year', () => {
    const [groupId, group] = only('AWS::RDS::DBClusterParameterGroup')
    assert.deepEqual(cluster.Properties.DBClusterParameterGroupName, { Ref: groupId })
    assert.deepEqual(group.Properties.Parameters, {
      require_secure_transport: 'ON',
      tls_version: 'TLSv1.2,TLSv1.3',
      slow_query_log: '1',
      long_query_time: '1',
      log_output: 'FILE',
      server_audit_logging: '1',
      server_audit_events: 'CONNECT,QUERY_DCL,QUERY_DDL',
      server_audit_excl_users: 'rdsadmin',
    })
    assert.deepEqual(cluster.Properties.EnableCloudwatchLogsExports, [...DATABASE_LOGS])

    // Created before the cluster, with their retention, so RDS does not create them to keep forever.
    for (const log of DATABASE_LOGS) {
      const [id, logGroup] = Object.entries(resources).find(([, resource]) => resource.Type === 'AWS::Logs::LogGroup' && resource.Properties.LogGroupName === `/aws/rds/cluster/dian-einvoice-prod-database/${log}`) ?? []
      assert.ok(id !== undefined && logGroup !== undefined, `a log group for the ${log} log`)
      assert.equal(logGroup.Properties.RetentionInDays, 365)
      assert.equal(logGroup.DeletionPolicy, 'Retain')
      assert.ok(cluster.DependsOn?.includes(id), `the cluster waits for the log group of the ${log} log`)
    }
  })

  it('gives more connections than the functions hold at once, with a margin for the rotation functions and the operators', () => {
    // max_connections of Aurora MySQL Serverless v2 follows the maximum capacity and grows with it.
    assert.ok(AURORA_MYSQL_MAX_CONNECTIONS.every(([capacity, connections], index) => index === 0 || (capacity > (AURORA_MYSQL_MAX_CONNECTIONS[index - 1]?.[0] ?? 0) && connections >= (AURORA_MYSQL_MAX_CONNECTIONS[index - 1]?.[1] ?? 0))))
    const budget = connectionBudget('prod')
    const offered = maxConnections(settings.maxCapacity)
    assert.equal(budget, 60, 'the reserved concurrency of the functions as osls packaged them')
    assert.ok(offered >= budget + CONNECTION_MARGIN, `${offered} connections at ${settings.maxCapacity} ACU for ${budget} of the functions and a margin of ${CONNECTION_MARGIN}`)
  })

  it('authenticates with passwords, on the default port, without Backtrack, each acknowledged with its reason (test/nag.test.ts)', () => {
    assert.equal(cluster.Properties.EnableIAMDatabaseAuthentication, false)
    assert.equal(cluster.Properties.Port, settings.port)
    assert.equal(cluster.Properties.BacktrackWindow, undefined)
  })

  it('keeps each identity in a secret of its own: the migrations identity is the master user, the application one names it as its superuser', () => {
    const secrets = Object.entries(resources).filter(([, resource]) => resource.Type === 'AWS::SecretsManager::Secret')
    const migrations = secrets.find(([, secret]) => secret.Properties.Name === 'dian-einvoice/prod/database/migrations')
    const application = secrets.find(([, secret]) => secret.Properties.Name === 'dian-einvoice/prod/database/application')
    assert.equal(secrets.length, 2)
    assert.ok(migrations !== undefined && application !== undefined)

    for (const [, secret] of [migrations, application]) {
      assert.equal(secret.DeletionPolicy, 'Retain')
      assert.equal((secret.Properties.GenerateSecretString as { GenerateStringKey: string }).GenerateStringKey, 'password')
    }

    assert.equal((migrations[1].Properties.GenerateSecretString as { SecretStringTemplate: string }).SecretStringTemplate, JSON.stringify({ username: 'dian_migrations', dbname: 'dian_einvoice' }))
    assert.deepEqual((application[1].Properties.GenerateSecretString as { SecretStringTemplate: unknown }).SecretStringTemplate, {
      'Fn::Join': ['', ['{"username":"dian_app","dbname":"dian_einvoice","masterarn":"', { Ref: migrations[0] }, '"}']],
    })
    assert.match(JSON.stringify(cluster.Properties.MasterUsername), new RegExp(`resolve:secretsmanager:.*${migrations[0]}.*:SecretString:username`))

    // Both are attached to the cluster, which adds its address, port and engine for the rotation functions.
    for (const [id] of [migrations, application]) {
      body.hasResourceProperties('AWS::SecretsManager::SecretTargetAttachment', { SecretId: { Ref: id }, TargetId: { Ref: clusterId }, TargetType: 'AWS::RDS::DBCluster' })
    }
  })

  it('rotates both identities with the rotation functions of AWS: the migrations one as a single user, the application one with alternating users', () => {
    const schedules = Object.values(resources).filter((resource) => resource.Type === 'AWS::SecretsManager::RotationSchedule').map((resource) => resource.Properties)
    assert.equal(schedules.length, 2)
    assert.ok(schedules.every((schedule) => JSON.stringify(schedule.RotationRules) === JSON.stringify({ ScheduleExpression: `rate(${settings.rotateAfter.toDays()} days)` })))

    const applications = Object.entries(resources).filter(([, resource]) => resource.Type === 'AWS::Serverless::Application')
    const mappings = (body.toJSON() as { Mappings: Record<string, { aws: { applicationId: string } }> }).Mappings
    const named = applications.map(([id, application]) => {
      const mapping = ((application.Properties.Location as { ApplicationId: { 'Fn::FindInMap': [string] } }).ApplicationId['Fn::FindInMap'])[0]

      return [id, mappings[mapping]?.aws.applicationId.split('/').pop(), application.Properties.Parameters as Record<string, unknown>] as const
    })
    assert.deepEqual(named.map(([, name]) => name).sort(), ['SecretsManagerRDSMySQLRotationMultiUser', 'SecretsManagerRDSMySQLRotationSingleUser'])

    const attachments = Object.entries(resources).filter(([, resource]) => resource.Type === 'AWS::SecretsManager::SecretTargetAttachment')
    const migrationsAttachment = attachments.find(([, attachment]) => JSON.stringify(attachment.Properties.SecretId).includes('Migrations'))?.[0]
    const multiUser = named.find(([, name]) => name === 'SecretsManagerRDSMySQLRotationMultiUser')
    assert.deepEqual(multiUser?.[2].masterSecretArn, { Ref: migrationsAttachment }, 'the superuser of the alternating users is the migrations identity')

    // The first rotation of the application identity waits for its schedule: the user does not exist until
    // the console function provisions it after the first migrations.
    const applicationSchedule = schedules.find((schedule) => JSON.stringify(schedule.SecretId).includes('Application'))
    assert.equal(applicationSchedule?.RotateImmediatelyOnUpdate, false)

    // The rotation functions run in the private subnets, in their own security group.
    const [rotationGroup] = Object.entries(resources).find(([, resource]) => resource.Type === 'AWS::EC2::SecurityGroup' && resource.Properties.GroupName === 'dian-einvoice-prod-database-rotation') ?? []
    const privateSubnets = Object.entries(networkBody.Resources)
      .filter(([, resource]) => resource.Type === 'AWS::EC2::Subnet' && (resource.Properties.Tags as { Key: string, Value: string }[]).some((tag) => tag.Key === 'aws-cdk:subnet-name' && tag.Value === 'private'))
      .map(([id]) => id)

    for (const [, , parameters] of named) {
      assert.deepEqual(parameters.vpcSecurityGroupIds, { 'Fn::GetAtt': [rotationGroup, 'GroupId'] })
      const subnets = (parameters.vpcSubnetIds as { 'Fn::Join': [string, unknown[]] })['Fn::Join'][1].filter((part) => part !== ',').map(inNetwork)
      assert.deepEqual(subnets.sort(), privateSubnets.sort())
    }
  })

  it('publishes its address, port and name and the secrets of both identities with the names the functions read', () => {
    const parameters = Object.values(resources).filter((resource) => resource.Type === 'AWS::SSM::Parameter').map((resource) => String(resource.Properties.Name)).sort()
    assert.deepEqual(parameters, Object.values(DATABASE_PARAMETERS).map((name) => `/dian-einvoice/prod/${name}`).sort())
    body.hasResourceProperties('AWS::SSM::Parameter', { Name: '/dian-einvoice/prod/database/host', Value: { 'Fn::GetAtt': [clusterId, 'Endpoint.Address'] } })
    body.hasResourceProperties('AWS::SSM::Parameter', { Name: '/dian-einvoice/prod/database/port', Value: String(settings.port) })
  })
})

describe('database identities of the stage local', () => {
  const local = stageOf('local')
  const security = template(local.security)

  it('are two secrets in the stack security, for the MySQL of compose, without rotation functions', () => {
    for (const [name, username] of [['migrations', 'dian_cloud_migrations'], ['application', 'dian_cloud_app']] as const) {
      const secrets = Object.values(security.findResources('AWS::SecretsManager::Secret', { Properties: { Name: `dian-einvoice/local/database/${name}` } })) as { DeletionPolicy: string, Properties: { GenerateSecretString: { GenerateStringKey: string, SecretStringTemplate: unknown } } }[]
      assert.equal(secrets.length, 1, `the secret of the ${name} identity`)
      assert.equal(secrets[0]?.DeletionPolicy, 'Delete')
      assert.equal(secrets[0]?.Properties.GenerateSecretString.GenerateStringKey, 'password')
      assert.ok(JSON.stringify(secrets[0]?.Properties.GenerateSecretString.SecretStringTemplate).includes(`\\"username\\":\\"${username}\\",\\"dbname\\":\\"dian_einvoice_cloud\\"`), `${name}: ${username} on dian_einvoice_cloud`)
    }

    // The emulator cannot run the rotation functions of AWS: make cloud-check rotates the application
    // identity the way they would (docs/infrastructure.md), with no superuser named in the secret.
    security.resourceCountIs('AWS::SecretsManager::RotationSchedule', 0)
    assert.ok(!JSON.stringify(security.toJSON()).includes('masterarn'))

    for (const name of [DATABASE_PARAMETERS.secretArn, DATABASE_PARAMETERS.migrationsSecretArn]) {
      security.hasResourceProperties('AWS::SSM::Parameter', { Name: `/dian-einvoice/local/${name}` })
    }
  })
})
