// Assertions on the stack network of the prod stage as synthesized (it is never deployed, not even to the
// emulator): the zones and the three tiers of subnets, isolated subnets without any route out, the NAT gateways
// the stage asks for with their fixed addresses, the gateway endpoints, a path for every AWS service the
// adapters call (backend/resources/aws/services.json) and the table of docs/infrastructure.md that lists them,
// exactly the intended rules in every security group of the stage, the default group without rules, the flow
// logs and the parameters the functions read. Then the same stage with every option turned the other way.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { GATEWAY_SERVICES } from '../lib/aws-services.ts'
import type { NetworkStack } from '../lib/network-stack.ts'
import { STAGES, type NetworkSettings, type StageConfig } from '../lib/stage.ts'
import { securityGroupRules } from './security-groups.ts'
import { services, stageOf, template, validationReport, variantOf } from './support.ts'

type Resources = Record<string, { Type: string, Properties: Record<string, unknown>, DeletionPolicy?: string }>

type Tier = 'public' | 'private' | 'isolated'

interface Subnet {
  readonly id: string
  readonly zone: string
  readonly routeTable: string
}

const prod = STAGES.prod
const prodNetwork = prod.network as NetworkSettings
const nonGateway = Object.keys(services).filter((service) => !(GATEWAY_SERVICES as readonly string[]).includes(service))

function resourcesOf(stack: NetworkStack): Resources {
  return (template(stack).toJSON() as { Resources: Resources }).Resources
}

function ofType(resources: Resources, type: string): [string, Resources[string]][] {
  return Object.entries(resources).filter(([, resource]) => resource.Type === type)
}

function ref(value: unknown): string {
  return String((value as { Ref?: string }).Ref ?? (value as { 'Fn::GetAtt'?: [string] })['Fn::GetAtt']?.[0])
}

/** The subnets of each tier, with their zone and route table, by the tags CDK gives them. */
function tiers(resources: Resources): Record<Tier, Subnet[]> {
  const associations = ofType(resources, 'AWS::EC2::SubnetRouteTableAssociation').map(([, association]) => association.Properties)
  const found: Record<Tier, Subnet[]> = { public: [], private: [], isolated: [] }

  for (const [id, subnet] of ofType(resources, 'AWS::EC2::Subnet')) {
    const tags = subnet.Properties.Tags as { Key: string, Value: string }[]
    const tier = tags.find((tag) => tag.Key === 'aws-cdk:subnet-name')?.Value as Tier
    const routeTable = associations.find((association) => ref(association.SubnetId) === id)?.RouteTableId
    found[tier].push({ id, zone: String(subnet.Properties.AvailabilityZone), routeTable: ref(routeTable) })
  }

  return found
}

/** The routes of a route table, as "<destination> → <type of target>". */
function routesOf(resources: Resources, routeTable: string): string[] {
  return ofType(resources, 'AWS::EC2::Route')
    .filter(([, route]) => ref(route.Properties.RouteTableId) === routeTable)
    .map(([, route]) => `${String(route.Properties.DestinationCidrBlock)} → ${route.Properties.GatewayId !== undefined ? 'internet gateway' : route.Properties.NatGatewayId !== undefined ? `nat ${ref(route.Properties.NatGatewayId)}` : 'other'}`)
}

/** Checks the routes and the NAT gateways of a network: one per zone up to the number the stage asks. */
function assertWayOut(resources: Resources, settings: NetworkSettings): void {
  const subnets = tiers(resources)
  const nats = ofType(resources, 'AWS::EC2::NatGateway')
  const addresses = ofType(resources, 'AWS::EC2::EIP')
  assert.equal(nats.length, settings.natGateways)
  assert.equal(addresses.length, settings.natGateways)

  for (const [, nat] of nats) {
    assert.ok(addresses.some(([id]) => ref(nat.Properties.AllocationId) === id), 'each NAT gateway has a fixed address of the stack')
    assert.ok(subnets.public.some((subnet) => subnet.id === ref(nat.Properties.SubnetId)), 'NAT gateways live in the public subnets')
  }

  for (const subnet of subnets.public) {
    assert.deepEqual(routesOf(resources, subnet.routeTable), ['0.0.0.0/0 → internet gateway'])
  }

  // Each private subnet leaves through the NAT gateway of its zone, or the only one.
  for (const subnet of subnets.private) {
    const routes = routesOf(resources, subnet.routeTable)
    assert.equal(routes.length, 1)
    const nat = nats.find(([id]) => routes[0] === `0.0.0.0/0 → nat ${id}`)
    assert.ok(nat !== undefined, `${subnet.id} leaves through a NAT gateway`)

    if (settings.natGateways === settings.availabilityZones.length) {
      assert.equal(subnets.public.find((candidate) => candidate.id === ref(nat[1].Properties.SubnetId))?.zone, subnet.zone, 'one NAT gateway per zone: each private subnet uses that of its zone')
    }
  }

  // The isolated subnets have no route at all beyond the VPC: no internet gateway, no NAT, no endpoint.
  for (const subnet of subnets.isolated) {
    assert.deepEqual(routesOf(resources, subnet.routeTable), [], `${subnet.id} has no route out of the VPC`)
  }

  for (const [, endpoint] of ofType(resources, 'AWS::EC2::VPCEndpoint')) {
    for (const table of (endpoint.Properties.RouteTableIds ?? []) as unknown[]) {
      assert.ok(subnets.private.some((subnet) => subnet.routeTable === ref(table)), 'gateway endpoints serve the private subnets only')
    }
  }
}

/** Checks that every AWS service of the adapters has a path from the private subnets. */
function assertPathToEveryService(resources: Resources, settings: NetworkSettings, functionsRules: readonly string[]): void {
  const subnets = tiers(resources)
  const endpoints = ofType(resources, 'AWS::EC2::VPCEndpoint').map(([, endpoint]) => endpoint.Properties)
  // com.amazonaws.<region>.<service>, written whole or joined with the region.
  const named = (service: string) => endpoints.filter((endpoint) => JSON.stringify(endpoint.ServiceName).includes(`.${service}"`))

  for (const service of Object.keys(services)) {
    if ((GATEWAY_SERVICES as readonly string[]).includes(service)) {
      const [gateway] = named(service)
      assert.equal(gateway?.VpcEndpointType, 'Gateway', `${service}: a gateway endpoint`)
      assert.deepEqual(((gateway?.RouteTableIds ?? []) as unknown[]).map(ref).sort(), subnets.private.map((subnet) => subnet.routeTable).sort(), `${service}: on every private route table`)
    } else {
      // Through the NAT gateway, which the functions reach with HTTPS...
      assert.ok(subnets.private.every((subnet) => routesOf(resources, subnet.routeTable).some((route) => route.startsWith('0.0.0.0/0 → nat '))), `${service}: the private subnets reach the internet`)
      assert.ok(functionsRules.includes('dian-einvoice-prod-functions out tcp/443 0.0.0.0/0'), `${service}: the functions may call HTTPS`)

      // ...or its interface endpoint, when the stage asks for them.
      const interfaces = named(service)
      assert.equal(interfaces.length, settings.interfaceEndpoints ? 1 : 0, `${service}: ${settings.interfaceEndpoints ? 'an' : 'no'} interface endpoint`)

      for (const endpoint of interfaces) {
        assert.equal(endpoint.VpcEndpointType, 'Interface')
        assert.equal(endpoint.PrivateDnsEnabled, true, `${service}: the regional name of the service resolves to the endpoint`)
        assert.deepEqual(((endpoint.SubnetIds ?? []) as unknown[]).map(ref).sort(), subnets.private.map((subnet) => subnet.id).sort())
      }
    }
  }
}

describe('network of the prod stage', () => {
  const stacks = stageOf('prod')
  const network = stacks.network as NetworkStack
  const resources = resourcesOf(network)
  const rules = securityGroupRules([network, ...(stacks.database === null ? [] : [stacks.database])])

  it('spans the zones of the stage, with a public, a private and an isolated subnet in each', () => {
    const subnets = tiers(resources)
    template(network).hasResourceProperties('AWS::EC2::VPC', { CidrBlock: prodNetwork.cidr, EnableDnsHostnames: true, EnableDnsSupport: true })

    for (const tier of ['public', 'private', 'isolated'] as const) {
      assert.deepEqual(subnets[tier].map((subnet) => subnet.zone).sort(), [...prodNetwork.availabilityZones].sort(), `a ${tier} subnet in each zone`)
    }

    // Nothing gets a public address of its own, not even in the public subnets: only the NAT gateways live there.
    assert.ok(ofType(resources, 'AWS::EC2::Subnet').every(([, subnet]) => subnet.Properties.MapPublicIpOnLaunch === false))
  })

  it('leaves through one NAT gateway with a fixed address, and gives the isolated subnets no route out', () => {
    assertWayOut(resources, prodNetwork)

    for (const [, address] of ofType(resources, 'AWS::EC2::EIP')) {
      assert.equal(address.DeletionPolicy, 'Retain', 'the address outlives the stack, and the allow lists that name it')
    }
  })

  it('reaches S3 and DynamoDB through gateway endpoints and every other AWS service of the adapters through the NAT gateway', () => {
    assert.deepEqual(Object.keys(services).sort(), ['dynamodb', 'email', 'kms', 's3', 'secretsmanager', 'sns', 'sqs'])
    assertPathToEveryService(resources, prodNetwork, rules)
    assert.equal(ofType(resources, 'AWS::EC2::VPCEndpoint').length, GATEWAY_SERVICES.length, 'no interface endpoint unless the stage asks for them')
  })

  it('lists in docs/infrastructure.md the path a function takes to every AWS service of the adapters', () => {
    const docs = readFileSync(join(process.env.DOCS ?? join(import.meta.dirname, '../../../docs'), 'infrastructure.md'), 'utf8')
    const section = docs.split('\n### ').find((part) => part.startsWith('How a function in the VPC reaches each AWS service')) ?? ''
    const rows = section.split('\n').filter((line) => line.startsWith('| ') && !line.startsWith('| ---') && !line.startsWith('| Service'))
    assert.equal(rows.length, Object.keys(services).length, 'one row per service, none stale')

    for (const service of Object.keys(services)) {
      const row = rows.find((line) => line.includes(`| \`${service}\` |`))
      assert.ok(row !== undefined, `a row for ${service}`)
      assert.match(row, (GATEWAY_SERVICES as readonly string[]).includes(service) ? /Gateway endpoint/ : /NAT gateway.*interface endpoint/, service)
    }
  })

  it('lets nothing into the functions, and out only HTTPS to the internet and the port of the database to the database', () => {
    assert.deepEqual(rules, [
      'dian-einvoice-prod-database in tcp/3306 dian-einvoice-prod-database-rotation',
      'dian-einvoice-prod-database in tcp/3306 dian-einvoice-prod-functions',
      'dian-einvoice-prod-database out nothing',
      'dian-einvoice-prod-database-rotation out tcp/3306 dian-einvoice-prod-database',
      'dian-einvoice-prod-database-rotation out tcp/443 0.0.0.0/0',
      'dian-einvoice-prod-functions out tcp/3306 dian-einvoice-prod-database',
      'dian-einvoice-prod-functions out tcp/443 0.0.0.0/0',
    ])
  })

  it('keeps the default security group of the VPC without rules', () => {
    const vpc = ofType(resources, 'AWS::EC2::VPC')[0]?.[0]
    template(network).hasResourceProperties('Custom::VpcRestrictDefaultSG', { DefaultSecurityGroupId: { 'Fn::GetAtt': [vpc, 'DefaultSecurityGroup'] } })
  })

  it('logs every flow of the VPC, kept a year, through a role only the flow logs of this account assume', () => {
    const [[logGroup, group] = []] = ofType(resources, 'AWS::Logs::LogGroup')
    assert.equal(group?.Properties.RetentionInDays, 365)
    assert.equal(group?.DeletionPolicy, 'Retain')
    template(network).hasResourceProperties('AWS::EC2::FlowLog', { ResourceType: 'VPC', TrafficType: 'ALL', LogDestinationType: 'cloud-watch-logs', LogGroupName: { Ref: logGroup } })
    template(network).hasResourceProperties('AWS::IAM::Role', {
      RoleName: 'dian-einvoice-prod-vpc-flow-logs',
      AssumeRolePolicyDocument: { Statement: [{ Action: 'sts:AssumeRole', Effect: 'Allow', Principal: { Service: 'vpc-flow-logs.amazonaws.com' }, Condition: { StringEquals: { 'aws:SourceAccount': prod.account } } }] },
    })
  })

  it('publishes the private subnets and the security group of the functions with the names the functions read', () => {
    const parameters = ofType(resources, 'AWS::SSM::Parameter').map(([, parameter]) => String(parameter.Properties.Name)).sort()
    assert.deepEqual(parameters, ['/dian-einvoice/prod/network/egress-addresses', '/dian-einvoice/prod/network/functions-security-group-id', '/dian-einvoice/prod/network/private-subnet-ids'])
  })

  it('does not exist in the local stage, whose functions run on the network of compose', () => {
    assert.equal(stageOf('local').network, null)
    assert.equal(stageOf('local').database, null)
  })
})

describe('the prod stage with every option of the network and the database turned the other way', () => {
  const managed = prod.database.managed

  if (managed === null) {
    throw new Error('prod has a managed database.')
  }

  // One NAT gateway per zone, interface endpoints, RDS Proxy and no reader.
  const config: StageConfig = {
    ...prod,
    network: { ...prodNetwork, natGateways: prodNetwork.availabilityZones.length, interfaceEndpoints: true },
    database: { ...prod.database, managed: { ...managed, reader: false, proxy: true } },
  }
  const { app, stacks } = variantOf(config)
  const assembly = app.synth()
  const network = stacks.network as NetworkStack
  const resources = resourcesOf(network)
  const rules = securityGroupRules([network, ...(stacks.database === null ? [] : [stacks.database])])

  it('passes cdk-nag with the same exceptions as the default settings', () => {
    const nag = validationReport(assembly.directory, 'AwsSolutions')
    assert.equal(nag?.conclusion, 'success')
    assert.deepEqual(nag.violations, [])
    const database = nag.suppressedViolations.filter((violation) => violation.violatingConstructs.some((construct) => construct.constructPath.startsWith('prod/database') || construct.constructPath.startsWith('prod/network')))
    assert.deepEqual(database.map((violation) => violation.ruleName).sort(), ['AwsSolutions-RDS11', 'AwsSolutions-RDS14', 'AwsSolutions-RDS6'])
  })

  it('gives each zone a NAT gateway of its own', () => {
    assertWayOut(resources, config.network as NetworkSettings)
  })

  it('reaches every AWS service of the adapters but S3 and DynamoDB through an interface endpoint', () => {
    assertPathToEveryService(resources, config.network as NetworkSettings, rules)
    assert.equal(ofType(resources, 'AWS::EC2::VPCEndpoint').length, Object.keys(services).length)
    assert.deepEqual(nonGateway.sort(), ['email', 'kms', 'secretsmanager', 'sns', 'sqs'])
  })

  it('lets the functions reach the database only through RDS Proxy, and the endpoints only take HTTPS from those that call them', () => {
    assert.deepEqual(rules, [
      'dian-einvoice-prod-database in tcp/3306 dian-einvoice-prod-database-rotation',
      'dian-einvoice-prod-database in tcp/cluster dian-einvoice-prod-database-proxy',
      'dian-einvoice-prod-database out nothing',
      'dian-einvoice-prod-database-proxy in tcp/3306 dian-einvoice-prod-functions',
      'dian-einvoice-prod-database-proxy out tcp/443 0.0.0.0/0',
      'dian-einvoice-prod-database-proxy out tcp/cluster dian-einvoice-prod-database',
      'dian-einvoice-prod-database-rotation out tcp/3306 dian-einvoice-prod-database',
      'dian-einvoice-prod-database-rotation out tcp/443 0.0.0.0/0',
      'dian-einvoice-prod-endpoints in tcp/443 dian-einvoice-prod-database-proxy',
      'dian-einvoice-prod-endpoints in tcp/443 dian-einvoice-prod-database-rotation',
      'dian-einvoice-prod-endpoints in tcp/443 dian-einvoice-prod-functions',
      'dian-einvoice-prod-endpoints out nothing',
      'dian-einvoice-prod-functions out tcp/3306 dian-einvoice-prod-database-proxy',
      'dian-einvoice-prod-functions out tcp/443 0.0.0.0/0',
    ])
  })

  it('puts RDS Proxy in front of the database, with TLS required, and publishes its address for the functions', () => {
    const database = stacks.database

    assert.ok(database !== null)
    const body = template(database)
    body.resourceCountIs('AWS::RDS::DBInstance', 1)
    body.hasResourceProperties('AWS::RDS::DBProxy', { RequireTLS: true, EngineFamily: 'MYSQL', Auth: [{ AuthScheme: 'SECRETS', IAMAuth: 'DISABLED' }, { AuthScheme: 'SECRETS', IAMAuth: 'DISABLED' }] })
    const proxy = Object.keys(body.findResources('AWS::RDS::DBProxy'))[0]
    body.hasResourceProperties('AWS::SSM::Parameter', { Name: '/dian-einvoice/prod/database/host', Value: { 'Fn::GetAtt': [proxy, 'Endpoint'] } })

    // RDS Proxy does not support alternating users: both identities rotate as a single user behind it.
    const mappings = Object.values((body.toJSON() as { Mappings: Record<string, { aws?: { applicationId?: string } }> }).Mappings)
    const applications = mappings.flatMap((mapping) => mapping.aws?.applicationId === undefined ? [] : [mapping.aws.applicationId.split('/').pop()])
    assert.deepEqual(applications, ['SecretsManagerRDSMySQLRotationSingleUser', 'SecretsManagerRDSMySQLRotationSingleUser'])
  })
})
