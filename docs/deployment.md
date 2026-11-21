# Deployment

How the platform reaches a real AWS account, written as code and validated offline, never run: nothing of this repository has been deployed to an account (docs/limitations.md). The emulated cloud runs the same script with the same steps against the local emulator (`make cloud-up`), and the stage prod is synthesized, packaged and checked by `make cdk-check`. What follows is what an account needs, the order of a first deployment, a routine one, how to go back, how to tear it down, and what has never been verified.

## One script for every stage

`scripts/deploy.sh <local|prod> <step>` deploys a stage. `make cloud-up` runs its steps against the emulator; the workflow `.github/workflows/deploy.yml` runs the same steps against an account. Every tool runs in a container of `compose.yaml`: `cdk-deploy` and `functions-deploy` reach the emulator, `cdk-release` and `functions-release` (profile `release`) reach an account with the credentials of the role the workflow assumed.

| Step | `make cloud-up` (stage local) | The workflow (stage prod) |
| --- | --- | --- |
| `stacks` | EMULATOR ONLY: `cdk bootstrap` every time, the emulator may be new; then `cdk deploy` of messaging, storage, data, security, mail, alerts, compute | `cdk deploy` of the same, plus network and database; the account was bootstrapped once, by hand |
| `image` | builds the target `lambda` of `backend/Dockerfile`; EMULATOR ONLY: pushes it to the registry behind the ECR of the emulator through its published port | builds it the same; pushes it to the ECR repository of the account under the tag of its commit, which never moves (a commit pushed before reuses its image) |
| both print | `<registry>/dian-einvoice-<stage>-functions@sha256:<digest>`: every function is deployed by digest | the same |
| `migrate` | EMULATOR ONLY: its database and its migrations identity on the MySQL of compose (Aurora creates its master user from the secret); then the same as prod | `osls deploy` with the new image on the console function only (`consoleImage`), the others still on the image they run; then `php artisan migrate` and `php artisan db:application-identity` in the console function |
| `functions` | `osls deploy` with every function on the new image, by digest; the admin token of the DIAN simulator as a parameter (a development tool) | the same, with the parameters of the account: its id, the domain (`appUrl`, `statefulDomains`) and the reserved concurrency |
| `edge` | `cdk deploy` of the edge with the value of its secret as a parameter, piped; EMULATOR ONLY: then `edge.mjs reconcile`, the settings of the behaviours its CloudFormation drops | `cdk deploy` of the edge, whose secret CloudFormation resolves from Secrets Manager (a version may be pinned: `ORIGIN_VERIFY_VERSION`) |
| `observability` | `cdk deploy` of the stack alone; EMULATOR ONLY: then `observability.mjs reconcile`, the metrics of the alarms of metric math and the saved queries | `cdk deploy` of the stack alone |
| `panel` | `pnpm build`, then `panel.mjs upload`; the emulator caches nothing, so no invalidation | the same, and the invalidation of the files that are not fingerprinted |
| `gateway` | EMULATOR ONLY: the proxy in front of the distribution, which the emulator serves by host name | none: the domain points at the distribution |
| `smoke` | the health through the edge (the proxy), and API Gateway refusing a call without and with a made-up secret | the same, through the domain and the public address of API Gateway |
| `versions`, `rollback-image`, `rollback-panel`, `diff` | `make cloud-deploy STEP=<step>` | by hand, `sh scripts/deploy.sh prod <step>` with the credentials of an operator; `diff` and `versions` are also steps of the workflow |

The steps that differ only exist because the emulator is not AWS; each is marked `EMULATOR ONLY` in the script, with what AWS does instead.

## What an account needs

### The concurrency of Lambda

The functions reserve 72 concurrent executions in all: api 40, chain 10, webhooks 5, relay 1, scheduler 2, console 2, origin verifier 10, notifier 2. The functions of the application hold one connection each, 60 at most, which the database takes (135 at 4 ACU). Lambda lets an account reserve at most its unreserved concurrency minus 100, which stay for the functions that reserve nothing: the quota of concurrent executions of the account must be at least 72 + 100 = **172**. The quota is 1,000 per region by default, but new accounts start with reduced quotas, which AWS raises with use. Reserving concurrency costs nothing.

1. Read the quota: `aws service-quotas get-service-quota --service-code lambda --quota-code L-B99A9384 --query Quota.Value`, and what is unreserved: `aws lambda get-account-settings --query AccountLimit`.
2. Below 172, ask for more: `aws service-quotas request-service-quota-increase --service-code lambda --quota-code L-B99A9384 --desired-value 1000`.
3. Until AWS raises it, deploy without reserved concurrency: the input `reserved-concurrency` of the workflow, `unreserved` (`--param=reservedConcurrency=unreserved` of the serverless service, which leaves `ReservedConcurrentExecutions` out of every function). The mappings of the queues still cap the chain at 10 and the webhooks at 5, and the quota of the account caps every function together; with a quota below about 115 the functions cannot outgrow the 135 connections of the database (72 + the margin of 20 of the operators and the rotation functions). Deploy with `reserved` again once the quota allows it: reserved concurrency is what keeps a burst of the API from taking the connections of the chain.

### Email: SES out of its sandbox, and the records of the domain

A new account sends email through SES in its sandbox: only to verified addresses, 200 a day. The stack mail verifies the domain of the platform with Easy DKIM and a MAIL FROM subdomain (`mail.<domain>`).

1. Ask for production access: `aws sesv2 put-account-details --production-access-enabled --mail-type TRANSACTIONAL --website-url https://<domain> --use-case-description "Electronic invoices and notes, sent to the customers of each company once the DIAN validates them" --contact-language EN`; check it with `aws sesv2 get-account --query "[ProductionAccessEnabled,SendQuota]"`.
2. After the first deployment, publish the records the identity asks for, in the zone of the domain: the three CNAME records of DKIM (`aws sesv2 get-email-identity --email-identity <domain> --query DkimAttributes.Tokens`: `<token>._domainkey.<domain>` to `<token>.dkim.amazonses.com`), the MX and the TXT (SPF) records of `mail.<domain>`, and a DMARC record for `_dmarc.<domain>`. No hosted zone is managed here: whoever holds the domain publishes them.

### The domain, its zone and its certificate

1. A domain for the platform, `EDGE_DOMAIN_NAME` (for example `app.<company domain>`), in a zone its owner manages.
2. A certificate of ACM in us-east-1, which CloudFront requires, for that name: `aws acm request-certificate --region us-east-1 --domain-name <domain> --validation-method DNS`, its validation record published in the zone (`aws acm describe-certificate --region us-east-1 --certificate-arn <arn> --query Certificate.DomainValidationOptions`), and its ARN as `EDGE_CERTIFICATE_ARN`. A deployment to an account requires both: the functions take the domain as their public origin (the links they generate, the origin of the session of the web app) before the edge exists. The CDK app can also serve the edge on the name CloudFront gives the distribution, with its default certificate (`-c edgeDomainName=`, as the tests synthesize it), which `scripts/deploy.sh prod` does not use.
3. After the first deployment, the domain points at the distribution (an alias or a CNAME to `/dian-einvoice/prod/edge/distribution-domain-name`).

### The bootstrap of CDK

Once per account and region, by an administrator: `docker compose --env-file backend/.env --profile release run --rm --no-deps cdk-release cdk bootstrap aws://<account>/us-east-1 --cloudformation-execution-policies <policy ARN> -c account=<account>`, with the credentials of that administrator in the environment. The deploy role assumes the roles the bootstrap creates (qualifier `hnb659fds`). The execution policy is what CloudFormation may do with every stack of the CDK app: AdministratorAccess when none is given. A policy limited to the services of the platform narrows it; this repository does not write one (docs/limitations.md).

### GitHub: the environment, its variables and its reviewers

In the settings of the repository, an environment named `production`:

- **Required reviewers**: the operators who approve each deployment; the job waits for one of them before it assumes the role.
- **Deployment branches**: `main` only (protected).
- **Variables** (not secrets: OpenID Connect needs no key):

| Variable | Value |
| --- | --- |
| `DEPLOY_ROLE_ARN` | the role of the stack delivery, `/dian-einvoice/prod/delivery/deploy-role-arn` |
| `AWS_ACCOUNT_ID` | the 12 digits of the account |
| `EDGE_DOMAIN_NAME` | the domain of the platform |
| `EDGE_CERTIFICATE_ARN` | the certificate of that domain in us-east-1 |
| `ALERTS_EMAIL` | the address the fallback topic of the alerts emails, or empty |

The workflow stops at its first step, with a message that names what is missing, when `DEPLOY_ROLE_ARN`, `AWS_ACCOUNT_ID`, `EDGE_DOMAIN_NAME` or `EDGE_CERTIFICATE_ARN` is empty.

### The deploy role

The stack `dian-einvoice-prod-delivery` (CDK, prod only) creates it, with the provider of OpenID Connect of GitHub Actions (or reuses the one the account has, `-c githubOidcProviderArn=arn:aws:iam::<account>:oidc-provider/token.actions.githubusercontent.com`):

- **Trust**: `sts:AssumeRoleWithWebIdentity` from that provider only, when the audience is exactly `sts.amazonaws.com` and the subject exactly `repo:<owner>/<repository>:environment:production`; owner and repository are deployment parameters (`-c githubOwner=... -c githubRepository=...`). No other branch, environment, fork or repository can assume it. Credentials last an hour.
- **Permissions**, each on the resources of this project: assume the roles of the bootstrap of CDK (`cdk diff`, `cdk deploy`); create, update and describe the stack of the functions and its change set, and pass it only the functions deployment role; the deployment artifacts of the stage in its bucket; push to the repository of the image (and sign in to the registry, the one action without a resource); invoke the console function (the migrations); upload, list and restore the web app in its bucket, and invalidate the distribution tagged `project=dian-einvoice`, `stage=prod`; read the parameters, the functions and the stacks of the stage (the smoke test and the summary). No managed policy, no administrator, no action with a wildcard; each resource with a wildcard has its reason in `lib/delivery-stack.ts`, and a test holds them (`infra/cdk/test/delivery.test.ts`). It cannot change a function, a role or a secret outside CloudFormation.
- **The functions deployment role**, which CloudFormation deploys the stack of the functions with (`provider.iam.deploymentRole` of the serverless service): the functions, their HTTP API, log groups, schedules, mappings, the subscription of the notifier and its parameter, each by the names of the stage; it passes the roles of the functions only to Lambda and the role of the schedules only to EventBridge Scheduler.

The pipeline never deploys the stack delivery: its own trust and permissions change only by the hand of an administrator.

### The secrets an operator puts by hand

- **The address of the chat webhook**, which the chat issues: `aws secretsmanager put-secret-value --secret-id dian-einvoice/prod/alerts/chat-webhook --secret-string '{"url":"<address>"}'`. Until then the notifier only logs each alarm.
- **The confirmation of the fallback email**: SNS sends a confirmation to `ALERTS_EMAIL`, which someone opens once.
- Generated by Secrets Manager, never typed: `APP_KEY` (`dian-einvoice/prod/app`), the secret of the edge, the credentials of the two identities of the database.
- **The DIAN**: this project never talks to the DIAN; its address in prod is a placeholder (`dianSoapUrl` of the serverless service), and the WS-Security of the real web service is not built (docs/limitations.md). The fixed addresses of the way out (`/dian-einvoice/prod/network/egress-addresses`) are those an allow list of the DIAN or of a customer names.

## The first deployment

1. **The account**: the quota of Lambda, the access of SES and the certificate, above.
2. **The bootstrap of CDK**, by an administrator.
3. **The stack delivery**, by an administrator: `docker compose --env-file backend/.env --profile release run --rm --no-deps cdk-release cdk deploy prod/delivery -c account=<account> -c githubOwner=<owner> -c githubRepository=<repository>`; the ARN of the role is in `/dian-einvoice/prod/delivery/deploy-role-arn` (`aws ssm get-parameter --name /dian-einvoice/prod/delivery/deploy-role-arn --query Parameter.Value --output text`).
4. **The environment `production`** of GitHub, with its reviewers and variables.
5. **The workflow Deploy**, dispatched from `main` (Actions, Deploy, Run workflow), approved by a reviewer: the checks offline (`make cdk-check`, `make migrations-check`), the role, the differences, the stacks before the functions (the network and the database take the longest: Aurora creates its cluster, its reader and the first rotation of its master user), the image by digest, the migrations, the functions, the edge (a distribution takes minutes to deploy), the observability, the web app, the smoke test and the summary.
6. **The records of the domain**: the alias to the distribution, and the records of SES.
7. **The secrets by hand**: the address of the chat webhook; the confirmation of the fallback email.
8. **Check it**: `sh scripts/deploy.sh prod smoke` through the domain, a sign-up and an invoice through the web app, and a forced alarm reaching the chat ([docs/runbooks/notifier.md](runbooks/notifier.md), step 7).

## A routine deployment

1. Merge to `main`; CI has passed (dispatched by hand while the repository is private).
2. Dispatch the workflow Deploy from `main`, or from a tag of a release (`vMAJOR.MINOR.PATCH`), and approve it.
3. It checks offline, shows the differences, then deploys. Migrations run before the functions take the new code, so for a few minutes the code of the release before runs on the migrated schema: a migration may add, never take away. `make migrations-check` (a step of the workflow and a test of `make test`) fails on a migration that drops or renames a column or a table without the annotation of the release from which that step is safe (`// migration-safety: safe since vX.Y.Z: <why>`): take something away only once no release that reads it runs (expand, release, contract).
4. The summary of the run gives the image of each function by digest, the stacks, the version of the web app and the recent images.

## Going back

- **The image**: every function back on an earlier image, by digest, from the summary of a run or `sh scripts/deploy.sh prod versions`: `sh scripts/deploy.sh prod rollback-image <registry>/dian-einvoice-prod-functions@sha256:<digest>`. The database stays as it is.
- **The web app**: the build before the current one, `index.html` included, whose fingerprinted files are still in the bucket for a week: `sh scripts/deploy.sh prod rollback-panel`, with its invalidation.
- **The infrastructure**: CloudFormation rolls back an update that fails, by itself. To go back to an earlier version of a stack, dispatch the workflow from the earlier commit or tag: it shows the differences and deploys those templates, with the image of that commit. A stack left in `UPDATE_ROLLBACK_FAILED`: `aws cloudformation continue-update-rollback --stack-name <stack>`, after fixing what blocked it.
- **The database goes forward only.** No migration is rolled back in prod: the code of the release before runs on the migrated schema (the check above), and a migration that was wrong is fixed by a new one. Data damaged by a bug or a mistake is restored to a point in time into a new cluster, from which the rows are copied back (docs/infrastructure.md, the database).

## Tearing it down

The stacks of prod have termination protection: turn it off first, for each one, `aws cloudformation update-termination-protection --no-enable-termination-protection --stack-name <stack>`. Then delete them in the reverse order of the deployment: observability, edge, the functions (`aws cloudformation delete-stack --stack-name dian-einvoice-prod-functions`), compute, alerts, mail, security, data, storage, messaging, database, network, and the delivery last.

Kept on purpose, when a stack goes (`RemovalPolicy.RETAIN` in prod, docs/infrastructure.md), so a mistaken delete loses nothing:

- **The database**: the cluster, which also has deletion protection, with its key, its secrets and its log groups. It keeps running, and costing: to stop it for good, take a final snapshot and delete it by hand (`aws rds delete-db-cluster --db-cluster-identifier dian-einvoice-prod-database --final-db-snapshot-identifier <name>` after `aws rds modify-db-cluster --db-cluster-identifier dian-einvoice-prod-database --no-deletion-protection`).
- **The buckets**: the documents (the invoices, kept for the years the law asks), their access logs, the deployments, the web app and the logs of the edge.
- **The keys of KMS**: the signing certificates and the topics of the alarms; each costs a monthly fee until it is scheduled for deletion.
- **The secrets**: `APP_KEY` (without it the values it encrypted cannot be read), the secret of the edge, the address of the chat webhook.
- **The repository of the image**, with its images.
- **The fixed addresses of the way out** (Elastic IPs), so an allow list that names them outlives the stack, and the logs of the flows of the VPC and of the firewall.
- The tables of DynamoDB (the cache, the sessions and the rate limits), which CDK keeps by default; they hold nothing that must outlive the platform.

## What has never been verified in a real account

Nothing of this repository has run against AWS. The emulator runs the script, the functions, the queues, the edge and the alarms on a single metric; synthesis, cdk-nag, cfn-lint and actionlint check the rest offline. Never verified:

- The workflow itself: OpenID Connect, the trust of the deploy role with a real token, the reviewers of the environment, and each step with real credentials.
- That the permissions of the deploy role and of the functions deployment role are enough, and no more than enough: written from the calls of the CDK CLI and of osls, never exercised. The execution policy of the bootstrap of CDK.
- The push to ECR with immutable tags, its scan on push, its lifecycle and its encryption with the key of ECR, and Lambda pulling an image by digest from it.
- The deployment of the network and of the database (Aurora, its reader, the rotation functions, the security groups and the routes), the order of the first deployment, and its times.
- The edge with a domain and its certificate, the firewall, the invalidation of the web app, and the smoke test through a real domain and the public address of API Gateway.
- SES out of its sandbox, the records of DKIM, MAIL FROM, SPF and DMARC, and the delivery of email.
- The quota of concurrency of a new account, the deployment without reserved concurrency, and the connections of the database under load.
- Going back: an image by digest, the web app by its versions, a stack by an earlier commit, a stack in `UPDATE_ROLLBACK_FAILED`; and tearing it down with what is kept.
- The cost: it is estimated with the documentation of the phase Polish.
