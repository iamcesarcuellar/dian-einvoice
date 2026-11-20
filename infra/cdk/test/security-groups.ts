// The rules of every security group of a stage, wherever CDK wrote them (inline in the group, or as a rule of
// their own in the stack that deploys later), as lines such as
// "dian-einvoice-prod-functions out tcp/443 0.0.0.0/0", so a test can compare them with what is intended.
import type { Stack } from 'aws-cdk-lib'
import { template } from './support.ts'

type Json = Record<string, unknown>

interface Located {
  readonly stackName: string
  readonly body: { Resources: Record<string, { Type: string, Properties?: Json }>, Outputs?: Record<string, { Value: unknown }> }
}

/** The rules of the security groups of these stacks; "<group> out nothing" for a group that may send nothing. */
export function securityGroupRules(stacks: readonly Stack[]): string[] {
  const located: Located[] = stacks.map((stack) => ({ stackName: stack.stackName, body: template(stack).toJSON() as Located['body'] }))
  const rules: string[] = []

  for (const { stackName, body } of located) {
    for (const resource of Object.values(body.Resources)) {
      const properties = resource.Properties ?? {}

      if (resource.Type === 'AWS::EC2::SecurityGroup') {
        const group = String(properties.GroupName)

        for (const rule of (properties.SecurityGroupIngress ?? []) as Json[]) {
          rules.push(render(group, 'in', rule, located, stackName))
        }

        for (const rule of (properties.SecurityGroupEgress ?? []) as Json[]) {
          rules.push(render(group, 'out', rule, located, stackName))
        }
      } else if (resource.Type === 'AWS::EC2::SecurityGroupIngress' || resource.Type === 'AWS::EC2::SecurityGroupEgress') {
        rules.push(render(groupName(properties.GroupId, located, stackName), resource.Type.endsWith('Ingress') ? 'in' : 'out', properties, located, stackName))
      }
    }
  }

  return rules.sort()
}

function render(group: string, direction: 'in' | 'out', rule: Json, located: readonly Located[], stackName: string): string {
  // CDK writes this rule into a group that may send nothing, as a group without rules sends anything.
  if (direction === 'out' && rule.CidrIp === '255.255.255.255/32' && rule.IpProtocol === 'icmp') {
    return `${group} out nothing`
  }

  const cidr = rule.CidrIp ?? rule.CidrIpv6
  const peer = typeof cidr === 'string' ? cidr
    : rule.SourceSecurityGroupId !== undefined ? groupName(rule.SourceSecurityGroupId, located, stackName)
      : rule.DestinationSecurityGroupId !== undefined ? groupName(rule.DestinationSecurityGroupId, located, stackName)
        : JSON.stringify(rule.DestinationPrefixListId ?? rule.SourcePrefixListId)

  return `${group} ${direction} ${String(rule.IpProtocol)}/${port(rule.FromPort)}${port(rule.FromPort) === port(rule.ToPort) ? '' : `-${port(rule.ToPort)}`} ${peer}`
}

/** A port, or "cluster" for the one the cluster reports (CDK opens it so for RDS Proxy). */
function port(value: unknown): string {
  if (typeof value === 'number') {
    return String(value)
  }

  return JSON.stringify(value).includes('Endpoint.Port') ? 'cluster' : JSON.stringify(value)
}

/** The name of the group a reference points at: in the same stack, or an output of another one. */
function groupName(reference: unknown, located: readonly Located[], stackName: string): string {
  const json = reference as Json
  const output = json['Fn::GetStackOutput'] as { StackName: string, OutputName: string } | undefined

  if (output !== undefined) {
    const exporter = located.find((candidate) => candidate.stackName === output.StackName)
    const value = exporter?.body.Outputs?.[output.OutputName]?.Value

    if (exporter === undefined || value === undefined) {
      throw new Error(`No output ${output.OutputName} of ${output.StackName}.`)
    }

    return groupName(value, located, exporter.stackName)
  }

  const attribute = json['Fn::GetAtt'] as [string, string] | undefined
  const id = attribute?.[0] ?? (json.Ref as string | undefined)
  const group = located.find((candidate) => candidate.stackName === stackName)?.body.Resources[id ?? '']

  if (group?.Type !== 'AWS::EC2::SecurityGroup') {
    throw new Error(`${JSON.stringify(reference)} is not a security group of ${stackName}.`)
  }

  return String(group.Properties?.GroupName)
}
