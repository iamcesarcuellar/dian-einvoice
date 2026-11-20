# Infrastructure

The production cloud architecture of the platform, written as code and proven locally. Nothing is deployed to a real AWS account: the AWS services run in a local emulator, [Floci](https://github.com/floci-io/floci) (open source, MIT, no account or token). This page covers Infrastructure task 1: messaging, storage, data, keys and email. Task 2 adds the compute, the network, the managed database and the delivery of the web app; task 3 the observability.

## Two environments

| | Local stack (default) | Emulated cloud |
| --- | --- | --- |
| Start | `make up`, `make demo` | `make cloud-up`, `make cloud-demo` |
| Stop | `make down` | `make cloud-down`; `make cloud-clean` also removes its state |
| Check | `make test`, `make checks` | `make cloud-check` |
| AWS services | none | Floci on 127.0.0.1:8604 (compose profile `cloud`) |
| Configuration | `backend/.env` | `backend/.env` plus `backend/.env.cloud`, which `make cloud-up` writes from the outputs of the CDK stage `local` |
| Database | MySQL, database `dian_einvoice` | the same MySQL, database `dian_einvoice_cloud` |

Both use the same ports (web app 8601, API 8600, DIAN simulator 8602), so one runs at a time: `make cloud-up` stops the API, workers and relay of the local stack, and `make up` stops those of the emulated cloud. They share the DIAN simulator; `make cloud-demo`, like `make seed`, resets it.

## The CDK app

`infra/cdk`, AWS CDK in TypeScript, run by Node's type stripping in its own container. Two stages: `local`, deployed to the emulator with the normal CDK commands (`cdk bootstrap`, `cdk deploy "local/*"`), and `prod`, synthesized and validated but never deployed (its account is a placeholder, so the CLI refuses real credentials). Synthesis needs no network: `make cdk-check` runs it in a container without one.

| Stack | Resources |
| --- | --- |
| `dian-einvoice-<stage>-messaging` | SNS topic of document events (TLS only, encrypted with the AWS managed key); SQS queues `chain` and `webhooks`, each with its dead-letter queue (14 days), visibility timeout 45 s and `maxReceiveCount` 10; one raw subscription per queue, filtered on the `type` attribute with the routes the backend declares (`backend/resources/outbox/routes.json`) |
| `dian-einvoice-<stage>-storage` | Documents bucket: private, SSE-S3 with a bucket key, versioned, TLS only, server access logs; current versions move to Standard-IA at 90 days and Glacier Instant Retrieval at 365 and never expire; old versions expire (30 days locally, 90 in prod) |
| `dian-einvoice-<stage>-data` | DynamoDB tables `cache`, `sessions` and `limits` in the format of Laravel's store (key `key`, TTL on `expires_at`), on demand |
| `dian-einvoice-<stage>-security` | KMS key of the signing certificates (rotation on), and the secret `dian-einvoice/<stage>/app`, whose `APP_KEY` Secrets Manager generates |
| `dian-einvoice-<stage>-mail` | SES identity: the sender address locally; in prod the domain, with Easy DKIM and a MAIL FROM subdomain |

Every stack also creates one managed IAM policy per consumer and capability (consume a queue, publish to the topic, read and write the documents, use a table, use the signing key with the encryption context of the key store, read the secret, send email as the platform address) and publishes the names, ARNs and policies under `/dian-einvoice/<stage>/<stack>/` in SSM Parameter Store, for task 2. Names carry the stage; resources carry the tags `project`, `stage` and `managed-by`, and stacks also `component`. cdk-nag (AWS Solutions) runs on every synthesis; its five exceptions per stage are acknowledged next to their resources with their reasons, and listed in `infra/cdk/cdk.out/validation-report.json`.

## Each piece, locally and in AWS

The application reaches AWS only through its ports; `backend/config/adapters.php` and Laravel's own settings choose the adapter.

| Piece | Local stack | AWS (emulated cloud, production) |
| --- | --- | --- |
| Events between the steps of the chain | Outbox relay → Redis queue | Outbox relay → SNS topic of document events (`SnsOutboxPublisher`) |
| Jobs of the electronic document chain and the delivery | Redis queue, `worker` ×2 | SQS queue `chain`, `cloud-chain` ×2 (`queue:work sqs --queue=chain`) |
| Webhook events and deliveries | the same Redis queue | SQS queue `webhooks`, `cloud-webhooks` |
| Jobs the application dispatches itself (invitations, password resets) | the same Redis queue | SQS queue `chain` |
| Retries with backoff | delayed Redis jobs | visibility timeout of the message (ChangeMessageVisibility) |
| Retries scheduled for later (transmission, webhooks) | delayed Redis jobs | the message stays invisible in its queue until it is due |
| Dead letters | table `failed_jobs` | dead-letter queue of each queue, by the redrive policy; `failed_jobs` still records the failures |
| Files of electronic documents | private disk `storage/app/documents` | S3 bucket (`S3DocumentStorage`) |
| Email | SMTP to the local mailbox (Mailpit) | SES, v2 API (the emulator keeps every email, at `/_aws/ses`) |
| Cache | Redis | DynamoDB table `cache` |
| Web sessions | Redis | DynamoDB table `sessions` |
| Rate limits (sign-in, API keys...) | Redis | DynamoDB table `limits` |
| Signing certificates | `key_store_entries`, encrypted with `APP_KEY` | `kms_key_store_entries`: each one encrypted with a data key of its own that the KMS key protects (`KmsKeyStore`) |
| Secrets of the application (`APP_KEY`, database credentials) | `backend/.env` | Secrets Manager, read at boot and kept 5 minutes in the container (`LoadSecrets`) |
| Names of the resources, for other stacks | — | SSM parameters `/dian-einvoice/<stage>/...` |
| Health checks | database and Redis | database, cache store and queue |
| Database | MySQL in compose | MySQL in compose, its own database (managed database: task 2) |
| Compute | compose containers | compose containers (task 2) |
| DIAN | local simulator | local simulator |

## What the emulator does not do

It accepts every resource of the templates, but it does not evaluate IAM policies, resource policies (TLS only, the topic as the only sender of the queues) or key policies, does not apply the encryption key of the topic, the server access logs of the bucket or the storage classes of the lifecycle rules, and delivers no email. Those parts are checked on the synthesized templates (CDK tests and cdk-nag) only. The details are in [limitations.md](limitations.md).
