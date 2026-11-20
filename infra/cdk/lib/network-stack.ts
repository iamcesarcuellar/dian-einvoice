// Network (prod only): the private network of the functions and of the managed database. A VPC across the
// zones of the stage with three tiers of subnets: public (the NAT gateways only, the way out to the
// internet), private with egress (the functions, the rotation functions of the secrets and, when the stage
// turns them on, RDS Proxy and the interface endpoints) and isolated (the database, without any route to the
// internet). Gateway endpoints for the AWS services that offer one (S3, DynamoDB); interface endpoints for the
// other services of the adapters as an option, which otherwise go through the NAT gateway. The security group
// of the functions (out: HTTPS to the internet; the stack database adds the port of the database toward it)
// and of the interface endpoints, the default group of the VPC without rules, and flow logs. The groups of the
// database, of its rotation functions and of RDS Proxy belong to the stack database, which admits the
// functions. The reasons of each choice are in docs/infrastructure.md; the emulator cannot give evidence for
// any of it, so this stack is validated offline and never deployed.
import { CfnResource, Stack, type StackProps, Validations } from 'aws-cdk-lib'
import {
  CfnEIP, CfnSubnet, FlowLogDestination, FlowLogTrafficType, GatewayVpcEndpointAwsService, InterfaceVpcEndpointAwsService, IpAddresses, NatProvider,
  Peer, Port, SecurityGroup, SubnetType, Vpc,
} from 'aws-cdk-lib/aws-ec2'
import { Role, ServicePrincipal } from 'aws-cdk-lib/aws-iam'
import { LogGroup } from 'aws-cdk-lib/aws-logs'
import type { Construct } from 'constructs'
import { GATEWAY_SERVICES } from './aws-services.ts'
import { Published } from './published.ts'
import { PROJECT, removalPolicy, resourceName, type NetworkSettings, type StageConfig } from './stage.ts'

export interface NetworkStackProps extends StackProps {
  readonly stage: StageConfig
  readonly network: NetworkSettings
  // The AWS services of the adapters (backend/resources/aws/services.json).
  readonly services: Readonly<Record<string, string>>
}

/** The tiers of subnets, by the name CDK gives their subnets (public1, private1, isolated1...). */
export const SUBNET_TIERS = { public: SubnetType.PUBLIC, private: SubnetType.PRIVATE_WITH_EGRESS, isolated: SubnetType.PRIVATE_ISOLATED } as const

export class NetworkStack extends Stack {
  readonly vpc: Vpc
  // The functions of the serverless service, which the database admits.
  readonly functions: SecurityGroup
  // The interface endpoints, when the stage turns them on.
  readonly endpoints: SecurityGroup | null
  private readonly zones: readonly string[]

  constructor(scope: Construct, id: string, props: NetworkStackProps) {
    super(scope, id, props)
    const { stage, network, services } = props
    const published = new Published(this, stage, 'network')
    const zones = network.availabilityZones
    this.zones = zones

    if (zones.length < 2 || network.natGateways < 1 || network.natGateways > zones.length) {
      throw new Error(`${stage.name}: the network needs two zones or more and between 1 and ${zones.length} NAT gateways.`)
    }

    // The fixed public addresses of the way out: the DIAN and the webhook endpoints see these and no other.
    // They are kept when the stack goes, so the allow list of a customer outlives it.
    const addresses = Array.from({ length: network.natGateways }, (_, index) => {
      const address = new CfnEIP(this, `EgressAddress${index + 1}`, {
        domain: 'vpc',
        tags: [{ key: 'Name', value: resourceName(stage, `egress-${index + 1}`) }],
      })
      address.applyRemovalPolicy(removalPolicy(stage))

      return address
    })

    this.vpc = new Vpc(this, 'Vpc', {
      vpcName: resourceName(stage, 'network'),
      ipAddresses: IpAddresses.cidr(network.cidr),
      availabilityZones: [...zones],
      natGateways: network.natGateways,
      natGatewayProvider: NatProvider.gateway({ eipAllocationIds: addresses.map((address) => address.attrAllocationId) }),
      natGatewaySubnets: { subnetType: SubnetType.PUBLIC },
      subnetConfiguration: [
        // Nothing but the NAT gateways lives here, so nothing gets a public address of its own.
        { name: 'public', subnetType: SUBNET_TIERS.public, cidrMask: 24, mapPublicIpOnLaunch: false },
        // Room for the network interfaces of Lambda, of the endpoints and of the proxy as they grow.
        { name: 'private', subnetType: SUBNET_TIERS.private, cidrMask: 22 },
        { name: 'isolated', subnetType: SUBNET_TIERS.isolated, cidrMask: 24 },
      ],
      // The private names of the interface endpoints and of the database resolve inside the VPC.
      enableDnsHostnames: true,
      enableDnsSupport: true,
      // The default security group of the VPC keeps no rule, so nothing put in it by mistake talks to anything.
      restrictDefaultSecurityGroup: true,
    })

    // cfn-lint and the schema validation of CDK ask not to name zones (W3010): they are settings of the stage,
    // which the subnets need, and which keep the reader of the database in the other zone than its writer.
    for (const subnet of [...this.vpc.publicSubnets, ...this.vpc.privateSubnets, ...this.vpc.isolatedSubnets]) {
      const resource = subnet.node.defaultChild as CfnSubnet
      resource.addMetadata('cfn-lint', { config: { ignore_checks: ['W3010'] } })
      Validations.of(resource).acknowledge({ id: 'CloudFormation-Validate::W3010', reason: 'The zones are settings of the stage, which a subnet needs by name; the zone of each subnet keeps the reader of the database in the other zone than its writer.' })
    }

    // The function of the custom resource that empties the default group, written by CDK, states a DependsOn
    // on its role that its GetAtt already implies (W3005): harmless, and not ours to change.
    for (const handler of this.node.findAll().filter((child): child is CfnResource => child instanceof CfnResource && child.cfnResourceType === 'AWS::Lambda::Function')) {
      handler.addMetadata('cfn-lint', { config: { ignore_checks: ['W3005'] } })
    }

    // The services that have a gateway endpoint reach AWS over the route tables of the private subnets, at no
    // cost and without the NAT gateway; the isolated subnets get none.
    for (const service of GATEWAY_SERVICES.filter((name) => name in services)) {
      this.vpc.addGatewayEndpoint(`${pascal(service)}Endpoint`, {
        service: new GatewayVpcEndpointAwsService(service),
        subnets: [{ subnetType: SUBNET_TIERS.private }],
      })
    }

    // Every accepted and rejected flow of every network interface, for investigating an incident.
    const flowLogs = new LogGroup(this, 'FlowLogs', {
      logGroupName: `/${PROJECT}/${stage.name}/vpc-flow-logs`,
      retention: network.flowLogsRetention,
      removalPolicy: removalPolicy(stage),
    })
    const flowLogsRole = new Role(this, 'FlowLogsRole', {
      roleName: resourceName(stage, 'vpc-flow-logs'),
      description: `Role the flow logs of the VPC of dian-einvoice (${stage.name}) write their log group with`,
      assumedBy: new ServicePrincipal('vpc-flow-logs.amazonaws.com', { conditions: { StringEquals: { 'aws:SourceAccount': this.account } } }),
    })
    this.vpc.addFlowLog('FlowLog', {
      destination: FlowLogDestination.toCloudWatchLogs(flowLogs, flowLogsRole),
      trafficType: FlowLogTrafficType.ALL,
    })

    // The functions call the DIAN, the webhook endpoints of the customers (HTTPS on port 443 only, in
    // production) and the AWS APIs: all of it HTTPS. Nothing comes in: Lambda invokes them from outside the VPC.
    this.functions = new SecurityGroup(this, 'FunctionsSecurityGroup', {
      vpc: this.vpc,
      securityGroupName: resourceName(stage, 'functions'),
      description: 'The functions: nothing comes in; out, HTTPS to the internet and the port of the database',
      allowAllOutbound: false,
    })
    this.functions.addEgressRule(Peer.anyIpv4(), Port.tcp(443), 'HTTPS to the internet through the NAT gateway: the DIAN, webhook endpoints and the AWS APIs')

    // Interface endpoints, as an option: the other AWS services of the adapters through PrivateLink instead of
    // the NAT gateway (their reasons and their cost in docs/infrastructure.md). The stack database lets its
    // rotation functions and RDS Proxy in too.
    if (network.interfaceEndpoints) {
      this.endpoints = new SecurityGroup(this, 'EndpointsSecurityGroup', {
        vpc: this.vpc,
        securityGroupName: resourceName(stage, 'endpoints'),
        description: 'The interface endpoints of the AWS services: HTTPS comes in from the functions, the rotation functions and RDS Proxy',
        allowAllOutbound: false,
      })
      this.endpoints.addIngressRule(this.functions, Port.tcp(443), 'The functions')

      for (const service of Object.keys(services).filter((name) => !(GATEWAY_SERVICES as readonly string[]).includes(name))) {
        this.vpc.addInterfaceEndpoint(`${pascal(service)}Endpoint`, {
          service: new InterfaceVpcEndpointAwsService(service),
          subnets: { subnetType: SUBNET_TIERS.private },
          securityGroups: [this.endpoints],
          privateDnsEnabled: true,
          // Only the groups that call the services come in, not the whole address range of the VPC.
          open: false,
        })
      }
    } else {
      this.endpoints = null
    }

    published.add('PrivateSubnetIds', 'private-subnet-ids', this.vpc.selectSubnets({ subnetType: SUBNET_TIERS.private }).subnetIds.join(','), 'The private subnets of the functions, separated by commas')
    published.add('FunctionsSecurityGroupId', 'functions-security-group-id', this.functions.securityGroupId, 'Security group of the functions, which the database admits')
    published.add('EgressAddresses', 'egress-addresses', addresses.map((address) => address.ref).join(','), 'The fixed public addresses of the way out to the internet (NAT gateways), for the allow lists of the DIAN and of webhook endpoints')
  }

  /**
   * The zones of the stage, as settings: CDK would otherwise look the zones of the account up, which needs
   * credentials, and synthesis must need nothing but the code.
   */
  override get availabilityZones(): string[] {
    return [...this.zones]
  }
}

/** secretsmanager → Secretsmanager, dynamodb → Dynamodb: logical ids. */
function pascal(name: string): string {
  return name.split('-').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('')
}
