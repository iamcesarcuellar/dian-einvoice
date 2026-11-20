// Messaging: the topic of document events, which the outbox relay publishes to, and one SQS queue per
// channel of the backend (the electronic document chain with the delivery, and the webhooks), each with
// its dead-letter queue and its retry policy. The subscriptions deliver raw messages and filter on the
// event type, from the routes the backend declares.
import { Stack, type StackProps } from 'aws-cdk-lib'
import { ManagedPolicy, PolicyStatement } from 'aws-cdk-lib/aws-iam'
import { Alias } from 'aws-cdk-lib/aws-kms'
import { SubscriptionFilter, Topic } from 'aws-cdk-lib/aws-sns'
import { SqsSubscription } from 'aws-cdk-lib/aws-sns-subscriptions'
import { Queue, QueueEncryption } from 'aws-cdk-lib/aws-sqs'
import type { Construct } from 'constructs'
import type { Channel, EventRoutes } from './event-routes.ts'
import { Published } from './published.ts'
import { resourceName, type StageConfig } from './stage.ts'

export interface MessagingStackProps extends StackProps {
  readonly stage: StageConfig
  readonly routes: EventRoutes
}

export class MessagingStack extends Stack {
  readonly topic: Topic
  readonly queues: Readonly<Record<Channel, Queue>>
  readonly deadLetterQueues: Readonly<Record<Channel, Queue>>

  constructor(scope: Construct, id: string, props: MessagingStackProps) {
    super(scope, id, props)
    const { stage, routes } = props
    const published = new Published(this, stage, 'messaging')

    // Encrypted at rest with the AWS managed key of SNS: only the relay publishes, so no key policy of
    // our own is needed. In transit, the topic refuses anything but TLS.
    this.topic = new Topic(this, 'DocumentEvents', {
      topicName: resourceName(stage, 'document-events'),
      displayName: 'Document events of dian-einvoice',
      masterKey: Alias.fromAliasName(this, 'SnsManagedKey', 'alias/aws/sns'),
      enforceSSL: true,
    })

    const queues: Partial<Record<Channel, Queue>> = {}
    const deadLetterQueues: Partial<Record<Channel, Queue>> = {}

    for (const channel of ['chain', 'webhooks'] as const) {
      const name = channel === 'chain' ? 'Chain' : 'Webhooks'

      const deadLetterQueue = new Queue(this, `${name}DeadLetterQueue`, {
        queueName: resourceName(stage, `${channel}-dlq`),
        encryption: QueueEncryption.SQS_MANAGED,
        enforceSSL: true,
        retentionPeriod: stage.queues.deadLetterRetention,
      })

      // The retry policy: a job that throws becomes visible again after the wait of its backoff, every
      // receive counts as an attempt, and after maxReceiveCount SQS moves the message to the dead-letter
      // queue. A consumer that dies leaves its message invisible for the visibility timeout.
      const queue = new Queue(this, `${name}Queue`, {
        queueName: resourceName(stage, channel),
        encryption: QueueEncryption.SQS_MANAGED,
        enforceSSL: true,
        visibilityTimeout: stage.queues.visibilityTimeout,
        retentionPeriod: stage.queues.retention,
        deadLetterQueue: { queue: deadLetterQueue, maxReceiveCount: stage.queues.maxReceiveCount },
      })

      // Raw delivery: the message of the queue is the event itself, with its type attribute. A message
      // SNS cannot deliver goes to the same dead-letter queue.
      this.topic.addSubscription(new SqsSubscription(queue, {
        rawMessageDelivery: true,
        filterPolicy: { type: SubscriptionFilter.stringFilter({ allowlist: [...routes[channel]] }) },
        deadLetterQueue,
      }))

      queues[channel] = queue
      deadLetterQueues[channel] = deadLetterQueue

      published.add(`${name}QueueUrl`, `${channel}-queue-url`, queue.queueUrl, `URL of the SQS queue of the ${channel} channel`)
      published.add(`${name}QueueArn`, `${channel}-queue-arn`, queue.queueArn, `ARN of the SQS queue of the ${channel} channel`)
      published.add(`${name}DeadLetterQueueUrl`, `${channel}-dlq-url`, deadLetterQueue.queueUrl, `URL of the dead-letter queue of the ${channel} channel`)

      // Consumers of the queue (the workers of the channel): receive, delete, change the visibility for
      // the backoff, and read the redrive policy.
      const consume = new ManagedPolicy(this, `Consume${name}Policy`, {
        managedPolicyName: resourceName(stage, `consume-${channel}`),
        description: `Consume the ${channel} queue of dian-einvoice (${stage.name})`,
        statements: [new PolicyStatement({
          actions: ['sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:ChangeMessageVisibility', 'sqs:GetQueueAttributes', 'sqs:GetQueueUrl'],
          resources: [queue.queueArn],
        })],
      })
      published.add(`Consume${name}PolicyArn`, `policies/consume-${channel}`, consume.managedPolicyArn, `Managed policy for the consumers of the ${channel} queue`)
    }

    this.queues = queues as Record<Channel, Queue>
    this.deadLetterQueues = deadLetterQueues as Record<Channel, Queue>

    // The outbox relay publishes every event to the topic.
    const publish = new ManagedPolicy(this, 'PublishEventsPolicy', {
      managedPolicyName: resourceName(stage, 'publish-document-events'),
      description: `Publish to the topic of document events of dian-einvoice (${stage.name})`,
      statements: [new PolicyStatement({ actions: ['sns:Publish'], resources: [this.topic.topicArn] })],
    })

    // The application dispatches a few jobs of its own to the chain queue (invitation and password reset emails).
    const sendJobs = new ManagedPolicy(this, 'SendJobsPolicy', {
      managedPolicyName: resourceName(stage, 'send-jobs'),
      description: `Dispatch jobs of the application to the chain queue of dian-einvoice (${stage.name})`,
      statements: [new PolicyStatement({ actions: ['sqs:SendMessage', 'sqs:GetQueueUrl'], resources: [this.queues.chain.queueArn] })],
    })

    // The console function (migrations and operations): the depth of every queue, and what waits in the
    // dead-letter queues, to read and remove.
    const inspect = new ManagedPolicy(this, 'InspectQueuesPolicy', {
      managedPolicyName: resourceName(stage, 'inspect-queues'),
      description: `Read the depth of the queues of dian-einvoice and take the messages of their dead-letter queues (${stage.name})`,
      statements: [
        new PolicyStatement({ actions: ['sqs:GetQueueAttributes', 'sqs:GetQueueUrl'], resources: [...Object.values(this.queues), ...Object.values(this.deadLetterQueues)].map((queue) => queue.queueArn) }),
        new PolicyStatement({ actions: ['sqs:ReceiveMessage', 'sqs:DeleteMessage', 'sqs:ChangeMessageVisibility'], resources: Object.values(this.deadLetterQueues).map((queue) => queue.queueArn) }),
      ],
    })

    published.add('EventsTopicArn', 'events-topic-arn', this.topic.topicArn, 'ARN of the topic of document events')
    published.add('InspectQueuesPolicyArn', 'policies/inspect-queues', inspect.managedPolicyArn, 'Managed policy for the console function, which reads the queues and their dead letters')
    published.add('PublishEventsPolicyArn', 'policies/publish-document-events', publish.managedPolicyArn, 'Managed policy for the outbox relay')
    published.add('SendJobsPolicyArn', 'policies/send-jobs', sendJobs.managedPolicyArn, 'Managed policy for the processes that dispatch jobs of their own')
  }
}
