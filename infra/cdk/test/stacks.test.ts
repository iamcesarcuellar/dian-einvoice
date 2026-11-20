// Assertions on the synthesized templates of both stages: the resources of each stack, their settings,
// the least-privilege policies of their consumers, the published parameters, the names and the tags.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import type { Stack } from 'aws-cdk-lib'
import { Match } from 'aws-cdk-lib/assertions'
import { STAGES } from '../lib/stage.ts'
import { logicalId, routes, stageOf, template } from './support.ts'

for (const name of ['local', 'prod'] as const) {
  const config = STAGES[name]
  const stacks = stageOf(name)
  const prefix = `dian-einvoice-${name}-`

  describe(`stage ${name}`, () => {
    it('deploys to its account and region, with names and tags that carry the stage', () => {
      for (const stack of [stacks.messaging, stacks.storage, stacks.data, stacks.security, stacks.mail, stacks.compute, ...(stacks.network === null ? [] : [stacks.network]), ...(stacks.database === null ? [] : [stacks.database])]) {
        assert.equal(stack.account, config.account)
        assert.equal(stack.region, config.region)
        assert.match(stack.stackName, new RegExp(`^${prefix}(messaging|storage|data|security|mail|compute|network|database)$`))
        assert.equal(stack.terminationProtection, name === 'prod')
        assert.deepEqual(stack.tags.tagValues(), { project: 'dian-einvoice', stage: name, component: stack.stackName.slice(prefix.length), 'managed-by': 'aws-cdk' })
      }

      template(stacks.messaging).hasResourceProperties('AWS::SQS::Queue', {
        QueueName: `${prefix}chain`,
        Tags: Match.arrayWith([{ Key: 'managed-by', Value: 'aws-cdk' }, { Key: 'project', Value: 'dian-einvoice' }, { Key: 'stage', Value: name }]),
      })
      template(stacks.storage).hasResourceProperties('AWS::S3::Bucket', {
        BucketName: `${prefix}documents-${config.account}-${config.region}`,
        Tags: Match.arrayWith([{ Key: 'managed-by', Value: 'aws-cdk' }, { Key: 'project', Value: 'dian-einvoice' }, { Key: 'stage', Value: name }]),
      })
    })

    it('messaging: a topic encrypted at rest that refuses anything but TLS', () => {
      const messaging = template(stacks.messaging)
      messaging.resourceCountIs('AWS::SNS::Topic', 1)
      messaging.hasResourceProperties('AWS::SNS::Topic', { TopicName: `${prefix}document-events`, KmsMasterKeyId: `arn:aws:kms:${config.region}:${config.account}:alias/aws/sns` })
      messaging.hasResourceProperties('AWS::SNS::TopicPolicy', {
        PolicyDocument: { Statement: Match.arrayWith([Match.objectLike({ Effect: 'Deny', Condition: { Bool: { 'aws:SecureTransport': 'false' } } })]) },
      })
    })

    it('messaging: a queue per channel with its dead-letter queue and retry policy, fed by a filtered raw subscription', () => {
      const messaging = template(stacks.messaging)
      messaging.resourceCountIs('AWS::SQS::Queue', 4)
      messaging.resourceCountIs('AWS::SNS::Subscription', 2)

      for (const channel of ['chain', 'webhooks'] as const) {
        const deadLetterQueue = logicalId(stacks.messaging, 'AWS::SQS::Queue', { QueueName: `${prefix}${channel}-dlq` })
        const queue = logicalId(stacks.messaging, 'AWS::SQS::Queue', { QueueName: `${prefix}${channel}` })

        messaging.hasResourceProperties('AWS::SQS::Queue', {
          QueueName: `${prefix}${channel}`,
          VisibilityTimeout: config.queues.visibilityTimeout.toSeconds(),
          MessageRetentionPeriod: 4 * 24 * 3600,
          SqsManagedSseEnabled: true,
          RedrivePolicy: { deadLetterTargetArn: { 'Fn::GetAtt': [deadLetterQueue, 'Arn'] }, maxReceiveCount: 10 },
        })
        messaging.hasResourceProperties('AWS::SQS::Queue', { QueueName: `${prefix}${channel}-dlq`, MessageRetentionPeriod: 14 * 24 * 3600, SqsManagedSseEnabled: true })

        messaging.hasResourceProperties('AWS::SNS::Subscription', {
          Protocol: 'sqs',
          Endpoint: { 'Fn::GetAtt': [queue, 'Arn'] },
          RawMessageDelivery: true,
          FilterPolicy: { type: [...routes[channel]] },
          RedrivePolicy: { deadLetterTargetArn: { 'Fn::GetAtt': [deadLetterQueue, 'Arn'] } },
        })

        // Only the topic may send to the queue, and only over TLS.
        messaging.hasResourceProperties('AWS::SQS::QueuePolicy', {
          Queues: [{ Ref: queue }],
          PolicyDocument: {
            Statement: Match.arrayWith([
              Match.objectLike({ Effect: 'Deny', Condition: { Bool: { 'aws:SecureTransport': 'false' } } }),
              Match.objectLike({ Effect: 'Allow', Action: 'sqs:SendMessage', Principal: { Service: 'sns.amazonaws.com' }, Condition: { ArnEquals: { 'aws:SourceArn': { Ref: Match.anyValue() } } } }),
            ]),
          },
        })

        messaging.hasResourceProperties('AWS::IAM::ManagedPolicy', {
          ManagedPolicyName: `${prefix}consume-${channel}`,
          PolicyDocument: {
            Statement: [{
              Effect: 'Allow',
              Action: ['sqs:ChangeMessageVisibility', 'sqs:DeleteMessage', 'sqs:GetQueueAttributes', 'sqs:GetQueueUrl', 'sqs:ReceiveMessage'],
              Resource: { 'Fn::GetAtt': [queue, 'Arn'] },
            }],
          },
        })
      }
    })

    it('messaging: the relay may only publish to the topic, and the application only send jobs to the chain queue', () => {
      const messaging = template(stacks.messaging)
      messaging.hasResourceProperties('AWS::IAM::ManagedPolicy', {
        ManagedPolicyName: `${prefix}publish-document-events`,
        PolicyDocument: { Statement: [{ Effect: 'Allow', Action: 'sns:Publish', Resource: { Ref: Match.anyValue() } }] },
      })
      messaging.hasResourceProperties('AWS::IAM::ManagedPolicy', {
        ManagedPolicyName: `${prefix}send-jobs`,
        PolicyDocument: { Statement: [{ Effect: 'Allow', Action: ['sqs:GetQueueUrl', 'sqs:SendMessage'], Resource: { 'Fn::GetAtt': [logicalId(stacks.messaging, 'AWS::SQS::Queue', { QueueName: `${prefix}chain` }), 'Arn'] } }] },
      })
    })

    it('storage: a private, encrypted, versioned documents bucket with lifecycle rules and access logs', () => {
      const storage = template(stacks.storage)
      const logs = logicalId(stacks.storage, 'AWS::S3::Bucket', { BucketName: `${prefix}access-logs-${config.account}-${config.region}` })

      storage.hasResource('AWS::S3::Bucket', {
        DeletionPolicy: name === 'prod' ? 'Retain' : 'Delete',
        Properties: {
          BucketName: `${prefix}documents-${config.account}-${config.region}`,
          VersioningConfiguration: { Status: 'Enabled' },
          BucketEncryption: { ServerSideEncryptionConfiguration: [{ ServerSideEncryptionByDefault: { SSEAlgorithm: 'AES256' }, BucketKeyEnabled: true }] },
          PublicAccessBlockConfiguration: { BlockPublicAcls: true, BlockPublicPolicy: true, IgnorePublicAcls: true, RestrictPublicBuckets: true },
          OwnershipControls: { Rules: [{ ObjectOwnership: 'BucketOwnerEnforced' }] },
          LoggingConfiguration: { DestinationBucketName: { Ref: logs }, LogFilePrefix: 'documents/' },
          LifecycleConfiguration: {
            Rules: [
              Match.objectLike({
                Id: 'age-documents',
                Status: 'Enabled',
                Transitions: [{ StorageClass: 'STANDARD_IA', TransitionInDays: 90 }, { StorageClass: 'GLACIER_IR', TransitionInDays: 365 }],
                NoncurrentVersionExpiration: { NoncurrentDays: config.documents.noncurrentVersionsExpireAfter.toDays() },
              }),
              Match.objectLike({ Id: 'remove-expired-delete-markers', ExpiredObjectDeleteMarker: true }),
            ],
          },
        },
      })

      // No current version ever expires: documents are kept.
      const documents = Object.values(storage.findResources('AWS::S3::Bucket', { Properties: { VersioningConfiguration: { Status: 'Enabled' } } }))[0] as { Properties: { LifecycleConfiguration: { Rules: { ExpirationInDays?: number }[] } } }
      assert.ok(documents.Properties.LifecycleConfiguration.Rules.every((rule) => rule.ExpirationInDays === undefined))

      storage.resourcePropertiesCountIs('AWS::S3::BucketPolicy', {
        PolicyDocument: { Statement: Match.arrayWith([Match.objectLike({ Effect: 'Deny', Action: 's3:*', Condition: { Bool: { 'aws:SecureTransport': 'false' } } })]) },
      }, 3)
    })

    it('storage: a private bucket for the deployments of the functions, whose artifacts expire', () => {
      const storage = template(stacks.storage)
      const logs = logicalId(stacks.storage, 'AWS::S3::Bucket', { BucketName: `${prefix}access-logs-${config.account}-${config.region}` })

      storage.hasResourceProperties('AWS::S3::Bucket', {
        BucketName: `${prefix}deployments-${config.account}-${config.region}`,
        BucketEncryption: { ServerSideEncryptionConfiguration: [{ ServerSideEncryptionByDefault: { SSEAlgorithm: 'AES256' } }] },
        PublicAccessBlockConfiguration: { BlockPublicAcls: true, BlockPublicPolicy: true, IgnorePublicAcls: true, RestrictPublicBuckets: true },
        LoggingConfiguration: { DestinationBucketName: { Ref: logs }, LogFilePrefix: 'deployments/' },
        LifecycleConfiguration: { Rules: [Match.objectLike({ Id: 'expire-deployments', ExpirationInDays: config.deployments.expireAfter.toDays() })] },
      })
    })

    it('storage: the application may read, write, delete and list the documents, nothing more', () => {
      const bucket = logicalId(stacks.storage, 'AWS::S3::Bucket', { BucketName: `${prefix}documents-${config.account}-${config.region}` })

      template(stacks.storage).hasResourceProperties('AWS::IAM::ManagedPolicy', {
        ManagedPolicyName: `${prefix}documents-read-write`,
        PolicyDocument: {
          Statement: [
            { Effect: 'Allow', Action: ['s3:DeleteObject', 's3:GetObject', 's3:PutObject'], Resource: { 'Fn::Join': ['', [{ 'Fn::GetAtt': [bucket, 'Arn'] }, '/*']] } },
            { Effect: 'Allow', Action: 's3:ListBucket', Resource: { 'Fn::GetAtt': [bucket, 'Arn'] } },
          ],
        },
      })
    })

    it('data: tables for the cache, the sessions and the rate limits, with automatic expiry', () => {
      const data = template(stacks.data)
      data.resourceCountIs('AWS::DynamoDB::Table', 3)

      for (const use of ['cache', 'sessions', 'limits']) {
        data.hasResourceProperties('AWS::DynamoDB::Table', {
          TableName: `${prefix}${use}`,
          KeySchema: [{ AttributeName: 'key', KeyType: 'HASH' }],
          AttributeDefinitions: [{ AttributeName: 'key', AttributeType: 'S' }],
          TimeToLiveSpecification: { AttributeName: 'expires_at', Enabled: true },
          BillingMode: 'PAY_PER_REQUEST',
          SSESpecification: { SSEEnabled: true },
        })

        data.hasResourceProperties('AWS::IAM::ManagedPolicy', {
          ManagedPolicyName: `${prefix}use-${use}-table`,
          PolicyDocument: {
            Statement: [{
              Effect: 'Allow',
              Action: ['dynamodb:BatchGetItem', 'dynamodb:BatchWriteItem', 'dynamodb:DeleteItem', 'dynamodb:DescribeTable', 'dynamodb:GetItem', 'dynamodb:PutItem', 'dynamodb:UpdateItem'],
              Resource: { 'Fn::GetAtt': [logicalId(stacks.data, 'AWS::DynamoDB::Table', { TableName: `${prefix}${use}` }), 'Arn'] },
            }],
          },
        })
      }
    })

    it('security: a rotating KMS key for the signing certificates, usable only with the context of the key store', () => {
      const security = template(stacks.security)
      const key = logicalId(stacks.security, 'AWS::KMS::Key', { EnableKeyRotation: true })

      security.hasResource('AWS::KMS::Key', { DeletionPolicy: name === 'prod' ? 'Retain' : 'Delete', Properties: { KeySpec: 'SYMMETRIC_DEFAULT', KeyUsage: 'ENCRYPT_DECRYPT' } })
      security.hasResourceProperties('AWS::KMS::Alias', { AliasName: `alias/${prefix}signing-keys`, TargetKeyId: { 'Fn::GetAtt': [key, 'Arn'] } })
      security.hasResourceProperties('AWS::IAM::ManagedPolicy', {
        ManagedPolicyName: `${prefix}use-signing-key`,
        PolicyDocument: {
          Statement: [{
            Effect: 'Allow',
            Action: ['kms:Decrypt', 'kms:GenerateDataKey'],
            Resource: { 'Fn::GetAtt': [key, 'Arn'] },
            Condition: {
              'StringEquals': { 'kms:EncryptionContext:purpose': 'signing-certificate' },
              'ForAllValues:StringEquals': { 'kms:EncryptionContextKeys': ['company_id', 'entry_id', 'purpose'] },
            },
          }],
        },
      })
    })

    it('security: the secret of the application, with APP_KEY generated by Secrets Manager', () => {
      const security = template(stacks.security)

      security.hasResource('AWS::SecretsManager::Secret', {
        DeletionPolicy: name === 'prod' ? 'Retain' : 'Delete',
        Properties: {
          Name: `dian-einvoice/${name}/app`,
          // APP_KEY alone: the credentials of the database are in the secrets of its identities.
          GenerateSecretString: { GenerateStringKey: 'APP_KEY', PasswordLength: 32, ExcludePunctuation: true, SecretStringTemplate: '{}' },
        },
      })

      // No secret value is ever written into a template.
      assert.ok(!JSON.stringify(security.toJSON()).includes('"SecretString"'))
      security.hasResourceProperties('AWS::IAM::ManagedPolicy', {
        ManagedPolicyName: `${prefix}read-app-secret`,
        PolicyDocument: { Statement: [{ Effect: 'Allow', Action: ['secretsmanager:DescribeSecret', 'secretsmanager:GetSecretValue'], Resource: { Ref: logicalId(stacks.security, 'AWS::SecretsManager::Secret', { Name: `dian-einvoice/${name}/app` }) } }] },
      })
    })

    it('mail: the sending identity of SES, and sending only as the address of the platform', () => {
      const mail = template(stacks.mail)

      if (name === 'local') {
        mail.hasResourceProperties('AWS::SES::EmailIdentity', { EmailIdentity: config.mail.fromAddress })
      } else {
        mail.hasResourceProperties('AWS::SES::EmailIdentity', {
          EmailIdentity: config.mail.domain,
          MailFromAttributes: { MailFromDomain: `mail.${config.mail.domain}`, BehaviorOnMxFailure: 'REJECT_MESSAGE' },
        })
      }

      mail.hasResourceProperties('AWS::IAM::ManagedPolicy', {
        ManagedPolicyName: `${prefix}send-email`,
        PolicyDocument: { Statement: [Match.objectLike({ Action: ['ses:SendEmail', 'ses:SendRawEmail'], Condition: { StringEquals: { 'ses:FromAddress': config.mail.fromAddress } } })] },
      })
    })

    it('publishes the names of its resources and policies as parameters, for the functions', () => {
      const names: string[] = []

      // The stack security of a stage without a managed database (local) publishes the identities of the
      // database under the names the stack database uses in prod.
      const groups: [Stack, string][] = [[stacks.messaging, 'messaging'], [stacks.storage, 'storage'], [stacks.data, 'data'], [stacks.security, config.database.managed === null ? '(security|database)' : 'security'], [stacks.mail, 'mail'], [stacks.compute, 'compute']]

      if (stacks.network !== null && stacks.database !== null) {
        groups.push([stacks.network, 'network'], [stacks.database, 'database'])
      }

      for (const [stack, group] of groups) {
        const parameters = Object.values(template(stack).findResources('AWS::SSM::Parameter')) as { Properties: { Name: string, Type: string } }[]
        const outputs = Object.keys(template(stack).findOutputs('*')).filter((output) => !output.startsWith('PublishOutput'))

        assert.equal(parameters.length, outputs.length, `${group}: one parameter per output`)

        for (const parameter of parameters) {
          assert.match(parameter.Properties.Name, new RegExp(`^/dian-einvoice/${name}/${group}/`))
          assert.equal(parameter.Properties.Type, 'String')
          names.push(parameter.Properties.Name)
        }
      }

      assert.equal(new Set(names).size, names.length)

      for (const wanted of ['messaging/events-topic-arn', 'messaging/chain-queue-url', 'messaging/webhooks-queue-url', 'storage/documents-bucket-name', 'storage/deployments-bucket-name', 'data/cache-table-name', 'data/sessions-table-name', 'data/limits-table-name', 'security/signing-key-arn', 'security/app-secret-arn', 'mail/identity', 'compute/roles/api', 'compute/roles/schedules', 'compute/functions-repository-uri']) {
        assert.ok(names.includes(`/dian-einvoice/${name}/${wanted}`), wanted)
      }

      // Every managed policy is published, so the roles of the functions attach them by name.
      const policies = [stacks.messaging, stacks.storage, stacks.data, stacks.security, stacks.mail].reduce((count, stack) => count + Object.keys(template(stack).findResources('AWS::IAM::ManagedPolicy')).length, 0)
      assert.equal(names.filter((parameter) => parameter.includes('/policies/')).length, policies)
    })
  })
}

describe('event routes', () => {
  it('every event type the backend routes reaches exactly the queues of its channels', () => {
    const subscriptions = Object.values(template(stageOf('local').messaging).findResources('AWS::SNS::Subscription')) as { Properties: { FilterPolicy: { type: string[] } } }[]
    const filtered = subscriptions.map((subscription) => [...subscription.Properties.FilterPolicy.type].sort())

    assert.deepEqual(filtered.sort(), [[...routes.chain].sort(), [...routes.webhooks].sort()].sort())
    assert.ok(routes.chain.includes('invoice.issued') && routes.webhooks.includes('invoice.issued'))
    assert.ok(routes.webhooks.includes('webhook.delivery_due') && !routes.chain.includes('webhook.delivery_due'))
  })
})
