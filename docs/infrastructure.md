# Infrastructure

The production cloud architecture of the platform, written as code and proven locally. Nothing is deployed to a real AWS account: the AWS services run in a local emulator, [Floci](https://github.com/floci-io/floci) (open source, MIT, no account or token). Infrastructure task 1 brought messaging, storage, data, keys and email; task 2 runs the application as AWS Lambda functions; task 3 gives prod its private network and its managed database (Aurora MySQL Serverless v2), which exist only in the stage prod and are validated offline, never deployed, not even to the emulator, and splits the access to the database into two identities, in both stages; task 4 puts the edge in front of the web app and the API: one CloudFront distribution under one domain, its security headers, a firewall in prod, and an origin verifier, so the API answers only what came through the edge. Task 5 brings the observability, the delivery pipeline and the cost.

## Two environments

| | Local stack (default) | Emulated cloud |
| --- | --- | --- |
| Start | `make up`, `make demo` | `make cloud-up`, `make cloud-demo` |
| Stop | `make down` | `make cloud-down`; `make cloud-clean` also removes its state |
| Check | `make test`, `make checks`, `make e2e` | `make cloud-check`, `make cloud-e2e` |
| The application | containers that run all the time: `api`, `worker` ×2, `relay` | Lambda functions in the emulator, started per execution environment; nothing of the application runs all the time |
| The web app | the development server of Vite (`web`), which forwards `/api` and `/sanctum` to the API | the production build, in the bucket of the edge, served by the CloudFront distribution of the emulator with the API on the same origin |
| AWS services | none | Floci on 127.0.0.1:8604 (compose profile `cloud`), with the Docker socket; its web console (functions and their logs, queues, buckets, tables, secrets, keys, the emails SES took, the stacks), with no account, on 127.0.0.1:8605, started the first time `http://localhost:8604/_floci/ui` is opened |
| Configuration | `backend/.env` | the environment of each function, from the serverless service and the SSM parameters of the CDK app; secrets from Secrets Manager |
| Database | MySQL, database `dian_einvoice`, one user | the same MySQL, database `dian_einvoice_cloud`, with its two identities: migrations (the console function) and application (every other function), each with its secret |

Both use the same ports (web app 8601, API 8600, DIAN simulator 8602), so one runs at a time: `make cloud-up` stops the API, workers, relay and development server of the web app of the local stack, and `make up` stops the emulator and the proxy of the emulated cloud and removes the containers of its functions. They share the DIAN simulator; `make cloud-demo`, like `make seed`, resets it. In the emulated cloud both ports belong to `cloud-gateway`, a minimal reverse proxy (nginx) in front of the CloudFront distribution, which the emulator serves by host name only (`<id>.cloudfront.localhost.floci.io`): 8601 serves the web app and the API on one origin, as in production, and 8600 the same edge, so the address of the API keeps answering. In AWS the domain of the platform points at the distribution (the edge, below).

## The CDK app

`infra/cdk`, AWS CDK in TypeScript, run by Node's type stripping in its own container. Two stages: `local`, deployed to the emulator with the normal CDK commands (`cdk bootstrap`, `cdk deploy`: every stack but the edge first, then the edge, after the functions it fronts), and `prod`, synthesized and validated but never deployed (its account is a placeholder, so the CLI refuses real credentials). Synthesis needs no network: `make cdk-check` runs it in a container without one. The stacks network and database exist in prod only: the emulator could not give meaningful evidence for a VPC, its routes and security groups, or a managed database, so they are checked on what synthesis produces (assertions, cdk-nag, cfn-lint and the schema validation of CDK) and never deployed.

| Stack | Resources |
| --- | --- |
| `dian-einvoice-<stage>-messaging` | SNS topic of document events (TLS only, encrypted with the AWS managed key); SQS queues `chain` and `webhooks`, each with its dead-letter queue (14 days), visibility timeout 60 s locally and 360 s in prod, and `maxReceiveCount` 10; one raw subscription per queue, filtered on the `type` attribute with the routes the backend declares (`backend/resources/outbox/routes.json`) |
| `dian-einvoice-<stage>-storage` | Documents bucket: private, SSE-S3 with a bucket key, versioned, TLS only, server access logs; current versions move to Standard-IA at 90 days and Glacier Instant Retrieval at 365 and never expire; old versions expire (30 days locally, 90 in prod). The deployments bucket of the serverless service (its templates and state, 7 days locally, 90 in prod) |
| `dian-einvoice-<stage>-data` | DynamoDB tables `cache`, `sessions` and `limits` in the format of Laravel's store (key `key`, TTL on `expires_at`), on demand |
| `dian-einvoice-<stage>-security` | KMS key of the signing certificates (rotation on), the secret `dian-einvoice/<stage>/app`, whose `APP_KEY` Secrets Manager generates and which holds nothing else, and the secret of the edge `dian-einvoice/<stage>/edge/origin-verify` (64 letters and digits Secrets Manager generates, below). In the stage local, which has no managed database, also the secrets of the two identities of the database (below), which prod keeps in the stack database |
| `dian-einvoice-<stage>-mail` | SES identity: the sender address locally; in prod the domain, with Easy DKIM and a MAIL FROM subdomain |
| `dian-einvoice-<stage>-compute` | One IAM role per function (`dian-einvoice-<stage>-<function>-function`), which may read the secret of its identity of the database (the console function both), the role of the origin verifier, which may only read the secret of the edge, the role EventBridge Scheduler invokes the scheduled functions with, and the ECR repository of the image of the functions (scan on push, immutable tags in prod, only Lambda pulls, for the functions of the stage) |
| `dian-einvoice-<stage>-edge` | The CloudFront distribution in front of the web app and the API, its bucket of the web app (private, versioned, read only by the distribution), its CloudFront Function, cache policy and response headers policy, its bucket of access logs, and in prod its domain with its certificate and its web ACL of AWS WAF (the edge, below). Deployed after the functions, whose HTTP API it fronts |
| `dian-einvoice-prod-network` (prod only) | VPC across two zones with public, private and isolated subnets, one NAT gateway with a fixed address (one per zone as an option), gateway endpoints for S3 and DynamoDB, interface endpoints for the other AWS services of the adapters as an option, the security group of the functions, the default security group without rules, and flow logs (below) |
| `dian-einvoice-prod-database` (prod only) | Aurora MySQL Serverless v2 compatible with MySQL 8.4, its KMS key, parameters, log groups and security groups, the secrets of the two identities with the rotation functions of AWS, and RDS Proxy as an option (below) |

Every stack also creates one managed IAM policy per consumer and capability (consume a queue, publish to the topic, read and write the documents, use a table, use the signing key with the encryption context of the key store, read the secret, send email as the platform address, read the queues and their dead letters) and publishes the names, ARNs and policies under `/dian-einvoice/<stage>/<stack>/` in SSM Parameter Store. Names carry the stage; resources carry the tags `project`, `stage` and `managed-by`, and stacks also `component`. cdk-nag (AWS Solutions) runs on every synthesis; its exceptions are acknowledged next to their resources with their reasons, and listed in `infra/cdk/cdk.out/validation-report.json`: per stage the three tables without point-in-time recovery, the objects of the documents bucket, the secret of `APP_KEY` without rotation, the secret of the edge, rotated by its procedure rather than a rotation function, one per role for the log streams of its function (the origin verifier included), and the distribution without a geographic restriction; in prod also one per role for the network interfaces of a function in a VPC, and three on the database (IAM authentication, the default port, Backtrack); locally also the secrets of the two identities, which no rotation function of AWS can rotate in the emulator, and on the distribution the default certificate of CloudFront (no domain of its own) and no web ACL (the emulator applies no AWS WAF). The reasons are next to each resource and in the sections on the database and on the edge below.

## The functions

`infra/functions`, a serverless service deployed with [osls](https://github.com/oss-serverless/osls) 4.8.1, the free fork of the Serverless Framework (MIT, no account, no telemetry), in a container of its own: the stack `dian-einvoice-<stage>-functions`. The stage local is deployed to the emulator by `make cloud-up`; prod is packaged without a network or credentials and validated by `make cdk-check`.

Every function runs one container image, the target `lambda` of `backend/Dockerfile`: the application with its production dependencies on the official image of [Bref](https://bref.sh) 3 for PHP 8.5 (`bref/php-85:3.0.12`, the minor version of the local image), with the extensions the application requires (the `ext-*` of `backend/composer.json`: the build fails when one is missing) and Bref's Laravel bridge. A function chooses its runtime of Bref (`BREF_RUNTIME`) and its handler (the command of the image):

| Function | Trigger | Mode of Bref | Memory | Timeout | Concurrency | Why |
| --- | --- | --- | --- | --- | --- | --- |
| `api` | API Gateway HTTP API, every route (`$default`), payload 2.0, behind the origin verifier | `fpm`, `public/index.php` | 1024 MB | 28 s | 40 reserved | The whole HTTP API under `/api/v1`, the CSRF cookie of the web app and the reference at `/docs/api`. Memory buys CPU for short answers and short cold starts (Lambda gives CPU in proportion); below the 30 s API Gateway waits for an integration. 40 environments at once are 40 requests in flight, the biggest share of the connection budget |
| `chain` | SQS queue `chain`: batches of up to 10, no batching window, partial batch response, at most 10 invocations at once | `function`, `App\Support\Outbox\Sqs\SqsEventsHandler` | 1024 MB | 60 s | 10 reserved | Every step of the electronic document chain and the delivery: XML, XAdES signature, transmission to the DIAN, PDF (dompdf) and email. Signing and the PDF want CPU and memory. A job may run 35 s, as in the local workers; 60 s is one such job and the margin, and a batch stops starting jobs it could not finish before the deadline and hands them back |
| `webhooks` | SQS queue `webhooks`: the same, at most 5 at once | `function`, the same handler | 512 MB | 60 s | 5 reserved | Webhook events and their deliveries: HTTP calls of 5 s at most, little CPU |
| `relay` | EventBridge Scheduler, `rate(1 minute)`, input `"outbox:publish-pending"` | `console`, `artisan` | 512 MB | 60 s | 1 reserved | The safety net of the outbox. It publishes for up to 40 s and leaves the rest to the next run. One at a time; a run that fails is not retried (no asynchronous retries, events older than 60 s dropped, no retries of the schedule): the next minute is its retry |
| `scheduler` | EventBridge Scheduler, `rate(1 minute)`, input `"schedule:run"` | `console`, `artisan` | 512 MB | 120 s | 2 reserved | The Laravel scheduler: the sweepers (stalled chains, deliveries and webhooks, expired idempotency keys, the retention pruning once an hour) and a heartbeat. Two at once let a long pruning overlap the next minute; each task runs on one process per minute and never alongside itself (locks of the cache). No retries, as the relay |
| `console` | none: invoked on demand | `console`, `artisan` | 1024 MB | 900 s | 2 reserved | Migrations and operation commands (`make cloud-up` migrates with it, `make cloud-demo` seeds, `make cloud-check` runs its steps, `make cloud-artisan ARGS="..."` runs any other): the event is the command line of `php artisan`. The longest a Lambda function may run |
| `origin-verifier` | the authorizer of every route of API Gateway (a request authorizer of payload 2.0, simple answers, its answer kept 5 minutes per value of `X-Origin-Verify`) | not Bref: Node.js 24, a zip of `origin-verifier/` built from TypeScript | 128 MB | 5 s | 10 reserved | The origin verifier of the edge (below): a comparison and, at most every 30 s, a read of Secrets Manager. Outside the VPC, with a role that may only read the secret of the edge; no database. Its cap bounds what a flood of made-up values sent to API Gateway directly can run |

The roles come from the stack compute (`role: !Sub '${ApiRoleArn}'`), the queues, the topic, the bucket, the tables, the key and the secret from the parameters the CDK app publishes in SSM (`resources/parameters.yml`, parameters of the template whose defaults are those names, which CloudFormation reads when it deploys); the mappings of the queues and the schedules are written as CloudFormation (`resources/triggers.yml`), since osls cannot take an ARN from a parameter or set a retry policy on a schedule. The functions have no IAM of their own. Each one writes its logs to stderr, to its log group `/aws/lambda/<function>` (7 days locally, 365 in prod), and API Gateway its access log to a log group of the service.

### The journey of an invoice

```mermaid
sequenceDiagram
    autonumber
    participant Client as Web app or API client
    participant Edge as Edge (CloudFront)
    participant APIGW as API Gateway (HTTP API)
    participant API as Function api
    participant DB as MySQL (outbox)
    participant SNS as SNS topic of document events
    participant SQS as SQS queues chain and webhooks
    participant Chain as Function chain
    participant DIAN as DIAN (local simulator)
    participant S3 as S3 documents bucket
    participant SES as SES
    participant Relay as Function relay (every minute)

    Client->>Edge: POST /api/v1/invoices/{id}/issue (session cookie and CSRF, or API key)
    Edge->>APIGW: the request and the secret of the edge (origin verifier)
    APIGW->>API: event of payload 2.0
    API->>DB: number, document and outbox event, in one transaction
    API->>SNS: after the commit, before answering: publish the event, mark it published (fast path)
    API-->>Client: 200 through API Gateway and the edge
    SNS->>SQS: filtered by event type (raw delivery)
    SQS->>Chain: batch of messages (event source mapping)
    Chain->>S3: signed XML (key store: KMS)
    Chain->>DB: next step and its outbox event, in one transaction
    Chain->>SNS: after the commit: publish the next event
    Chain-->>SQS: partial batch response: only the failed messages stay
    SQS->>Chain: transmission step
    Chain->>DIAN: SendBillSync (SOAP), ApplicationResponse
    Chain->>S3: answer, AttachedDocument, PDF and zip
    Chain->>SES: email to the customer with the zip
    Note over SQS,Chain: Webhooks go the same way through the queue webhooks and its function
    Relay->>DB: events older than 10 s still unpublished (a writer that died after its commit)
    Relay->>SNS: publish them, claimed with FOR UPDATE SKIP LOCKED
```

### The outbox without a relay that runs all the time

Every change records its integration events in the outbox table in its own transaction, as in the local stack. Locally the relay process publishes them all the time. The functions have no such process, and a relay function once a minute alone would add up to a minute to every step of the chain, so in the functions (`OUTBOX_PUBLISH_AFTER_COMMIT`):

- **Fast path.** Whoever wrote the events publishes them right after its transaction commits (`DB::afterCommit`), before it answers the request or finishes the job, and marks them published (`App\Support\Outbox\OutboxFastPath`). The events of one transaction go together. A publish that fails loses nothing: the change is committed, the error is reported, and the rows stay unpublished in the outbox.
- **Safety net.** The relay function publishes, once a minute, the events still unpublished that are older than 10 s (`php artisan outbox:publish-pending`): those of a process that died after its commit, or whose publish failed.
- Both claim rows the way every relay does: `FOR UPDATE SKIP LOCKED` in read committed, publish, mark published, commit. A row one holds is skipped by the other, and one already published is not taken again. Delivery stays at least once (a process that dies between the publish and its commit publishes again), and every consumer is idempotent.
- The demo seeder runs the chain of its documents in line, so it publishes nothing after its commits and pauses the relay function and the sweepers in the shared cache until it ends (`App\Support\Outbox\OutboxPause`), as `make seed` stops the local relay.

The local stack keeps its relay process unchanged; the fast path is off there.

### Inside a function

- **Read-only file system but `/tmp`.** Bref's Laravel bridge, registered only inside Lambda, puts the storage of Laravel, the compiled views and the file cache in `/tmp`; dompdf, the heartbeat and the cache of the secrets write there too. The manifests of the packages and of the service providers are written when the image is built. The emulator runs every function as an unprivileged user (`993:990`, as Lambda does) that owns nothing in the image.
- **Secrets once per cold start.** When an execution environment starts, the bridge caches the whole configuration in `/tmp` (`php artisan config:cache`, `BREF_LARAVEL_CACHE_CONFIG`), which reads the secret of the application and the secret of the identity of the database of the function from Secrets Manager (`App\Support\Secrets\LoadSecrets`); later requests and jobs boot from that cache and read nothing. A rotated secret reaches the environments started after the rotation; one already warm reads the secret of its identity again only when the database refuses its password (below).
- **Logs to stderr**, where Lambda sends them to CloudWatch Logs; the formatter of Bref starts each line with the id of the request.
- **Nothing after the answer.** AWS freezes an environment as soon as it answers, which the emulator does not reproduce, so nothing may wait for after the answer: the outbox publishes before it, PHP-FPM of Bref has `fastcgi_finish_request` disabled, no scheduled task runs in the background, and `tests/Unit/Support/NothingAfterTheAnswerTest.php` refuses the calls that would leave work for later (after-response dispatches, `defer()`, terminating callbacks, shutdown functions, background processes).
- **The client address.** PHP-FPM receives every request from the Bref runtime (127.0.0.1), and the functions trust 127.0.0.1 alone (`TRUSTED_PROXIES`): the address of a request is the last one of `X-Forwarded-For`. Behind the edge that one is not the client's, so before anything counts a request the application keeps of `X-Forwarded-For` only the entry the edge appended: the last one, once an entry API Gateway appended for its peer is dropped (`App\Http\Middleware\EdgeClientAddress`; the edge, below): a client cannot choose its own address for the rate limits by sending any header, and without that entry every such request shares the address of the runtime, which makes a limit stricter, never looser. Of the forwarded headers only the address and the scheme count, never the host: behind the edge the application generates every URL from `APP_URL`, the public origin.
- **The consumers.** The handler of the queues maps each SQS record to the job of task 1 (the same mapping of outbox events and jobs, `SqsEventsQueue::jobFor`) and runs it with Laravel's queue worker, one after another: a job that succeeds deletes its message; one that throws changes the visibility of its message to the wait of its backoff and is reported as failed; an event due later stays invisible until then; a record whose job would not finish before the deadline goes back at once. The retries, the dead-letter queue after `maxReceiveCount` and the idempotency of the handlers are those of task 1. After each batch the function gives its database connection back.

### The budget of database connections

Each execution environment holds at most one connection to the database: PHP-FPM of Bref runs one worker per environment and closes its connection with every request, and the consumers close theirs after each batch. The reserved concurrency of the functions caps the environments at once: 40 (api) + 10 (chain) + 5 (webhooks) + 1 (relay) + 2 (scheduler) + 2 (console) = **60 connections at most**, which a test of the CDK app holds the template of the functions to; the origin verifier of the edge never connects to the database and does not count. The managed database of prod gives 135 at its maximum capacity of 4 ACU, and `infra/cdk/test/database.test.ts` holds that figure, from the table AWS documents, at or above those 60 plus a margin of 20 for the rotation functions and the operators (the database section below); RDS Proxy, an option, would let the API grow beyond them. In AWS, reserved concurrency needs an account limit of concurrent executions above its sum plus the 100 that stay unreserved; new accounts may start lower and need a quota increase.

### The visibility timeout of the queues

Lambda refuses a mapping whose queue hides a message for less than the timeout of its function, and AWS recommends six times that timeout, plus the batching window, so a batch Lambda retries after a throttle is not received twice meanwhile. The consumers have a timeout of 60 s and no batching window: the queues of prod hide a message for 360 s, six times it. Those of the stage local keep the minimum, 60 s, while the emulator gives failed records back late: Floci 2.5.0 holds every record a function reports as failed for the visibility timeout of its queue (docs/limitations.md), so at 360 s each retry and each receive of an unreadable message would wait six minutes, and make cloud-check would no longer take a few minutes. `infra/cdk/test/functions.test.ts` ties the two numbers, read from the CDK app and from the template osls packaged: at least the timeout of the function in both stages, six times it in prod.

### Production, validated without deploying

`make cdk-check`, also a job of CI, runs without a network or credentials: osls packages both stages (the image named by a placeholder digest, `@sha256:0…0`, until the delivery pipeline of task 5 builds and pushes it to ECR, and the origin verifier built from TypeScript), the tests of the CDK app assert the template of the functions (every function has a timeout, memory, a concurrency limit and a role of its own from the stack compute; the only route is behind the origin verifier, a zip of Node.js outside the VPC; the mappings report failures per item; the scheduled functions retry nothing; every parameter it reads is one the CDK app publishes, the network and the database ones included in prod; each function has its identity of the database and an encrypted connection; it holds no IAM), include it in a stack of their own to run the same rules of cdk-nag as the CDK app (one exception: the origin verifier runs Node.js 24, the newest runtime the emulator runs, where the rule asks for the newest of AWS), synthesize the CDK app, and cfn-lint 1.60.1 checks every template (one exception, on the template of the functions: a DependsOn osls writes that a reference already implies).

## Decisions of the functions

- **A container image, not layers.** The layers of Bref are published from AWS accounts of Bref, which the emulator cannot mount, and an image is the same artifact in the emulator and in AWS: built once, by digest, with the exact PHP and extensions the build checks. It also lifts the limit of 250 MB of a zip with its layers. The cost: an image to keep in ECR, and a slower first cold start of each new image in AWS while Lambda caches it.
- **Fast path with a safety net.** Publishing after the commit keeps the chain as fast as with a relay that runs all the time, without one; the relay function keeps the guarantee of the outbox when a writer dies after its commit. The alternatives were a relay function once a minute alone (up to a minute per step) or a stream of the database (MySQL has none a function can subscribe to without another service).
- **The budget of connections.** Reserved concurrency per function, consistent with the mappings (at most as many invocations at once as the function may run), rather than one limit for the account: a burst of the API cannot take the connections of the chain, and the total fits the database.
- **What goes in the CDK app, what in the serverless service.** Each resource is defined once. Shared and stateful resources (queues, topic, buckets, tables, key, secret, the ECR repository, the deployments bucket) and every IAM role are in the CDK app, which cdk-nag checks; the functions and their triggers (API Gateway and its routes, the mappings, the schedules, the log groups of the functions) are in the serverless service, where osls packages an image function in one step. The service reads what it needs from the SSM parameters of the CDK app when CloudFormation deploys it, so nothing is copied between the two.
- **arm64.** Graviton is cheaper per second. It would take: the images of Bref for arm64 (`bref/arm-php-85`), a build of the image for `linux/arm64` (in CI with QEMU or an arm64 runner), `architecture: arm64` in the serverless service, and the emulator with `FLOCI_SERVICES_LAMBDA_HONOUR_ARCHITECTURES` on a host that runs arm64 containers. This machine and CI are x86_64, so the functions are x86_64.

## The network and the database of prod

The stacks `dian-einvoice-prod-network` and `dian-einvoice-prod-database` exist in the stage prod only, and are never deployed, not even to the emulator, which could not give meaningful evidence for them: the emulated cloud keeps the MySQL of compose and the network of compose. They are proven on what synthesis produces: `infra/cdk/test/network.test.ts` and `infra/cdk/test/database.test.ts` (the zones and tiers, no route out of the isolated subnets, the NAT gateways the stage asks for, a path to every AWS service of the adapters, the exact rules of every security group, the flow logs, a database that is not public, encrypted, protected against deletion, backed up and with more connections than the functions hold, and the same stage with every option turned the other way), cdk-nag, cfn-lint, and the schema validation of CDK.

```mermaid
flowchart TB
    internet(("Internet: the DIAN, webhook endpoints, AWS APIs"))
    lambda[["AWS Lambda: invokes the functions, polls the queues, pulls the image, ships the logs"]]
    subgraph vpc["VPC 10.40.0.0/16 (stack network)"]
        igw["Internet gateway"]
        subgraph zoneA["Zone us-east-1a"]
            publicA["Public 10.40.0.0/24<br/>NAT gateway, fixed address"]
            privateA["Private 10.40.4.0/22<br/>functions, rotation functions"]
            isolatedA["Isolated 10.40.12.0/24<br/>Aurora writer"]
        end
        subgraph zoneB["Zone us-east-1b"]
            publicB["Public 10.40.1.0/24<br/>a NAT gateway of its own with natGateways: 2"]
            privateB["Private 10.40.8.0/22<br/>functions, rotation functions"]
            isolatedB["Isolated 10.40.13.0/24<br/>Aurora reader, tier 1"]
        end
        gateways["Gateway endpoints: S3, DynamoDB"]
        interfaces["Interface endpoints, an option, off:<br/>SNS, SQS, KMS, Secrets Manager, SES"]
    end
    lambda -. "network interfaces of the functions" .-> privateA & privateB
    privateA & privateB -- "0.0.0.0/0, HTTPS" --> publicA
    publicA --> igw --> internet
    publicB --> igw
    privateA & privateB -- "S3, DynamoDB" --> gateways
    privateA & privateB -. "when on" .-> interfaces
    privateA & privateB -- "TLS, 3306" --> isolatedA
    isolatedA -. "shared storage, failover" .- isolatedB
```

- **Public subnets**: the NAT gateways only, the way out to the internet, each with an Elastic IP kept when the stack goes, so an allow list of the DIAN or of a customer that names it outlives the stack (`/dian-einvoice/prod/network/egress-addresses`). Nothing gets a public address of its own.
- **Private subnets with egress**: the network interfaces Lambda creates for the functions, those of the rotation functions of the secrets, and, when turned on, RDS Proxy and the interface endpoints. Their route to the internet goes through the NAT gateway; S3 and DynamoDB, through their gateway endpoints.
- **Isolated subnets**: the database. Their route tables have no route at all beyond the VPC: no internet gateway, no NAT gateway, no endpoint.
- **Flow logs** of the whole VPC, accepted and rejected traffic, to the log group `/dian-einvoice/prod/vpc-flow-logs`, kept a year, through a role only the flow logs of this account may assume.

### How a function in the VPC reaches each AWS service

The services the adapters call are listed by the backend (`backend/resources/aws/services.json`), which a backend test checks against the code (`tests/Unit/Support/AwsServicesTest.php`: the clients each adapter builds and the mailer); the network gives each one a path, and a test of the CDK app checks every path and this table. The emulator would not notice a function without a path to a service: in it every function reaches every service.

| Service | Endpoint | Called by | Path from the private subnets |
| --- | --- | --- | --- |
| Amazon DynamoDB | `dynamodb` | The cache, the web sessions and the rate limits (Laravel's DynamoDB store): every function | Gateway endpoint on the route tables of the private subnets, free, without the NAT gateway |
| Amazon S3 | `s3` | The files of electronic documents (`S3DocumentStorage`): api, chain, console | Gateway endpoint on the route tables of the private subnets, free, without the NAT gateway |
| AWS Secrets Manager | `secretsmanager` | The secrets of each cold start (`LoadSecrets`) and the connector of the database after a refusal: every function; also the rotation functions and RDS Proxy | NAT gateway, or its interface endpoint when `interfaceEndpoints` is on |
| Amazon SNS | `sns` | The outbox events (`SnsOutboxPublisher`): every function that writes, and the relay | NAT gateway, or its interface endpoint when `interfaceEndpoints` is on |
| Amazon SQS | `sqs` | The jobs the application dispatches to the chain queue, and the visibility of the messages a consumer fails (`SqsEventsQueue`): api, chain, webhooks, console | NAT gateway, or its interface endpoint when `interfaceEndpoints` is on |
| AWS KMS | `kms` | The key store of the signing certificates (`KmsKeyStore`): api, chain, console | NAT gateway, or its interface endpoint when `interfaceEndpoints` is on |
| Amazon SES (API v2) | `email` | Email (Laravel's mailer): chain, console | NAT gateway, or its interface endpoint when `interfaceEndpoints` is on |

Lambda itself needs no path from the VPC: it invokes the functions, polls the SQS queues of the mappings, pulls the image from ECR and takes the logs of stderr to CloudWatch Logs from outside it, and the functions get their credentials from their environment. The DIAN and the webhook endpoints are on the internet, through the NAT gateway.

### Security groups

Every group allows nothing it is not told to; `infra/cdk/test/network.test.ts` holds the rules of the stage to exactly these:

| Group | In | Out |
| --- | --- | --- |
| `dian-einvoice-prod-functions` (stack network) | nothing: Lambda invokes the functions from outside the VPC | TCP 443 to anywhere (the DIAN, webhook endpoints and the AWS APIs, through the NAT gateway or the endpoints), TCP 3306 to the database |
| `dian-einvoice-prod-database` (stack database) | TCP 3306 from the functions and from the rotation functions | nothing |
| `dian-einvoice-prod-database-rotation` | nothing | TCP 443 to anywhere (the Secrets Manager API), TCP 3306 to the database |
| `dian-einvoice-prod-database-proxy`, with RDS Proxy | TCP 3306 from the functions, which then reach the database through it only | TCP 3306 to the database, TCP 443 to anywhere (Secrets Manager) |
| `dian-einvoice-prod-endpoints`, with interface endpoints | TCP 443 from the functions, the rotation functions and RDS Proxy | nothing |
| The default group of the VPC | nothing (a custom resource of CDK removes its rules) | nothing |

Webhooks go out on port 443 only in production (the stage prod, where `WEBHOOK_REQUIRE_HTTPS` is on by default), as the group of the functions opens no other port toward the internet: a subscription to a URL with another scheme or port is refused when it is created or changed, with a 422 in `application/problem+json` whose `errors.url` gives the reason, and a subscription saved before is refused again before each attempt. The local stack and the emulated cloud keep their local receiver, on http and port 8602. Security groups do not filter DNS to the resolver of the VPC, which resolves the names of the endpoints and of the database. The rules toward the database are written in the stack database, which the network, deployed first, cannot depend on.

### The database

Aurora MySQL Serverless v2, version `8.4.mysql_aurora.8.4.8`, compatible with MySQL 8.4 as the MySQL of compose, in the isolated subnets and never public, with the writer in the first zone and a reader in the second one. Encrypted with a KMS key of its own (rotation on), protected against deletion, and kept with its key, its secrets and its log groups when the stack goes. Backups every day from 01:00 to 02:00 in Colombia (06:00-07:00 UTC), kept 35 days; maintenance on Sundays from 02:30 to 03:30 (07:30-08:30 UTC); minor versions change in code (`engineVersion`), not by themselves, so the template and the cluster never disagree. Its parameters require TLS 1.2 or 1.3 on every connection (`require_secure_transport`, the default of 8.4, written down), log queries slower than a second, and audit connections, privilege changes and schema changes, Aurora's own health checks left out; the error, slow query and audit logs go to log groups kept a year. The server certificate is signed by `rds-ca-rsa2048-g1`, one of the certificate authorities of the global bundle of RDS that the image of the functions carries (`/opt/rds/global-bundle.pem`, pinned by its SHA-256 in `backend/Dockerfile`), and the functions of prod verify it on every connection (`DB_SSL_CA`, `DB_SSL_VERIFY_SERVER_CERT`, `backend/config/database.php`).

It publishes what the functions read, under the names task 2 gave them:

| Parameter | Value |
| --- | --- |
| `/dian-einvoice/prod/network/private-subnet-ids` | The private subnets of the functions, separated by commas (stack network) |
| `/dian-einvoice/prod/network/functions-security-group-id` | The security group of the functions, which the database admits (stack network) |
| `/dian-einvoice/prod/network/egress-addresses` | The fixed public addresses of the way out, for the allow lists of the DIAN and of webhook endpoints (stack network) |
| `/dian-einvoice/prod/database/host` | The address of the writer, or of RDS Proxy in front of it |
| `/dian-einvoice/prod/database/port` | Its port |
| `/dian-einvoice/prod/database/name` | The database of the application |
| `/dian-einvoice/prod/database/secret-arn` | The secret of the application identity, rotated (both stages) |
| `/dian-einvoice/prod/database/migrations-secret-arn` | The secret of the migrations identity, rotated, for the console function only (both stages) |

### The two identities of the database

In both stages the application reaches the database as two identities, each with its secret in Secrets Manager, in the format of Amazon RDS (`username`, `password`, `dbname`, and in prod `host`, `port` and `engine` once the secret is attached to the cluster):

| | Migrations identity | Application identity |
| --- | --- | --- |
| Used by | the console function only (migrations and operations) | every other function: api, chain, webhooks, relay, scheduler |
| May | change the schema of the database of the application; create the application identity and grant it what it holds | `SELECT`, `INSERT`, `UPDATE` and `DELETE` on the database of the application, nothing of its schema, TLS required |
| Secret | `dian-einvoice/<stage>/database/migrations`; the console function reads it, and the secret of the application identity to provision it | `dian-einvoice/<stage>/database/application`; no other role reads the migrations one |
| In prod | the master user of the cluster (`dian_migrations`), rotated as a single user every 30 days | `dian_app` and `dian_app_clone`, rotated with alternating users every 30 days, the migrations identity as superuser |
| In the emulated cloud | `dian_cloud_migrations`, which `make cloud-up` creates from its secret as the MySQL root user, as Aurora creates its master user | `dian_cloud_app`, which `make cloud-check` rotates twice the way the rotation function would |

The application identity is created by one idempotent command, `php artisan db:application-identity`, which the console function runs with the migrations identity after the migrations, the same on the MySQL of compose (`make cloud-up`) and in prod: the user of the current version of the secret gets its password, TLS and exactly the four privileges; the user of the previous version, still valid until the next rotation, gets the same privileges and keeps its password; then the command connects as the identity and fails if it holds anything more. The first rotation of the application identity waits for its schedule, since the user does not exist before the first migrations.

**Rotation without stopping warm functions.** The rotation functions are those of AWS (the applications `SecretsManagerRDSMySQLRotationSingleUser` and `SecretsManagerRDSMySQLRotationMultiUser` of the Serverless Application Repository), in the private subnets with a security group of their own. A function reads its secret once per cold start, so a warm one may hold a password a rotation replaced since. Two things keep that from showing:

- **Alternating users** for the application identity: each rotation sets a new password on the user that is not current and makes it current; the credentials a warm function holds keep working until the rotation after next, 30 days later, far longer than an execution environment lives.
- **One more read, once**: when the database refuses the credentials of a connection whose credentials come from a secret (error 1045), the MySQL connector of the application (`App\Support\Database\RefreshingMySqlConnector`) reads that secret again through the Secrets Manager adapter, keeps the new credentials for the rest of the execution environment in `/tmp` and retries once. A refusal within 30 s of a read does not read again (Laravel reconnects once by itself on this error, and a password that keeps failing would otherwise read the secret on every connection). This covers the single-user rotation of the migrations identity, whose window between the change of the password and the change of the secret is seconds, and the application identity behind RDS Proxy, which does not support alternating users.

The secret of `APP_KEY` does not rotate: it encrypts stored values (the PINs and technical keys of the software, the webhook secrets) that a rotation would have to re-encrypt first (docs/limitations.md).

### Decisions of the network and the database

- **Two zones.** The fewest that keep the database and the functions running when one fails: Aurora needs a subnet group in two zones at least, and the reader lives in the other zone than the writer. A third zone would add subnets and, with one NAT gateway per zone, a third gateway, without a third copy of the database (Aurora keeps six copies of the storage across three zones anyway). The zones are a setting of the stage (`network.availabilityZones`), by name; each account maps names to zones of its own.
- **One NAT gateway, by default.** What leaves for the internet is asynchronous and retried: the transmission to the DIAN (its retries and the outbox), the webhooks (their retries) and the email (the delivery step). Losing the way out delays that work and loses none of it. One gateway costs about $33 a month ($0.045 an hour) plus $0.045 per GB; one per zone (`natGateways: 2`) doubles the fixed part to keep the way out when the zone of the gateway fails. The AWS APIs without a gateway endpoint also leave through it, and some calls to them happen inside a request: what that means when the gateway is lost is in the table of failures below, and the interface endpoints remove it.
- **Gateway endpoints for S3 and DynamoDB, interface endpoints off by default.** The gateway endpoints are free and keep the busiest services (the documents, the cache, the sessions and the rate limits) off the NAT gateway. An interface endpoint costs about $0.01 an hour in each zone plus $0.01 per GB: the five other services in two zones come to about $73 a month, more than the NAT gateway, which the DIAN and the webhooks need anyway. Turn them on (`interfaceEndpoints: true`) when the AWS traffic through the NAT gateway costs more than they do, when new execution environments must start and certificates must be uploaded while the NAT gateway is lost, or when a rule asks that calls to AWS never leave the network of AWS.
- **Capacity: 0.5 to 4 ACU.** The minimum is the lowest that keeps the instance running. Zero would let it pause after 5 minutes without connections, but the relay and scheduler functions connect every minute, so it would never pause, and resuming takes about 15 s (30 s or more after a day), half of the 28 s the API has. The maximum sets `max_connections`, from the table AWS documents (1 ACU: 90; 4 ACU: 135; 8 ACU: 1,000): 135 cover the 60 connections of the functions plus a margin of 20, and a test ties the three. The next step, 8 ACU, would give 1,000; `max_connections` is static, so a change of the maximum takes effect after the instances reboot. At $0.12 per ACU-hour (Aurora Standard, us-east-1), the minimum costs about $44 a month per instance, and an hour at 4 ACU about $0.48.
- **A reader in the other zone, on by default.** Without one, a failed writer is replaced by a new instance, which AWS says typically takes less than 10 minutes and may not succeed while the zone is impaired: the API and the chain would stop meanwhile. With a reader in promotion tier 1, which scales with the writer, a failover promotes a warm instance of the same size, typically within 30 seconds. It doubles the cost of the instances (about $44 a month more at the minimum), which an invoicing platform that SMEs depend on to sell is worth; a smaller deployment turns it off (`reader: false`). The application does not read from it.
- **RDS Proxy off, as an option (`proxy: true`).** The functions need 60 connections and the database offers 135, so there is nothing to pool yet. The proxy is billed for 8 ACU at least, whatever the database uses: about $0.015 per ACU-hour in us-east-1, about $88 a month, as much as both instances of the database at their minimum. It keeps connections open, so the instances never pause (moot here), and it does not support the alternating users strategy: behind it the application identity rotates as a single user, and the connector covers the seconds of the change. Turn it on when the reserved concurrency of the functions must outgrow `max_connections` (an API of more than about 95 environments at 4 ACU), when bursts of new connections at scale-out show as errors of too many connections, or to shorten failovers for the functions, whose connections it moves to the new writer.
- **Passwords, not IAM authentication.** Each identity authenticates with a password in its secret, rotated by AWS. IAM authentication would replace those passwords, not add to them (an Aurora MySQL user set up for IAM cannot use a password), would need a token for every connection the functions open, one per request, and AWS asks 300 to 1000 MiB of extra memory on the instance for it, most of the 1 GiB of the minimum capacity. cdk-nag rule RDS6, acknowledged with this reason.
- **The default port, 3306.** The port is not a secret: the functions read it from their environment, and only the groups of the functions, RDS Proxy and the rotation functions reach it, in isolated subnets without a route out. Another port would hide nothing from what can reach it and break the parity with the MySQL of compose. cdk-nag rule RDS11, acknowledged.
- **35 days of backups, no Backtrack.** A logical error, such as a migration or a bug that rewrites rows, may be noticed weeks later, at the monthly close of the books of a company or of its VAT and withholding returns: 35 days, the most Aurora keeps, cover a whole month and its close, and backup storage up to the size of the cluster costs nothing. Backtrack rewinds the whole cluster, every company at once: undoing the mistake of one company would undo the invoices the others issued since, already accepted by the DIAN. Recovery is a point-in-time restore into a new cluster, from which the rows affected are copied back (cdk-nag rule RDS14, acknowledged). The documents themselves are also kept in the documents bucket, which never expires them.
- **Two identities and their rotation**, as above: the migrations identity is the master user, as the only user Aurora creates is the one that can create the others; the application identity holds data privileges only, so a flaw in a function cannot change the schema or the privileges; 30 days between rotations, far longer than an execution environment lives and well inside what the rules of cdk-nag ask.

### When something fails

| What fails | What happens | How it recovers |
| --- | --- | --- |
| A zone | Lambda starts new environments in the private subnet of the other zone. If the database writer was there, Aurora promotes the reader (next row). If the NAT gateway was there (the default, one gateway in the first zone), the functions in the other zone lose the way out too (last row); with `natGateways: 2` they keep their own | By itself when the zone returns: Lambda uses both subnets again, and Aurora replaces the lost instance as a reader |
| The writer of the database, failed over to the reader | The cluster endpoint moves to the promoted reader, typically within 30 seconds. The connections open at that moment drop: those requests answer an error and those jobs fail and go back to their queue | The functions open a new connection for every request and every batch, so the next ones reach the new writer; Laravel reconnects once when it finds a connection lost; the failed jobs run again after their backoff, and every handler is idempotent. Aurora recreates the lost instance as the new reader |
| The way out to the internet (the NAT gateway, or the zone it lives in) | The DIAN, the webhook endpoints and SES are unreachable: transmissions, webhooks and emails fail and wait for their retries. The outbox events stay unpublished in their table (SNS is unreachable), so the chain pauses after its current step. S3, DynamoDB and the database keep working. Without interface endpoints, also: new execution environments cannot read their secrets and fail to start, while warm ones keep serving; uploading a certificate (KMS) and sending an invitation or a password reset (SQS) answer an error; a failed job goes back to its queue after the visibility timeout instead of its backoff | By itself when the way out returns: the relay function publishes the events left behind within a minute, the retries of the DIAN and the webhooks run, and SQS gives back the failed jobs. Nothing is lost: every pending step is a row of the outbox or a message of a queue. The interface endpoints keep the AWS APIs reachable meanwhile |
| A rotation of a secret, with functions warm | Nothing visible: warm functions keep the old credentials of the application identity until the rotation after next; when the database refuses a password, the connector reads the secret again and retries once | By itself. A rotation that fails is retried by Secrets Manager in its next window; the old credentials keep working meanwhile |
| Secrets Manager, unreachable | New execution environments cannot start; warm ones keep serving with what they read; the rotation functions fail and retry in their next window | By itself when it returns |

## The edge

One CloudFront distribution (stack `dian-einvoice-<stage>-edge`) serves the web app and the API under one domain: the build of the web app from a private bucket, and the paths of the API from the HTTP API of API Gateway of the functions, which answers only what came through the distribution (the origin verifier). Both stages have it; the emulator serves its distribution and applies most of it (below), and prod adds a domain of its own with TLS 1.2 at least and a firewall.

```mermaid
sequenceDiagram
    autonumber
    participant Browser as Browser (web app)
    participant Client as API client (API key)
    participant WAF as AWS WAF (prod)
    participant CF as CloudFront distribution
    participant Fn as CloudFront Function (routes of the web app)
    participant S3 as Bucket of the web app
    participant APIGW as API Gateway (HTTP API)
    participant Verifier as Origin verifier (Lambda, Node.js)
    participant API as Function api (PHP)

    Browser->>WAF: GET /invoices/01J... over HTTPS (HTTP/2 or HTTP/3)
    WAF->>CF: managed rules and limits per address passed
    CF->>Fn: viewer request of the default behaviour
    Fn-->>CF: /index.html: a path without an extension is a route of the web app
    CF->>S3: GET /index.html, signed with origin access control
    S3-->>CF: index.html, Cache-Control no-cache
    CF-->>Browser: index.html with the security headers of the web app
    Browser->>CF: GET /assets/index-1a2b3c4d.js (cached a year, immutable)
    Browser->>WAF: POST /api/v1/invoices with the session cookie and X-XSRF-TOKEN
    WAF->>CF: passed
    CF->>APIGW: uncached, every header but Host, every cookie and query, X-Forwarded-For with the address of the client appended, and X-Origin-Verify with the secret of the edge
    APIGW->>Verifier: X-Origin-Verify, once per value and 5 minutes
    Verifier-->>APIGW: isAuthorized: the current or the previous value of the secret
    APIGW->>API: event of payload 2.0
    API-->>Browser: the answer with the headers of the application, through CloudFront
    Client->>WAF: GET /api/v1/invoices with Authorization: Bearer dei_...
    Note over Client,API: the same way as the POST of the web app
    Client-xAPIGW: straight to API Gateway: 401 without X-Origin-Verify, 403 with a wrong value, never the application
```

| Behaviour | Origin | Cache | To the origin | Headers of the answer | Function |
| --- | --- | --- | --- | --- | --- |
| `/api/*`, `/sanctum/*`, `/docs/*` (`backend/resources/http/paths.json`) | API Gateway of the functions, HTTPS with TLS 1.2, the secret of the edge in `X-Origin-Verify` | none (managed policy CachingDisabled) | every method; every header but Host, every cookie and query string, and the headers of CloudFront (managed policy AllViewerExceptHostHeader); `X-Forwarded-For` with the address of the client appended | those of the application, untouched | none |
| `/assets/*` | the bucket, origin access control | the `Cache-Control` of each file: a year, immutable | GET and HEAD, nothing of the request | the security headers of the web app | none |
| default (`*`) | the bucket | the `Cache-Control` of each file: `no-cache` for `index.html` | GET and HEAD | the security headers of the web app | `panel-routes`, viewer request |

- **The web app.** A bucket per stage (`dian-einvoice-<stage>-panel-<account>-<region>`), private (every public access blocked, objects owned by the bucket), encrypted, TLS only, its server access logs in the bucket of the logs of the edge, and versioned: an upload keeps the version it replaced for 7 days locally and 90 in prod. Only the distribution reads it: origin access control, with a bucket policy that admits CloudFront for this distribution only (`AWS:SourceArn`), to read and to list, so a file that does not exist answers 404 rather than 403. `infra/functions/scripts/panel.mjs` uploads the build of Vite with the type of each file and its cache: the files under `assets/` carry the hash of their content in their names (the script refuses one that does not) and are cached a year, immutable; `index.html` and every other file get `no-cache`, so a new build shows at once. The fingerprinted files go first and `index.html` last, so no page names a file not uploaded yet; files of earlier builds go, except the fingerprinted ones of the last week, which a tab still open may load. In prod it then invalidates the files that are not fingerprinted; `panel.mjs rollback` puts back the previous version of each of them, `index.html` included, whose fingerprinted files are still in the bucket.
- **The API.** The paths of the application (`backend/resources/http/paths.json`) are behaviours of their own, so nothing of the API reaches the bucket: a backend test holds every route of the application to one of them (`tests/Integration/Security/EdgeTest.php`), the development tools of Scramble aside, which the functions turn off. API Gateway answers by its own name, so the Host of the viewer stays behind.
- **The routes of the web app.** The CloudFront Function `panel-routes` (`infra/cdk/edge/panel-routes.js`, runtime cloudfront-js-2.0), on the viewer request of the default behaviour only, rewrites a path whose last segment has no extension to `/index.html`, whose router shows the route; a file keeps its path. Its cases (`infra/cdk/edge/panel-routes.cases.json`) are tested on the function (`infra/cdk/test/edge.test.ts`) and, in the emulated cloud, on the proxy that stands in for it (`make cloud-check`).
- **Transport.** HTTPS only: the web app redirects plain HTTP, the API refuses it (a redirect would turn a POST into a GET). HTTP/2 and HTTP/3, IPv4 and IPv6. With a domain of its own (prod), the certificate of ACM and the security policy `TLSv1.2_2021`, SNI only; without one, the default certificate of CloudFront. Compression (gzip and Brotli) for the web app; none for the API, whose answers are not cached.
- **Logs.** The standard access logs of CloudFront, every request without its cookies, which carry the session, in the bucket `dian-einvoice-<stage>-edge-logs-<account>-<region>` (prefix `cloudfront/`), kept 30 days locally and a year in prod, with the server access logs of the bucket of the web app (prefix `panel/`).
- **The domain**, a parameter of the deployment, never a lookup in an account: `cdk deploy -c edgeDomainName=app.example.com -c edgeCertificateArn=arn:aws:acm:us-east-1:...` (CloudFront takes certificates of us-east-1 only); without them prod uses the placeholders of `infra/cdk/lib/stage.ts`, and an empty `edgeDomainName` the name CloudFront gives the distribution. The record that points the domain at the distribution belongs to whoever holds the domain (docs/limitations.md). The tests synthesize prod with and without a domain.
- **Price class: all edge locations.** The users are Colombian businesses and their customers, and the edge locations of South America (Bogotá among them) are only in the class of every location; the cheaper classes would serve Colombia from North America, with more latency on every request of the API and every page. Its data transfer costs more per GB in South America than in North America, which a web app built once and cached, and answers of a few KB, keep small (task 5 estimates it).

### The security headers

One file, `backend/resources/http/security-headers.json`, holds every security header of the platform, and every one of its consumers is held to it by a test: the backend sets those of its own answers from it (`App\Http\Middleware\SecurityHeaders`; `ErrorsAndHeadersTest`), the CDK app builds the response headers policy of the web app from it (`infra/cdk/lib/edge-stack.ts`; `edge.test.ts`), and `make cloud-check` reads them back through the edge. The edge adds nothing to the answers of the API, so they carry the headers of the application, neither duplicated nor contradicted.

| Header | Web app (the edge) | API (the application) |
| --- | --- | --- |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | the same, in production |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `X-Frame-Options` | `DENY` | `DENY` |
| `Referrer-Policy` | `same-origin` | `no-referrer` |
| `Permissions-Policy` | accelerometer, camera, geolocation, gyroscope, magnetometer, microphone, payment and usb denied | — |
| `Content-Security-Policy` | the policy below | data and problems `default-src 'none'; frame-ancestors 'none'`; files `frame-ancestors 'none'`; the API reference a policy of its own |

The policy of the web app, directive by directive, with the reason of each source beyond its own origin (the file keeps them next to the values):

| Directive | Sources | Why |
| --- | --- | --- |
| `default-src` | `'none'` | Nothing is allowed that a directive below does not name. |
| `script-src` | `'self'` and the hash of the inline script of `index.html` | The scripts are files of the build. The inline script applies the saved theme before the first paint, so a page in dark mode never flashes white; it is allowed by its hash, which a test computes from `frontend/index.html`, never by `'unsafe-inline'` or `'unsafe-eval'`. |
| `style-src` | `'self'` and four hashes | The styles are files of the build or properties set from script, which the policy does not govern. The hashes admit fixed style elements of libraries, by their exact text: the empty one the charts (Unovis) fill through the CSS object model, the ones Reka UI renders with the lists of a combobox and of a select (they hide the scrollbar), and the one VueUse adds for an instant while the theme changes. No `'unsafe-inline'`: the charts set their colours as variables of their container and the swatches of their hover cards as classes. |
| `img-src`, `font-src`, `connect-src` | `'self'` | Images, the Inter font and every call of the API come from the same origin. |
| `frame-src` | `blob:` | The PDF of an invoice is shown in a frame from an object URL of the file the API returned; only a script of the origin can create one. The PDF viewer of Chromium runs in that frame under this policy (`make cloud-e2e`). |
| `object-src`, `base-uri` | `'none'` | No plugins, and no `<base>` to redirect relative URLs. |
| `form-action` | `'self'` | Forms are sent by script; one a failed script left to the browser still reaches only this origin. |
| `frame-ancestors` | `'none'` | Never framed, with `X-Frame-Options` for older browsers. |

No `upgrade-insecure-requests`: every URL is relative to an origin that is HTTPS only with HSTS, so there is nothing to upgrade, and the emulated cloud serves plain HTTP on localhost. No `require-trusted-types-for`: Vue inserts static parts of its templates as markup, and Unovis its hover cards. No reporting endpoint yet (observability is task 5); `make cloud-e2e` fails on the first violation the browser reports.

**The API reference** (`/docs/api`, the page of Scramble with Stoplight Elements) shares the origin of the session behind the edge, so it has a policy of its own, which the application sets (`App\Support\ApiDocs\SecureApiReference`): `script-src` names the exact file of Stoplight Elements 8.4.2 on unpkg.com, and its two files get Subresource Integrity (`sha384`, pinned in the same file), so the browser runs those exact bytes or nothing; the inline scripts of the page (the interceptor of Scramble that sends the CSRF token, and the document of the API, which changes with it) are allowed by the hashes the middleware computes for each answer, never by `'unsafe-inline'` or `'unsafe-eval'`; styles may be inline (Stoplight Elements styles its components at run time, and styles cannot run code); `connect-src 'self'` for Try it; `frame-ancestors`, `base-uri` and `object-src` `'none'`. The alternative, serving those files from the domain, needs a copy of the page of Scramble, a view the backend does not keep.

### The firewall (prod)

A web ACL of AWS WAF (scope CloudFront) on the distribution, allowing by default:

| Priority | Rule | Action | Why |
| --- | --- | --- | --- |
| 0 | AWS managed: Amazon IP reputation list | block | Addresses AWS has seen in attacks and bot networks. |
| 1 | AWS managed: core rule set | block, but `SizeRestrictions_BODY` and `NoUserAgent_HEADER` count | The common exploits of web applications. A body over 8 KB is legitimate here: an invoice with many lines, the signing certificate (up to 100 KB, in a multipart form); the application limits its bodies itself. Several HTTP libraries send no User-Agent by default, so an integration would be blocked for it. |
| 2 | AWS managed: known bad inputs | block | Payloads known to exploit software (Log4j and the like). |
| 3 | sign-in points per address | 429 above 100 requests in 5 minutes | `POST` on the sign-in, sign-up, password reset and invitation points (`authentication` of `paths.json`, which a backend test holds to throttled POST routes): a flood of guesses stops at the edge before it reaches a function; the application limits them more finely behind it (five failures a minute per email and address). |
| 4 | every request per address | 429 above 3,000 requests in 5 minutes | A ceiling against floods, well above what the application allows an API key (120 a minute), so a platform that integrates several companies from one address fits. |

The limits count the address CloudFront sees, which no header changes. Both answer 429 with `Retry-After` and a JSON problem. The body of a request is inspected up to the 16 KB of CloudFront; the rules on it keep their own handling of larger bodies. A web ACL costs a monthly fee per web ACL and per rule plus a fee per million requests (task 5 estimates it); the managed groups of AWS used here have no fee of their own.

### The origin verifier

The distribution adds the secret of the edge, `dian-einvoice/<stage>/edge/origin-verify` (64 letters and digits Secrets Manager generates), to every request it sends to API Gateway, in `X-Origin-Verify`; CloudFront replaces a header of that name a viewer sends. API Gateway answers 401 itself to a request without it; otherwise it calls the origin verifier, `infra/functions/origin-verifier` (TypeScript on Node.js 24, packaged by osls as a zip of its build, apart from the image of PHP, defined in the serverless service with the other functions), and keeps its answer for each value of the header 5 minutes. The verifier compares the value in constant time (both reduced to their SHA-256 first, compared with `timingSafeEqual`) with the current and with the previous version of the secret, which it keeps 30 s in memory and reads again sooner, at most every 5 s, when a request brings a value it does not hold; when Secrets Manager does not answer it keeps the values it holds. A wrong value gets 403, before the application. Its role may read that secret and write its logs, nothing else; it runs outside the VPC and touches no database.

The value reaches the distribution as a parameter of the deployment in the stage local (`OriginVerifyValue`, `NoEcho`, which `make cloud-up` reads from the secret and pipes to the CDK CLI), since the emulator expands dynamic references to Secrets Manager only in the credentials of RDS; in prod as a dynamic reference that CloudFormation resolves (`{{resolve:secretsmanager:...}}`), pinned to a version by the parameter `OriginVerifyVersion` (empty: the current one). Anyone who may read the configuration of the distribution sees the value (CloudFront keeps custom headers as they are), so reading the distribution belongs with the operators.

**Rotation of the secret of the edge, without a cut**, because the verifier accepts the current and the previous version:

1. Put a new value as the current version: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/edge/origin-verify --secret-string "$(openssl rand -hex 32)"`. The value it replaces becomes the previous one, still accepted. Note its version id (`VersionId` of the answer).
2. Wait 30 s, the time the verifier keeps the values, and longer than the edge takes to update anyway; a value it does not hold reads the secret again at once.
3. Deploy the edge with that version: `cdk deploy prod/edge --parameters OriginVerifyVersion=<version id>`. CloudFormation resolves the new value and CloudFront sends it as its edge locations take the new configuration; meanwhile the others still send the previous value, which is valid.
4. Wait until the distribution is deployed (`aws cloudfront wait distribution-deployed --id <id>`) and 5 more minutes, the cache of the authorizer, before any other rotation: a second rotation drops the first value, so it must not happen while an edge location still sends it.

A value dropped by a rotation may still pass for up to the 5 minutes of the cache of the authorizer after it, when a request brought it just before.

**mTLS to the origin, not adopted now.** Since January 2026 CloudFront can present a client certificate to its origin, API Gateway included, so the API would accept only connections that prove they come from the distribution, with no shared value in a header. It needs a domain of its own for the API (API Gateway checks client certificates on custom domains only, with a trust store in S3), a certificate authority with its life cycle (AWS Private CA, which costs a monthly fee per authority, or one of our own whose certificates are imported in ACM and renewed before they expire), and the emulator cannot exercise any of it. The secret header gives the same guarantee against a client that skips the edge, rotates without a cut, and runs here end to end. mTLS replaces it when the API has its own domain and the operators a CA to run.

### The application behind the edge

- **One origin.** The web app and the API share the domain, so the session cookie and the `XSRF-TOKEN` cookie of Sanctum are host-only cookies of that origin (`SESSION_DOMAIN` unset), `Secure` in prod, `SameSite=Lax`, and the stateful domain of Sanctum is that origin (`SANCTUM_STATEFUL_DOMAINS`: `localhost:8601` in the emulated cloud, the domain in prod). The web app sends `Referer` on its own origin (`Referrer-Policy: same-origin`), which Sanctum reads to recognize it. CORS is not offered for the CSRF cookie, which only the web app on its own origin asks for; the API under `/api/v1` keeps answering any origin without credentials, so a page of another origin can call it with an API key, never with the session.
- **The address of the client** is the entry the edge appended to `X-Forwarded-For`, for the rate limits. AWS documents that CloudFront appends the address of the connection of the client to the `X-Forwarded-For` it received, whatever the client wrote there, and that API Gateway appends the address of the edge location after it; it does not document what CloudFront does with a `CloudFront-Viewer-Address` a viewer sends, so the application does not read that header. The application does not count the entries the hops after the edge append, which only a real account could confirm: it drops the last entry only when it is the peer API Gateway reports for the connection it received (the source address of the request context of the event, `requestContext.http.sourceIp`, which Bref passes to PHP-FPM in `LAMBDA_REQUEST_CONTEXT`, a server variable no header can set), comparing both as normalized addresses (IPv6 in one notation, IPv4 mapped into IPv6 as IPv4), and takes the last entry left. When API Gateway appends the address of its peer, the edge location, that entry goes and the one before it, CloudFront's, remains; when it appends nothing, the last entry already is CloudFront's and stays, since the client's connection is never the edge location's. Everything before it is the client's to write and counts for nothing, even an entry that names the edge location. Without a peer, without an entry left, or with one that is not an address, no forwarded address is believed at all and the request counts as the runtime, which makes a limit stricter, never looser; so does an API Gateway that appended an entry other than its peer, which would make every client of an edge location share one. The same rule and the same settings in every stage: the API Gateway of the emulator appends nothing after its CloudFront and reports 127.0.0.1 as the peer of every request, which no entry its CloudFront appends is, so the emulated cloud runs the rule of prod as it is, in its case without an entry of API Gateway. The backend tests both cases, with and without the entry of API Gateway, the addresses a client writes in either header and the spellings of an address; `make cloud-check` sends a false `X-Forwarded-For`, a false `CloudFront-Viewer-Address`, both, and an `X-Forwarded-For` that ends with the peer the API Gateway of the emulator reports, and sees the limit of the sign-in count only the address the edge saw.
- **Generated URLs** take `APP_URL`, the public origin (`App\Http\PublicOrigin`): behind the edge the application sees the host of API Gateway, which refuses whoever does not come through the edge, so the links of the pages of a list and the servers of the API reference would lead nowhere otherwise.
- **The web app** of a production build names the API by its own origin (`frontend/src/config/product.ts`); the development server of the local stack keeps the address of the API, 8600.

### The edge in the emulated cloud

`make cloud-up` deploys every stack of the CDK app but the edge, then the functions with osls (which publish the id of their HTTP API in `/dian-einvoice/local/functions/http-api-id`), then the stack edge with the value of the secret as its parameter, builds the web app for production (`pnpm build`) and uploads it with `panel.mjs`, the script of every stage. Every request then goes through the distribution of the emulator: its behaviours, the response headers policy of the web app, the secret of the edge, API Gateway with the origin verifier (its first call in the emulator pulls the image of the Node.js runtime of Lambda), and the bucket. Two things the emulator does not do are done next to it:

- **The CloudFormation of Floci 2.5.0 drops part of each behaviour** (the methods it allows and caches, its cache and origin request policies and its functions), so the API would get no query strings, cookies or writes. `infra/functions/scripts/edge.mjs reconcile` gives the distribution those settings as the deployed template declares them, through the CloudFront API of the emulator; it creates nothing, changes nothing the template does not say, and refuses to run against anything but the emulator. `make cloud-check` checks that the distribution holds the template.
- **The emulator serves a distribution by its host name and runs no CloudFront Function.** `cloud-gateway` (nginx, `infra/functions/gateway.conf.template`) gives each request that host name and rewrites the routes of the web app to `/index.html` as the function does, with the same cases; it adds no header of its own, so the distribution sees the client as CloudFront would. 8601 serves the web app and the API on one origin; 8600, the same edge.

`make cloud-check` runs the edge as a browser and a client would (step 3): API Gateway refuses a call without the secret and one with a wrong value, the API answers through the edge with the headers of the application, the web app comes from the bucket with every header of the file and its files cached a year, a deep link answers `index.html` (the cases of the function), a 404 and a 401 of the API stay problems of the API; and, in the console function, the sign-in with the session cookie and CSRF on the origin of the web app, a 403 that stays a problem of the API, the links of a list that name the public origin, and an address the client writes that the limits do not count. `make cloud-e2e` runs in Chromium against 8601, on the production build under its policy: the journey of `make e2e`, the API reference, and a tour of the whole web app (`e2e/tests/tour.spec.ts`): every route the router declares, read from the running app, each loaded as a deep link with the demo data, with its main dialogs, menus and lists, the command palette, every theme and the hover card of a chart. It fails on the first violation of a policy the browser reports, in any frame.

### Decisions of the edge

- **A secret header, not mTLS, for the origin**, for the reasons above.
- **A CloudFront Function, not custom error responses, for the routes of the web app.** Custom error responses apply to the whole distribution: answering 403 and 404 with `index.html` would also turn the 403 and 404 of the API (problems that clients read) into a page of the web app. The function runs on the default behaviour only, on the request, and costs a fraction of a cent per million requests.
- **One distribution for both**, not two domains: the web app needs the API on its own origin for the session cookie, CSRF and CORS to stay simple; API clients use the same domain.
- **Managed policies for the API** (CachingDisabled, AllViewerExceptHostHeader): the ones AWS documents for API Gateway origins; a policy of our own would only copy them. No response headers policy on the API: the application already sets its headers, and two sources would drift.
- **The cache of the web app follows each file** (`Cache-Control` set by the upload), with a minimum of 0: a file without one is never cached, `no-cache` is revalidated on every request (an `ETag` answer of a few bytes), and the fingerprinted files live a year in the edge and in the browser.
- **The standard (legacy) access logs to S3**, not the logs of the new log delivery: the emulator creates no `AWS::Logs::Delivery` resources, and one definition serves both stages; CloudFront writes them with ACLs, so their bucket allows ACLs (owner preferred), which no other bucket of the platform does.
- **Rotation by procedure, not by a rotation function**: the new value must reach the distribution, which CloudFormation owns; a function that rotated the secret and updated the distribution behind CloudFormation would make the template lie.

## Each piece, locally and in AWS

The application reaches AWS only through its ports; `backend/config/adapters.php` and Laravel's own settings choose the adapter.

| Piece | Local stack | AWS (emulated cloud, production) |
| --- | --- | --- |
| Web app | the development server of Vite, which forwards `/api` and `/sanctum` to the API | the production build in the bucket of the edge, served by the CloudFront distribution with its security headers; the API on the same origin |
| Edge | none | the CloudFront distribution (stack edge), AWS WAF in prod; in the emulated cloud the proxy `cloud-gateway` in front of it for the host name and the routes of the web app |
| HTTP API | `api` (FrankenPHP) | API Gateway HTTP API behind the origin verifier (the function `origin-verifier`, Node.js), and the function `api` (PHP-FPM of Bref) |
| Events between the steps of the chain | Outbox relay → Redis queue | The writer after its commit (fast path), and the function `relay` once a minute → SNS topic of document events (`SnsOutboxPublisher`) |
| Jobs of the electronic document chain and the delivery | Redis queue, `worker` ×2 | SQS queue `chain`, the function `chain` (event source mapping, partial batch response) |
| Webhook events and deliveries | the same Redis queue | SQS queue `webhooks`, the function `webhooks` |
| Jobs the application dispatches itself (invitations, password resets) | the same Redis queue | SQS queue `chain` |
| Retries with backoff | delayed Redis jobs | visibility timeout of the message (ChangeMessageVisibility) |
| Retries scheduled for later (transmission, webhooks) | delayed Redis jobs | the message stays invisible in its queue until it is due |
| Dead letters | table `failed_jobs` | dead-letter queue of each queue, by the redrive policy; `failed_jobs` still records the failures |
| Sweepers and pruning | the relay, every half minute | the Laravel scheduler in the function `scheduler`, every minute |
| Migrations and operation commands | `php artisan` in `api` | the function `console` |
| Files of electronic documents | private disk `storage/app/documents` | S3 bucket (`S3DocumentStorage`) |
| Email | SMTP to the local mailbox (Mailpit) | SES, v2 API (the emulator keeps every email, at `/_aws/ses`) |
| Cache | Redis | DynamoDB table `cache` |
| Web sessions | Redis | DynamoDB table `sessions` |
| Rate limits (sign-in, API keys...) | Redis | DynamoDB table `limits` |
| Signing certificates | `key_store_entries`, encrypted with `APP_KEY` | `kms_key_store_entries`: each one encrypted with a data key of its own that the KMS key protects (`KmsKeyStore`) |
| Secrets of the application (`APP_KEY`) | `backend/.env` | Secrets Manager, read once per execution environment (`LoadSecrets` and the configuration cache) |
| Secret of the edge | — | Secrets Manager: the distribution sends it to API Gateway, the origin verifier checks it |
| Credentials of the database | `backend/.env`, one user | the secret of the identity of each function in Secrets Manager (migrations for the console, application for the rest), read once per execution environment and again when the database refuses them (`RefreshingMySqlConnector`) |
| Names of the resources, for other stacks | — | SSM parameters `/dian-einvoice/<stage>/...` |
| Health checks | database and Redis | database, cache store and queue |
| Database | MySQL in compose | emulated cloud: MySQL in compose, its own database and two identities over TLS; prod: Aurora MySQL Serverless v2 in the isolated subnets, TLS with the certificate verified |
| Network | the network of compose | emulated cloud: the network of compose; prod: the VPC of the stack network, the functions in its private subnets |
| DIAN | local simulator | local simulator |

## What the emulator does not do

It does not evaluate IAM policies, resource policies (TLS only, the topic as the only sender of the queues) or key policies, does not apply the encryption key of the topic, the server access logs of the buckets or the storage classes of the lifecycle rules, delivers no email, does not freeze a function after it answers, runs at most one invocation at a time per mapping of a queue, and (release 2.5.0) gives back a record a function reported as failed after the visibility timeout of its queue rather than after the wait the function set. It cannot run the rotation functions of AWS, which `make cloud-check` stands in for, step by step, and it is given neither the network nor the database of prod, which are never deployed. At the edge it applies no AWS WAF (prod only), caches and compresses nothing, runs no CloudFront Function (the proxy in front of it rewrites the routes of the web app), serves plain HTTP whatever the viewer protocol policy says, writes no access logs, and its CloudFormation drops the methods, policies and functions of each behaviour, which `make cloud-up` gives back to the distribution from its template. Those parts are checked on the synthesized and packaged templates (the tests of the CDK app, cdk-nag and cfn-lint) only. The details are in [limitations.md](limitations.md).
