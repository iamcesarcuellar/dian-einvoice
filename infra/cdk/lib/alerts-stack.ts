// Alerts (Infrastructure task 5): where the alarms of the platform go, deployed before the functions, since the
// notifier of the serverless service subscribes to its topic. Every alarm publishes to a topic encrypted with a KMS
// key of its own (CloudWatch cannot publish to a topic encrypted with the AWS managed key of SNS); the notifier
// turns each change of state into a message to a chat webhook compatible with Slack, whose address is in a secret;
// a notification the notifier cannot deliver goes to a dead-letter queue of its own; and the alarm on that queue
// also publishes to a fallback topic with an email subscription, which does not depend on the notifier.
import { SecretValue, Stack, type StackProps, Validations } from 'aws-cdk-lib'
import { PolicyStatement, ServicePrincipal } from 'aws-cdk-lib/aws-iam'
import { Key } from 'aws-cdk-lib/aws-kms'
import { Secret } from 'aws-cdk-lib/aws-secretsmanager'
import { Subscription, SubscriptionProtocol, Topic } from 'aws-cdk-lib/aws-sns'
import { Queue, QueueEncryption } from 'aws-cdk-lib/aws-sqs'
import type { Construct } from 'constructs'
import { Published } from './published.ts'
import { PROJECT, removalPolicy, resourceName, type StageConfig } from './stage.ts'

export interface AlertsStackProps extends StackProps {
  readonly stage: StageConfig
}

/** The secret of the chat webhook the notifier posts to: {"url": "..."}, empty until the operators set it. */
export function chatWebhookSecretName(stage: StageConfig): string {
  return `${PROJECT}/${stage.name}/alerts/chat-webhook`
}

export class AlertsStack extends Stack {
  readonly key: Key
  readonly alarms: Topic
  readonly fallback: Topic
  readonly notifierDeadLetters: Queue
  readonly chatWebhook: Secret

  constructor(scope: Construct, id: string, props: AlertsStackProps) {
    super(scope, id, props)
    const { stage } = props
    const published = new Published(this, stage, 'alerts')

    // CloudWatch encrypts what it publishes to the topics with this key, from this account only.
    this.key = new Key(this, 'AlarmsKey', {
      alias: `alias/${resourceName(stage, 'alarms')}`,
      description: `Encrypts the topics of the alarms of dian-einvoice (${stage.name})`,
      enableKeyRotation: true,
      removalPolicy: removalPolicy(stage),
    })
    this.key.addToResourcePolicy(new PolicyStatement({
      sid: 'CloudWatchPublishesTheAlarms',
      principals: [new ServicePrincipal('cloudwatch.amazonaws.com')],
      actions: ['kms:Decrypt', 'kms:GenerateDataKey*'],
      resources: ['*'],
      conditions: { StringEquals: { 'aws:SourceAccount': this.account } },
    }))

    const topic = (logicalId: string, name: string, displayName: string): Topic => {
      const created = new Topic(this, logicalId, { topicName: resourceName(stage, name), displayName, masterKey: this.key, enforceSSL: true })
      created.addToResourcePolicy(new PolicyStatement({
        sid: 'AlarmsOfThisAccount',
        principals: [new ServicePrincipal('cloudwatch.amazonaws.com')],
        actions: ['sns:Publish'],
        resources: [created.topicArn],
        conditions: {
          StringEquals: { 'aws:SourceAccount': this.account },
          ArnLike: { 'aws:SourceArn': `arn:${this.partition}:cloudwatch:${this.region}:${this.account}:alarm:*` },
        },
      }))

      return created
    }

    this.alarms = topic('Alarms', 'alarms', `Alarms of dian-einvoice (${stage.name})`)
    this.fallback = topic('AlarmsFallback', 'alarms-fallback', `Failures of the notifier of dian-einvoice (${stage.name})`)

    // The fallback does not depend on the notifier: an email to the operators (a parameter of the deployment).
    if (stage.alerts.fallbackEmail !== '') {
      new Subscription(this, 'AlarmsFallbackEmail', { topic: this.fallback, protocol: SubscriptionProtocol.EMAIL, endpoint: stage.alerts.fallbackEmail })
    }

    // What the notifier could not deliver, kept two weeks, and what SNS could not hand it.
    this.notifierDeadLetters = new Queue(this, 'NotifierDeadLetterQueue', {
      queueName: resourceName(stage, 'notifier-dlq'),
      encryption: QueueEncryption.SQS_MANAGED,
      enforceSSL: true,
      retentionPeriod: stage.queues.deadLetterRetention,
    })
    Validations.of(this.notifierDeadLetters).acknowledge({
      id: 'AwsSolutions::AwsSolutions-SQS3',
      reason: 'This queue is itself a dead-letter queue: the destination of the notifications the notifier could not deliver (its asynchronous invocations) and of those SNS could not hand it (the redrive policy of its subscription). Nothing consumes it but the operators, and its alarm (notifier-failures) emails the fallback address.',
    })
    this.notifierDeadLetters.addToResourcePolicy(new PolicyStatement({
      sid: 'TheTopicOfTheAlarmsSendsWhatItCouldNotDeliver',
      principals: [new ServicePrincipal('sns.amazonaws.com')],
      actions: ['sqs:SendMessage'],
      resources: [this.notifierDeadLetters.queueArn],
      conditions: { ArnEquals: { 'aws:SourceArn': this.alarms.topicArn } },
    }))

    // The address of the chat webhook, which is a credential: whoever holds it posts to the chat. The stage local
    // names its local receiver; prod starts empty, and the notifier only logs until the operators set it.
    this.chatWebhook = new Secret(this, 'ChatWebhook', {
      secretName: chatWebhookSecretName(stage),
      description: `Address of the chat webhook (Slack-compatible) the notifier of dian-einvoice (${stage.name}) posts the alarms to`,
      secretObjectValue: { url: SecretValue.unsafePlainText(stage.alerts.chatWebhookUrl) },
      removalPolicy: removalPolicy(stage),
    })

    Validations.of(this.chatWebhook).acknowledge({
      id: 'AwsSolutions::AwsSolutions-SMG4',
      reason: 'The address of a chat webhook is issued by the chat, which no rotation function of AWS can ask for a new one: whoever owns the webhook replaces it there and puts the new address in the secret (docs/observability.md).',
    })

    published.add('AlarmsTopicArn', 'alarms-topic-arn', this.alarms.topicArn, 'ARN of the topic every alarm publishes to, which the notifier subscribes to')
    published.add('AlarmsFallbackTopicArn', 'alarms-fallback-topic-arn', this.fallback.topicArn, 'ARN of the topic of the failures of the notifier, with an email subscription')
    published.add('NotifierDeadLetterQueueArn', 'notifier-dlq-arn', this.notifierDeadLetters.queueArn, 'ARN of the dead-letter queue of the notifier')
    published.add('NotifierDeadLetterQueueUrl', 'notifier-dlq-url', this.notifierDeadLetters.queueUrl, 'URL of the dead-letter queue of the notifier')
    published.add('ChatWebhookSecretArn', 'chat-webhook-secret-arn', this.chatWebhook.secretArn, 'ARN of the secret of the chat webhook the notifier posts to')
  }
}
