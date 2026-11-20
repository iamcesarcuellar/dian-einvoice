// Mail: the sending identity of SES for the email of the platform (delivery of documents to customers,
// invitations, password resets). The stage local verifies the sender address, which the emulator accepts
// at once; prod verifies the whole domain with Easy DKIM and a MAIL FROM subdomain, whose DNS records
// (DKIM, SPF, DMARC) belong to Infrastructure task 2.
import { Stack, type StackProps } from 'aws-cdk-lib'
import { ManagedPolicy, PolicyStatement } from 'aws-cdk-lib/aws-iam'
import { EmailIdentity, Identity, MailFromBehaviorOnMxFailure } from 'aws-cdk-lib/aws-ses'
import type { Construct } from 'constructs'
import { Published } from './published.ts'
import { resourceName, type StageConfig } from './stage.ts'

export interface MailStackProps extends StackProps {
  readonly stage: StageConfig
}

export class MailStack extends Stack {
  readonly identity: EmailIdentity

  constructor(scope: Construct, id: string, props: MailStackProps) {
    super(scope, id, props)
    const { stage } = props
    const published = new Published(this, stage, 'mail')
    const byDomain = stage.mail.identity === 'domain'

    this.identity = new EmailIdentity(this, 'Sender', byDomain
      ? {
          identity: Identity.domain(stage.mail.domain),
          mailFromDomain: `mail.${stage.mail.domain}`,
          mailFromBehaviorOnMxFailure: MailFromBehaviorOnMxFailure.REJECT_MESSAGE,
        }
      : { identity: Identity.email(stage.mail.fromAddress) })

    // Send as the address of the platform only.
    const send = new ManagedPolicy(this, 'SendEmailPolicy', {
      managedPolicyName: resourceName(stage, 'send-email'),
      description: `Send the email of dian-einvoice (${stage.name}) from ${stage.mail.fromAddress}`,
      statements: [new PolicyStatement({
        actions: ['ses:SendEmail', 'ses:SendRawEmail'],
        resources: [this.formatArn({ service: 'ses', resource: 'identity', resourceName: this.identity.emailIdentityName })],
        conditions: { StringEquals: { 'ses:FromAddress': stage.mail.fromAddress } },
      })],
    })

    published.add('MailIdentity', 'identity', this.identity.emailIdentityName, 'SES identity that sends the email of the platform')
    published.add('MailFromAddress', 'from-address', stage.mail.fromAddress, 'Sender address of the email of the platform')
    published.add('SendEmailPolicyArn', 'policies/send-email', send.managedPolicyArn, 'Managed policy for the processes that send email')
  }
}
