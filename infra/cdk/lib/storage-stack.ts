// Storage: the bucket of the files of electronic documents (signed XML, ApplicationResponse,
// AttachedDocument, PDF and zip), private, encrypted, versioned and with lifecycle rules, and the bucket of
// its server access logs.
import { Stack, type StackProps, Validations } from 'aws-cdk-lib'
import { ManagedPolicy, PolicyStatement } from 'aws-cdk-lib/aws-iam'
import { BlockPublicAccess, Bucket, BucketEncryption, type CfnBucket, ObjectOwnership, StorageClass } from 'aws-cdk-lib/aws-s3'
import type { Construct } from 'constructs'
import { Published } from './published.ts'
import { bucketName, removalPolicy, resourceName, type StageConfig } from './stage.ts'

export interface StorageStackProps extends StackProps {
  readonly stage: StageConfig
}

export class StorageStack extends Stack {
  readonly documents: Bucket

  constructor(scope: Construct, id: string, props: StorageStackProps) {
    super(scope, id, props)
    const { stage } = props
    const published = new Published(this, stage, 'storage')

    // Server access logs need S3-managed keys and the bucket policy CDK writes for the logging service.
    const accessLogs = new Bucket(this, 'AccessLogs', {
      bucketName: bucketName(stage, 'access-logs'),
      encryption: BucketEncryption.S3_MANAGED,
      blockPublicAccess: BlockPublicAccess.BLOCK_ALL,
      objectOwnership: ObjectOwnership.BUCKET_OWNER_ENFORCED,
      enforceSSL: true,
      lifecycleRules: [{ id: 'expire-access-logs', expiration: stage.documents.accessLogsExpireAfter, abortIncompleteMultipartUploadAfter: stage.documents.accessLogsExpireAfter }],
      removalPolicy: removalPolicy(stage),
    })

    // Documents are kept: current versions never expire (the law keeps invoices for years) and move to
    // cheaper classes as they age; an overwritten or deleted version stays recoverable for a while.
    this.documents = new Bucket(this, 'Documents', {
      bucketName: bucketName(stage, 'documents'),
      encryption: BucketEncryption.S3_MANAGED,
      bucketKeyEnabled: true,
      blockPublicAccess: BlockPublicAccess.BLOCK_ALL,
      objectOwnership: ObjectOwnership.BUCKET_OWNER_ENFORCED,
      enforceSSL: true,
      versioned: true,
      serverAccessLogsBucket: accessLogs,
      serverAccessLogsPrefix: 'documents/',
      lifecycleRules: [
        {
          id: 'age-documents',
          transitions: [
            { storageClass: StorageClass.INFREQUENT_ACCESS, transitionAfter: stage.documents.infrequentAccessAfter },
            { storageClass: StorageClass.GLACIER_INSTANT_RETRIEVAL, transitionAfter: stage.documents.glacierInstantRetrievalAfter },
          ],
          noncurrentVersionExpiration: stage.documents.noncurrentVersionsExpireAfter,
          abortIncompleteMultipartUploadAfter: stage.documents.noncurrentVersionsExpireAfter,
        },
        { id: 'remove-expired-delete-markers', expiredObjectDeleteMarker: true },
      ],
      removalPolicy: removalPolicy(stage),
    })

    // The document storage of the application: read, write and delete objects, and list them to delete
    // the files of a company. Nothing else, and only over TLS (bucket policy).
    const readWrite = new ManagedPolicy(this, 'DocumentsReadWritePolicy', {
      managedPolicyName: resourceName(stage, 'documents-read-write'),
      description: `Read, write and delete the files of electronic documents of dian-einvoice (${stage.name})`,
      statements: [
        new PolicyStatement({ actions: ['s3:GetObject', 's3:PutObject', 's3:DeleteObject'], resources: [this.documents.arnForObjects('*')] }),
        new PolicyStatement({ actions: ['s3:ListBucket'], resources: [this.documents.bucketArn] }),
      ],
    })

    Validations.of(readWrite).acknowledge({
      id: `AwsSolutions::AwsSolutions-IAM5[Resource::<${this.getLogicalId(this.documents.node.defaultChild as CfnBucket)}.Arn>/*]`,
      reason: 'The application stores one object per file under a key of its company; the objects of the bucket are the resource, not a wildcard over other buckets.',
    })

    published.add('DocumentsBucketName', 'documents-bucket-name', this.documents.bucketName, 'Bucket of the files of electronic documents')
    published.add('DocumentsBucketArn', 'documents-bucket-arn', this.documents.bucketArn, 'ARN of the bucket of the files of electronic documents')
    published.add('DocumentsReadWritePolicyArn', 'policies/documents-read-write', readWrite.managedPolicyArn, 'Managed policy for the document storage of the application')
  }
}
