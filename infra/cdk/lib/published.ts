// What a stack publishes for the rest of the platform: each name or ARN as an SSM parameter under
// /dian-einvoice/<stage>/<stack>/ (the serverless service of the functions reads them), and as a stack
// output (make cloud-up writes backend/.env.cloud from the outputs of the local stage).
import { CfnOutput } from 'aws-cdk-lib'
import { StringParameter } from 'aws-cdk-lib/aws-ssm'
import type { Construct } from 'constructs'
import { parameterPrefix, type StageConfig } from './stage.ts'

export class Published {
  private readonly scope: Construct
  private readonly prefix: string

  constructor(scope: Construct, stage: StageConfig, stack: string) {
    this.scope = scope
    this.prefix = `${parameterPrefix(stage)}/${stack}`
  }

  /** Publishes one value: id is the output name, such as EventsTopicArn; name the parameter, such as events-topic-arn. */
  add(id: string, name: string, value: string, description: string): void {
    new StringParameter(this.scope, `${id}Parameter`, {
      parameterName: `${this.prefix}/${name}`,
      stringValue: value,
      description,
    })

    new CfnOutput(this.scope, id, { value, description })
  }
}
