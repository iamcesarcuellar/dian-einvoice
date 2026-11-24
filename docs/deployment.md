# Deployment

**English** · [Español](es/deployment.md)

This guide covers deploying the platform to a real AWS account. The deployment is written as code and validated offline, but it has never run: nothing in this repository has been deployed to an account (docs/limitations.md).

The emulated cloud runs the same script, with the same steps, against the local emulator (`make cloud-up`). `make cdk-check` synthesizes, packages and checks the prod stage.

The sections below cover what an account needs, the order of a first deployment, a routine deployment, going back, tearing it down, and what has never been verified.

## One script for every stage

`scripts/deploy.sh <local|prod> <step>` deploys a stage. `make cloud-up` runs its steps against the emulator, and the `.github/workflows/deploy.yml` workflow runs the same steps against an account.

Every tool runs in a container from `compose.yaml`:

- `cdk-deploy` and `functions-deploy` reach the emulator.
- `cdk-release` and `functions-release` (profile `release`) reach an account, with the credentials of the role the workflow assumed.

| Step | `make cloud-up` (local stage) | The workflow (prod stage) |
| --- | --- | --- |
| `stacks` | EMULATOR ONLY: `cdk bootstrap` on every run, since the emulator may be new. Then `cdk deploy` of messaging, storage, data, security, mail, alerts and compute. | `cdk deploy` of the same stacks, plus network and database. The account was bootstrapped once, by hand. |
| `image` | Builds the `lambda` target of `backend/Dockerfile`. EMULATOR ONLY: pushes it to the registry behind the emulator's ECR, through its published port. | Builds it the same way, then pushes it to the account's ECR repository under its commit's tag. That tag never moves: a commit pushed before reuses its image. |
| `migrate` | EMULATOR ONLY: creates the emulated cloud's database and its migrations identity on the Docker Compose MySQL (in AWS, Aurora creates its master user from the secret). Then the same as prod. | `osls deploy` puts the new image on the console function only (`consoleImage`), and the other functions stay on the image they run. Then the console function runs `php artisan migrate` and `php artisan db:application-identity`. |
| `functions` | `osls deploy` puts every function on the new image, by digest, with the DIAN simulator's admin token as a parameter (a development tool). | The same, with the account's parameters: its id, the domain (`appUrl`, `statefulDomains`) and the reserved concurrency. |
| `edge` | `cdk deploy` of the edge stack, with the edge secret's value piped in as a parameter. EMULATOR ONLY: then `edge.mjs reconcile` applies the behavior settings that the emulator's CloudFormation drops. | `cdk deploy` of the edge stack. CloudFormation resolves the edge secret from Secrets Manager, and `ORIGIN_VERIFY_VERSION` can pin a version. |
| `observability` | `cdk deploy` of the observability stack alone. EMULATOR ONLY: then `observability.mjs reconcile` adds the metrics of the metric-math alarms and the saved queries. | `cdk deploy` of the observability stack alone. |
| `panel` | `pnpm build`, then `panel.mjs upload`. The emulator caches nothing, so there is no invalidation. | The same, plus an invalidation of the files that are not fingerprinted. |
| `gateway` | EMULATOR ONLY: the proxy in front of the distribution, which the emulator serves by host name. | None: the domain points at the distribution. |
| `smoke` | Checks the API's health through the edge (the proxy), and that API Gateway refuses a call without a secret and a call with a made-up one. | The same, through the domain and API Gateway's public address. |
| `versions`, `rollback-image`, `rollback-panel`, `diff` | `make cloud-deploy STEP=<step>` | By hand: `sh scripts/deploy.sh prod <step>`, with an operator's credentials. `diff` and `versions` are also workflow steps. |

In both stages, the `image` step prints the image as `<registry>/dian-einvoice-<stage>-functions@sha256:<digest>`, and every function is deployed by that digest.

The steps that differ exist only because the emulator is not AWS. The script marks each one `EMULATOR ONLY` and says what AWS does instead.

## What an account needs

### Lambda concurrency

The functions reserve 72 concurrent executions in total: api 40, chain 10, webhooks 5, relay 1, scheduler 2, console 2, origin verifier 10 and notifier 2. The application's functions hold one database connection each: 60 at most, within the 135 connections the database accepts at 4 ACU.

Lambda lets an account reserve at most its unreserved concurrency minus 100, and keeps those 100 for the functions that reserve nothing. So the account's quota of concurrent executions must be at least 72 + 100 = **172**. The default quota is 1,000 per region, but new accounts start with reduced quotas, which AWS raises with use. Reserving concurrency costs nothing.

1. Read the quota with `aws service-quotas get-service-quota --service-code lambda --quota-code L-B99A9384 --query Quota.Value`, and the unreserved concurrency with `aws lambda get-account-settings --query AccountLimit`.
2. If the quota is below 172, ask for more: `aws service-quotas request-service-quota-increase --service-code lambda --quota-code L-B99A9384 --desired-value 1000`.
3. Until AWS raises it, deploy without reserved concurrency: set the workflow's `reserved-concurrency` input to `unreserved`. It becomes the serverless service's `--param=reservedConcurrency=unreserved`, which leaves `ReservedConcurrentExecutions` out of every function.

   The queue mappings still cap the chain at 10 and the webhooks at 5, and the account's quota caps all the functions together. With reserved concurrency, the application's functions hold at most 60 connections, one per execution environment, well within the database's 135 and its margin of 20 for the operators and the rotation functions. Without it, a quota below about 115 (135 less that margin) keeps the functions within those connections.

   Deploy with `reserved` again once the quota allows it. Reserved concurrency is what keeps a burst on the API from taking the chain's connections.

### Email: SES out of its sandbox, and the domain's records

In a new account, SES runs in its sandbox: it sends only to verified addresses, and only 200 emails a day. The mail stack verifies the platform's domain with Easy DKIM and a MAIL FROM subdomain (`mail.<domain>`).

1. Ask for production access with `aws sesv2 put-account-details --production-access-enabled --mail-type TRANSACTIONAL --website-url https://<domain> --use-case-description "Electronic invoices and notes, sent to the customers of each company once the DIAN validates them" --contact-language EN`. Check it with `aws sesv2 get-account --query "[ProductionAccessEnabled,SendQuota]"`.
2. After the first deployment, publish the records the identity asks for in the domain's zone:
   - the three DKIM CNAME records, `<token>._domainkey.<domain>` to `<token>.dkim.amazonses.com`, one for each token that `aws sesv2 get-email-identity --email-identity <domain> --query DkimAttributes.Tokens` returns;
   - the MX and TXT (SPF) records of `mail.<domain>`;
   - a DMARC record for `_dmarc.<domain>`.

   This repository manages no hosted zone: whoever holds the domain publishes the records.

### The domain, its zone and its certificate

1. A domain for the platform, `EDGE_DOMAIN_NAME` (for example `app.<company domain>`), in a zone its owner manages.
2. An ACM certificate for that name, in us-east-1 as CloudFront requires. Request it with `aws acm request-certificate --region us-east-1 --domain-name <domain> --validation-method DNS`, and publish its validation record in the zone (`aws acm describe-certificate --region us-east-1 --certificate-arn <arn> --query Certificate.DomainValidationOptions`). Its ARN goes in `EDGE_CERTIFICATE_ARN`.
3. After the first deployment, point the domain at the distribution: an alias or a CNAME to the name in `/dian-einvoice/prod/edge/distribution-domain-name`.

A deployment to an account requires both the domain and the certificate. The functions take the domain as their public origin before the edge exists: they use it in the links they generate and as the web app's session origin.

The CDK app can also serve the edge on the name CloudFront gives the distribution, with its default certificate (`-c edgeDomainName=`, as the tests synthesize it). `scripts/deploy.sh prod` does not use that option.

### The CDK bootstrap

An administrator bootstraps CDK once per account and region, with their own credentials in the environment: `docker compose --env-file backend/.env --profile release run --rm --no-deps cdk-release cdk bootstrap aws://<account>/us-east-1 --cloudformation-execution-policies <policy ARN> -c account=<account>`.

The deploy role assumes the roles the bootstrap creates (qualifier `hnb659fds`). The execution policy sets what CloudFormation may do with every stack of the CDK app; when none is given, it is AdministratorAccess. A policy limited to the platform's services would narrow it, but this repository does not write one (docs/limitations.md).

### GitHub: the environment, its variables and its reviewers

In the repository settings, create an environment named `production`:

- **Required reviewers**: the operators who approve each deployment. The job waits for one of them before it assumes the role.
- **Deployment branches and tags**: `main`, which is protected, and the release tags (`v*.*.*`).
- **Variables** (not secrets: OpenID Connect needs no key):

| Variable | Value |
| --- | --- |
| `DEPLOY_ROLE_ARN` | the ARN of the delivery stack's role, in `/dian-einvoice/prod/delivery/deploy-role-arn` |
| `AWS_ACCOUNT_ID` | the account's 12-digit id |
| `EDGE_DOMAIN_NAME` | the platform's domain |
| `EDGE_CERTIFICATE_ARN` | the ARN of that domain's certificate in us-east-1 |
| `ALERTS_EMAIL` | the address the fallback alerts topic emails, or empty |

When `DEPLOY_ROLE_ARN`, `AWS_ACCOUNT_ID`, `EDGE_DOMAIN_NAME` or `EDGE_CERTIFICATE_ARN` is empty, the workflow stops at its first step with a message that names what is missing.

### The deploy role

The `dian-einvoice-prod-delivery` stack (CDK, prod only) creates it, along with the GitHub Actions OpenID Connect provider. If the account already has that provider, the stack reuses it: `-c githubOidcProviderArn=arn:aws:iam::<account>:oidc-provider/token.actions.githubusercontent.com`.

- **Trust**: `sts:AssumeRoleWithWebIdentity` from that provider only, when the audience is exactly `sts.amazonaws.com` and the subject is exactly `repo:<owner>/<repository>:environment:production`. The owner and the repository are deployment parameters (`-c githubOwner=... -c githubRepository=...`). No other branch, environment, fork or repository can assume the role. Credentials last an hour.
- **Permissions**, each on this project's resources only:
  - assume the CDK bootstrap roles (`cdk diff`, `cdk deploy`);
  - create, update and describe the functions stack and its change set, and pass it only the functions deployment role;
  - manage the stage's deployment artifacts in the deployments bucket;
  - push to the image repository, and sign in to the registry (the one action without a resource);
  - invoke the console function (the migrations);
  - upload, list and restore the web app in its bucket, and invalidate the distribution tagged `project=dian-einvoice`, `stage=prod`;
  - read the stage's parameters, functions and stacks (for the smoke test and the summary).

  No managed policy, no administrator access and no action with a wildcard. Each resource with a wildcard has its reason in `lib/delivery-stack.ts`, and a test checks them (`infra/cdk/test/delivery.test.ts`). The role cannot change a function, a role or a secret outside CloudFormation.
- **The functions deployment role**: CloudFormation deploys the functions stack with it (the serverless service's `provider.iam.deploymentRole`). It covers the functions, their HTTP API, log groups, schedules and mappings, and the notifier's subscription and its parameter, each by the stage's names. It passes the functions' roles only to Lambda, and the schedules' role only to EventBridge Scheduler.

The pipeline never deploys the delivery stack: only an administrator changes its trust and permissions, by hand.

### The secrets an operator sets by hand

- **The chat webhook's address**, which the chat service issues: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`. Until it is set, the notifier only logs each alarm.
- **The fallback email confirmation**: SNS sends a confirmation to `ALERTS_EMAIL`, and someone opens it once.
- **Generated by Secrets Manager, never typed**: `APP_KEY` (`dian-einvoice/prod/app`), the edge secret and the credentials of the two database identities.
- **The DIAN**: this project never talks to the DIAN. Its address in prod is a placeholder (the serverless service's `dianSoapUrl`), and the real web service's WS-Security is not built (docs/limitations.md). The fixed egress addresses (`/dian-einvoice/prod/network/egress-addresses`) are the ones to name in an allow list at the DIAN or at a customer.

## The first deployment

1. **The account**: the Lambda quota, SES production access and the certificate, as above.
2. **The CDK bootstrap**, by an administrator.
3. **The delivery stack**, by an administrator: `docker compose --env-file backend/.env --profile release run --rm --no-deps cdk-release cdk deploy prod/delivery -c account=<account> -c githubOwner=<owner> -c githubRepository=<repository>`. The role's ARN is in `/dian-einvoice/prod/delivery/deploy-role-arn` (`aws ssm get-parameter --name /dian-einvoice/prod/delivery/deploy-role-arn --query Parameter.Value --output text`).
4. **The GitHub environment `production`**, with its reviewers and variables.
5. **The Deploy workflow**, dispatched from `main` (Actions, Deploy, Run workflow) and approved by a reviewer. It:
   - runs the offline checks (`make cdk-check`, `make migrations-check`);
   - assumes the role;
   - shows the differences;
   - deploys the stacks that come before the functions. The network and the database take the longest: Aurora creates its cluster, its reader and the first rotation of its master user;
   - pushes the image, by digest;
   - runs the migrations;
   - deploys the functions;
   - deploys the edge (a distribution takes minutes to deploy);
   - deploys the observability stack;
   - uploads the web app;
   - runs the smoke test and writes the summary.
6. **The domain's records**: the alias to the distribution, and the SES records.
7. **The secrets set by hand**: the chat webhook's address, and the fallback email confirmation.
8. **Check it**: `sh scripts/deploy.sh prod smoke` through the domain, a sign-up and an invoice through the web app, and a forced alarm that reaches the chat ([docs/runbooks/notifier.md](runbooks/notifier.md), step 7).

## A routine deployment

1. Merge to `main` once CI has passed on the pull request.
2. Dispatch the Deploy workflow from `main`, or from a release tag (`vMAJOR.MINOR.PATCH`), and approve it.
3. The workflow runs the offline checks, shows the differences, then deploys.

   Migrations run before the functions take the new code. So for a few minutes, the previous release's code runs on the migrated schema: a migration may add, but never take away.

   `make migrations-check` is a workflow step and a test in `make test`. It fails on a migration that drops or renames a column or a table, unless an annotation names the release from which that change is safe (`// migration-safety: safe since vX.Y.Z: <why>`). Take something away only once no running release reads it (expand, release, contract).
4. The run's summary lists each function's image by digest, the stacks, the web app's version and the recent images.

## Going back

- **The image**: put every function back on an earlier image, by digest, with `sh scripts/deploy.sh prod rollback-image <registry>/dian-einvoice-prod-functions@sha256:<digest>`. A run's summary or `sh scripts/deploy.sh prod versions` gives the earlier images. The database stays as it is.
- **The web app**: `sh scripts/deploy.sh prod rollback-panel` restores the build before the current one, `index.html` included, with its invalidation. That build's fingerprinted files stay in the bucket for a week.
- **The infrastructure**: CloudFormation rolls back a failed update by itself. To go back to an earlier version of a stack, dispatch the workflow from that version's release tag. It shows the differences and deploys those templates, with that version's image. For a stack left in `UPDATE_ROLLBACK_FAILED`, fix what blocked it, then run `aws cloudformation continue-update-rollback --stack-name <stack>`.
- **The database goes forward only.** No migration is rolled back in prod. The previous release's code runs on the migrated schema (the check above), and a new migration fixes a wrong one. If a bug or a mistake damages data, restore the database to a point in time into a new cluster. Then copy the rows back from it (see the database section of docs/infrastructure.md).

## Tearing it down

The prod stacks have termination protection. First turn it off for each one: `aws cloudformation update-termination-protection --no-enable-termination-protection --stack-name <stack>`. Then delete the stacks in the reverse order of the deployment: observability, edge, the functions (`aws cloudformation delete-stack --stack-name dian-einvoice-prod-functions`), compute, database, network, alerts, mail, security, data, storage, messaging, and delivery last.

Some resources outlive their stack on purpose (`RemovalPolicy.RETAIN` in prod, docs/infrastructure.md), so a mistaken delete loses nothing:

- **The database**: the cluster, which also has deletion protection, with its key, its secrets and its log groups. It keeps running, and keeps costing. To stop it for good, take a final snapshot and delete it by hand: `aws rds modify-db-cluster --db-cluster-identifier dian-einvoice-prod-database --no-deletion-protection`, then `aws rds delete-db-cluster --db-cluster-identifier dian-einvoice-prod-database --final-db-snapshot-identifier <name>`.
- **The buckets**: the documents (the invoices, kept for the years the law requires), their access logs, the deployments, the web app and the edge logs.
- **The KMS keys** for the signing certificates and the alarm topics. Each costs a monthly fee until it is scheduled for deletion.
- **The secrets**: `APP_KEY` (without it, the values it encrypted cannot be read), the edge secret and the chat webhook's address.
- **The image repository**, with its images.
- **The fixed egress addresses** (Elastic IPs), so an allow list that names them outlives the stack.
- **The VPC flow logs** and the firewall logs.
- The DynamoDB tables (the cache, the sessions and the rate limits), which CDK keeps by default. They hold nothing that must outlive the platform.

## What has never been verified in a real account

Nothing in this repository has run against AWS. The emulator runs the script, the functions, the queues, the edge, and the alarms on a single metric. Synthesis, cdk-nag, cfn-lint and actionlint check the rest offline. Never verified:

- The workflow itself: OpenID Connect, the deploy role's trust with a real token, the environment's reviewers, and each step with real credentials.
- That the permissions of the deploy role and the functions deployment role are enough, and no more than enough. They are written from the calls the CDK CLI and osls make, and were never exercised.
- The CDK bootstrap's execution policy.
- The push to ECR with immutable tags, its scan on push, its lifecycle and its encryption with the ECR key, and Lambda pulling an image from it by digest.
- Deploying the network and the database (Aurora, its reader, the rotation functions, the security groups and the routes), the order of the first deployment, and how long it takes.
- The edge with a domain and its certificate, the firewall, the web app's invalidation, and the smoke test through a real domain and API Gateway's public address.
- SES out of its sandbox, the DKIM, MAIL FROM, SPF and DMARC records, and email delivery.
- A new account's concurrency quota, the deployment without reserved concurrency, and the database connections under load.
- Going back: an image by digest, the web app by its versions, a stack by an earlier release tag, and a stack in `UPDATE_ROLLBACK_FAILED`. Tearing it down, with what it keeps.
- The cost, which [cost.md](cost.md) only estimates.
