// Database (prod only): Aurora MySQL Serverless v2, compatible with MySQL 8.4 as the MySQL of compose, in the
// isolated subnets of the network and never public. Encrypted with a KMS key of its own that rotates, protected
// against deletion and kept when the stack goes, with point-in-time backups, a maintenance window, its error,
// slow query and audit logs in CloudWatch Logs, and TLS required on every connection. The two identities of
// the application with their secrets, rotated by the rotation functions of AWS: the migrations identity, the
// master user of the cluster, as a single user; the application identity with alternating users, so the
// password a warm function holds keeps working until the rotation after next. RDS Proxy as an option. The
// reasons of each choice are in docs/infrastructure.md; like the network, this stack is validated offline and
// never deployed.
//
// The security groups of the database, of its rotation functions and of RDS Proxy live here, and so do the
// rules that let the functions of the stack network in: CDK opens the port of the cluster with a value of
// this stack, which the network, deployed first, cannot read.
import { Duration, Stack, type StackProps, Validations } from 'aws-cdk-lib'
import { Connections, Peer, Port, SecurityGroup, SubnetType } from 'aws-cdk-lib/aws-ec2'
import { Key } from 'aws-cdk-lib/aws-kms'
import { LogGroup } from 'aws-cdk-lib/aws-logs'
import {
  AuroraMysqlEngineVersion, CaCertificate, CfnDBInstance, ClientPasswordAuthType, ClusterInstance, Credentials, DatabaseCluster, DatabaseClusterEngine, type DatabaseProxy,
  DBClusterStorageType, NetworkType, ParameterGroup, type ProvisionedClusterInstanceProps,
} from 'aws-cdk-lib/aws-rds'
import { SecretRotation, SecretRotationApplication } from 'aws-cdk-lib/aws-secretsmanager'
import type { Construct } from 'constructs'
import { DatabaseIdentities, PASSWORD_EXCLUDED } from './database-identities.ts'
import type { NetworkStack } from './network-stack.ts'
import { Published } from './published.ts'
import { removalPolicy, resourceName, type ManagedDatabaseSettings, type StageConfig } from './stage.ts'

export interface DatabaseStackProps extends StackProps {
  readonly stage: StageConfig
  readonly settings: ManagedDatabaseSettings
  readonly network: NetworkStack
}

/**
 * The default max_connections of Aurora MySQL Serverless v2 by maximum capacity in ACUs, as the AWS
 * documentation lists it (Performance and scaling for Aurora Serverless v2): static, and computed from the
 * maximum capacity, not from the current one, so a scaled-down instance never drops connections.
 */
export const AURORA_MYSQL_MAX_CONNECTIONS: readonly (readonly [number, number])[] = [
  [1, 90], [4, 135], [8, 1000], [16, 2000], [32, 3000], [64, 4000], [128, 5000], [192, 6000], [256, 6000],
]

/** The connections a maximum capacity gives: those of the largest documented maximum at or below it. */
export function maxConnections(maxCapacity: number): number {
  return AURORA_MYSQL_MAX_CONNECTIONS.filter(([capacity]) => capacity <= maxCapacity).at(-1)?.[1] ?? 0
}

/**
 * Connections kept for others than the functions: the two rotation functions, which open a few each while
 * they rotate, and operators and maintenance. A test holds maxConnections(maximum) at or above the
 * connections of the functions plus this margin (test/database.test.ts).
 */
export const CONNECTION_MARGIN = 20

/** The logs of the cluster exported to CloudWatch Logs. */
export const DATABASE_LOGS = ['error', 'slowquery', 'audit'] as const

/** The major version of the engine, which names its family of parameter groups (aurora-mysql8.4). */
export const ENGINE_MAJOR_VERSION = '8.4'

export class DatabaseStack extends Stack {
  readonly cluster: DatabaseCluster
  readonly identities: DatabaseIdentities
  readonly proxy: DatabaseProxy | null
  // Only the functions (or RDS Proxy) and the rotation functions come in; nothing goes out.
  readonly securityGroup: SecurityGroup
  // The rotation functions of AWS that change the passwords of the identities.
  readonly rotationSecurityGroup: SecurityGroup
  readonly proxySecurityGroup: SecurityGroup | null

  constructor(scope: Construct, id: string, props: DatabaseStackProps) {
    super(scope, id, props)
    const { stage, settings, network } = props
    const published = new Published(this, stage, 'database')
    const clusterIdentifier = resourceName(stage, 'database')
    const zones = network.vpc.availabilityZones

    if (settings.minCapacity !== 0 && settings.minCapacity < 0.5) {
      throw new Error(`${stage.name}: the minimum capacity of the database is 0 (it pauses) or at least 0.5 ACU.`)
    }

    if (settings.maxCapacity < 1 || settings.maxCapacity < settings.minCapacity) {
      throw new Error(`${stage.name}: the maximum capacity of the database is at least 1 ACU and not below the minimum.`)
    }

    const key = new Key(this, 'Key', {
      alias: `alias/${resourceName(stage, 'database')}`,
      description: `Encrypts the database of dian-einvoice (${stage.name}): storage, backups and snapshots`,
      enableKeyRotation: true,
      removalPolicy: removalPolicy(stage),
    })

    this.identities = new DatabaseIdentities(this, 'Identities', stage)

    // The log groups the cluster exports to, created here with their retention instead of by RDS, which would
    // keep them forever.
    const logGroups = DATABASE_LOGS.map((log) => new LogGroup(this, `${log.charAt(0).toUpperCase()}${log.slice(1)}Log`, {
      logGroupName: `/aws/rds/cluster/${clusterIdentifier}/${log}`,
      retention: settings.logRetention,
      removalPolicy: removalPolicy(stage),
    }))

    // Security groups that allow nothing they are not told to: no rule out by default.
    const group = (groupId: string, name: string, description: string) => new SecurityGroup(this, groupId, {
      vpc: network.vpc,
      securityGroupName: resourceName(stage, name),
      description,
      allowAllOutbound: false,
    })
    const https = Port.tcp(443)

    this.securityGroup = group('SecurityGroup', 'database', 'The database: only the functions (or RDS Proxy) and the rotation functions come in; nothing goes out')
    this.rotationSecurityGroup = group('RotationSecurityGroup', 'database-rotation', 'The rotation functions of the database secrets: nothing comes in; out, HTTPS to Secrets Manager and the port of the database')
    this.rotationSecurityGroup.addEgressRule(Peer.anyIpv4(), https, 'HTTPS to the Secrets Manager API, through the NAT gateway or its endpoint')
    network.endpoints?.addIngressRule(this.rotationSecurityGroup, https, 'The rotation functions of the database secrets', true)

    const engine = DatabaseClusterEngine.auroraMysql({ version: AuroraMysqlEngineVersion.of(settings.engineVersion, ENGINE_MAJOR_VERSION) })

    const parameters = new ParameterGroup(this, 'ClusterParameters', {
      engine,
      description: `Cluster parameters of the database of dian-einvoice (${stage.name})`,
      parameters: {
        // TLS 1.2 or 1.3 on every connection: the default of Aurora MySQL 8.4, written down.
        require_secure_transport: 'ON',
        tls_version: 'TLSv1.2,TLSv1.3',
        // Queries slower than a second.
        slow_query_log: '1',
        long_query_time: '1',
        log_output: 'FILE',
        // Connections (failed ones included), privilege changes and schema changes, not every query; the
        // health checks of Aurora left out.
        server_audit_logging: '1',
        server_audit_events: 'CONNECT,QUERY_DCL,QUERY_DDL',
        server_audit_excl_users: 'rdsadmin',
      },
    })

    // Minor upgrades are a change of engineVersion in the settings of the stage (lib/stage.ts), reviewed and
    // applied by CloudFormation, so the template and the cluster never disagree; the server certificate is
    // signed by the RDS CA the image of the functions trusts (its global bundle).
    const instance = (name: string, zone: string | undefined): ProvisionedClusterInstanceProps => ({
      instanceIdentifier: `${clusterIdentifier}-${name}`,
      publiclyAccessible: false,
      autoMinorVersionUpgrade: false,
      allowMajorVersionUpgrade: false,
      caCertificate: CaCertificate.RDS_CA_RSA2048_G1,
      preferredMaintenanceWindow: settings.maintenanceWindow,
      ...(zone === undefined ? {} : { availabilityZone: zone }),
    })

    this.cluster = new DatabaseCluster(this, 'Cluster', {
      clusterIdentifier,
      engine,
      credentials: Credentials.fromSecret(this.identities.migrations),
      defaultDatabaseName: stage.database.name,
      port: settings.port,
      vpc: network.vpc,
      vpcSubnets: { subnetType: SubnetType.PRIVATE_ISOLATED },
      securityGroups: [this.securityGroup],
      networkType: NetworkType.IPV4,
      writer: ClusterInstance.serverlessV2('Writer', instance('writer', zones[0])),
      // A reader in the second zone, promoted on a failover; tier 1 makes it scale with the writer, so it
      // takes over at the same capacity. The application does not read from it.
      readers: settings.reader ? [ClusterInstance.serverlessV2('Reader', { ...instance('reader', zones[1]), scaleWithWriter: true })] : [],
      serverlessV2MinCapacity: settings.minCapacity,
      serverlessV2MaxCapacity: settings.maxCapacity,
      ...(settings.minCapacity === 0 ? { serverlessV2AutoPauseDuration: Duration.minutes(5) } : {}),
      storageType: DBClusterStorageType.AURORA,
      storageEncrypted: true,
      storageEncryptionKey: key,
      deletionProtection: true,
      removalPolicy: removalPolicy(stage),
      backup: { retention: settings.backupRetention, preferredWindow: settings.backupWindow },
      preferredMaintenanceWindow: settings.maintenanceWindow,
      cloudwatchLogsExports: [...DATABASE_LOGS],
      parameterGroup: parameters,
      iamAuthentication: false,
      copyTagsToSnapshot: true,
    })

    for (const logGroup of logGroups) {
      this.cluster.node.addDependency(logGroup)
    }

    // The zones are settings of the stage, and naming them is the only way to keep the reader in the other
    // zone than the writer (the subnets name them too).
    const zoneReason = 'The zones are settings of the stage (the network names them for its subnets), and naming the zone of each instance is the only way to keep the reader in the other zone than the writer.'

    for (const instance of this.cluster.node.findAll().filter((child): child is CfnDBInstance => child instanceof CfnDBInstance)) {
      Validations.of(instance).acknowledge({ id: 'CloudFormation-Validate::W3010', reason: zoneReason })
      instance.addMetadata('cfn-lint', { config: { ignore_checks: ['W3010'] } })
    }

    Validations.of(this.cluster).acknowledge(
      {
        id: 'AwsSolutions::AwsSolutions-RDS6',
        reason: 'The identities authenticate with passwords in Secrets Manager, rotated by the rotation functions of AWS (alternating users for the application). IAM authentication would replace them, not add to them (an Aurora MySQL user set up for IAM cannot use a password), would need a token for every connection the functions open (one per request), and AWS asks 300 to 1000 MiB of extra memory on the instance for it, most of the 1 GiB of the minimum capacity.',
      },
      {
        id: 'AwsSolutions::AwsSolutions-RDS11',
        reason: 'The port is not a secret: the functions read it from their environment, and the database is reachable only from the security groups of the functions, RDS Proxy and the rotation functions, in isolated subnets without a route to the internet. A non-default port would hide nothing from what can reach it and would break the parity with the MySQL of compose.',
      },
      {
        id: 'AwsSolutions::AwsSolutions-RDS14',
        reason: 'Backtrack rewinds the whole cluster, every company at once: undoing the mistake of one company would also undo the invoices every other company issued since, which the DIAN has already accepted. Recovery is a point-in-time restore into a new cluster (35 days of backups), from which the rows affected are copied back.',
      },
    )

    // Who reaches the port of the database, written with the port of the stage (CDK would write the port the
    // cluster reports, and descriptions of its own): the functions, directly or through RDS Proxy, and the
    // rotation functions. Nothing else, and nothing goes out.
    const port = Port.tcp(settings.port)
    const database = new Connections({ securityGroups: [this.securityGroup], defaultPort: port })
    database.allowFrom(this.rotationSecurityGroup, port, 'The rotation functions of the database secrets')

    // The rotation functions of AWS (applications of the Serverless Application Repository), in the private
    // subnets: they reach the database, and the Secrets Manager API through the NAT gateway or its endpoint.
    // The migrations identity is the master user and rotates as a single user: only the console function uses
    // it, which reads its secret again and retries once when the database refuses the old password.
    const rotation = {
      target: { connections: database },
      vpc: network.vpc,
      vpcSubnets: { subnetType: SubnetType.PRIVATE_WITH_EGRESS },
      securityGroup: this.rotationSecurityGroup,
      automaticallyAfter: settings.rotateAfter,
      excludeCharacters: PASSWORD_EXCLUDED,
    }
    const migrations = this.cluster.secret

    if (migrations === undefined) {
      throw new Error(`${stage.name}: the cluster has no secret for its master user.`)
    }

    new SecretRotation(this, 'MigrationsRotation', { application: SecretRotationApplication.MYSQL_ROTATION_SINGLE_USER, secret: migrations, ...rotation })

    // The application identity rotates with alternating users: each rotation sets a new password on the user
    // that is not current and makes it current, so the previous credentials keep working until the next one,
    // far longer than an execution environment lives. Its first rotation waits for the schedule: the user does
    // not exist until the console function provisions it after the first migrations. RDS Proxy does not
    // support alternating users, so behind it the application identity rotates as a single user too, and the
    // connector of the functions reads the secret again when the proxy refuses the old password.
    const application = this.identities.application.attach(this.cluster)
    new SecretRotation(this, 'ApplicationRotation', {
      ...(settings.proxy
        ? { application: SecretRotationApplication.MYSQL_ROTATION_SINGLE_USER }
        : { application: SecretRotationApplication.MYSQL_ROTATION_MULTI_USER, masterSecret: migrations }),
      secret: application,
      rotateImmediatelyOnUpdate: false,
      ...rotation,
    })

    // RDS Proxy, as an option: it keeps the connections of the functions open and shares them
    // (docs/infrastructure.md). CDK lets it into the database itself.
    if (settings.proxy) {
      this.proxySecurityGroup = group('ProxySecurityGroup', 'database-proxy', 'RDS Proxy: the functions come in; out, the port of the database and HTTPS to Secrets Manager')
      this.proxySecurityGroup.addEgressRule(Peer.anyIpv4(), https, 'HTTPS to the Secrets Manager API, for the credentials of the identities')
      network.endpoints?.addIngressRule(this.proxySecurityGroup, https, 'RDS Proxy', true)

      this.proxy = this.cluster.addProxy('Proxy', {
        dbProxyName: resourceName(stage, 'database'),
        secrets: [migrations, application],
        vpc: network.vpc,
        vpcSubnets: { subnetType: SubnetType.PRIVATE_WITH_EGRESS },
        securityGroups: [this.proxySecurityGroup],
        requireTLS: true,
        iamAuth: false,
        // The plugin of the accounts of MySQL 8.4, which the proxy accepts over TLS only.
        clientPasswordAuthType: ClientPasswordAuthType.MYSQL_CACHING_SHA2_PASSWORD,
        // The rest stays for the rotation functions and the operators, which connect to the database itself.
        maxConnectionsPercent: 80,
        maxIdleConnectionsPercent: 50,
        debugLogging: false,
      })
      this.proxy.connections.allowFrom(network.functions, port, 'The functions')
    } else {
      this.proxySecurityGroup = null
      this.proxy = null
      database.allowFrom(network.functions, port, 'The functions')
    }

    published.add('DatabaseHost', 'host', this.proxy?.endpoint ?? this.cluster.clusterEndpoint.hostname, 'Address of the database: its writer, or RDS Proxy in front of it')
    published.add('DatabasePort', 'port', String(settings.port), 'Port of the database')
    published.add('DatabaseName', 'name', stage.database.name, 'Database of the application')
  }
}
