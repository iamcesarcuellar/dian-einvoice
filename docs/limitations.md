# Known limitations

**English** · [Español](es/limitations.md)

This page lists what the project does not do, or does only in part, as it stands. The project is a reference implementation that runs against a local DIAN simulator, with synthetic data. It is not in production use.

## Relationship with the DIAN

- **Not certified.** The DIAN has never accepted a CUFE, a CUDE or a software security code produced here. Every "accepted" comes from the local simulator in `simulator/`.
- **What validates the documents:**
  - the local simulator, with the subset of the annex 1.9 rules in `simulator/src/rules.ts`, where ZD05 is a notification
  - the official XSDs of the DIAN toolbox
  - xmlsec1, for the signatures
  - the toolbox's 2019 schematron, run once (`make dian-schematron`). It reports 24 findings from rules older than annex 1.9, and the Makefile lists each one with its reason. The run depends on the demo documents.
- **No WS-Security.** The real web service requires a signature on the SOAP header (section 7.5), and the project does not build it. The simulator does not check it, and its ApplicationResponse is unsigned.
- **Test certificates only.** Documents are signed with synthetic, self-signed RSA certificates, which are re-dated by rewriting their DER. Only the simulator accepts them; the DIAN would not.
- **What could not be checked without the real service:**
  - the name and NIT in the RUT (FAJ43b, FAJ44b, RUT01)
  - the registered reception emails (FAJ71, FAK55)
  - the issuer as the certificate's owner (ZE03)
  - the ONAC chain of trust (ZD05, DC38 to DC46)
  - the service's real responses, delays and failures
  - the habilitación test set
- **Calls that may have arrived.** When a call gets no response, the backend judges from its cURL error code whether it reached the service. The next attempt asks GetStatus first, and a document sent twice gets a rule 90 response.
- **Three documents only:** the electronic sales invoice, and its credit and debit notes, which always reference an invoice. There are no RADIAN events, no POS equivalent document, no documento soporte, no nómina electrónica, and no export or contingency invoices ([dian-conformance.md](dian-conformance.md#what-is-not-implemented)).

## Signature and certificates

- SigningCertificate lists the chain only when the uploaded file includes it, while DC25 asks for three Cert entries.
- X509Data carries only the signing certificate, as the toolbox example Generica.xml does, while 10.5.2 asks for the whole chain.
- Signatures use RSA with SHA-256 only.
- The signing time comes from the server's clock, which is not synchronized with the INM's legal time (10.11). Time synchronization is left to the infrastructure.
- The signing certificate gets no revocation check (CRL or OCSP).
- PKCS#12 files exported with legacy encryption (RC2, 3DES) do not open under OpenSSL 3 without its legacy provider. The upload responds that the file could not be opened.
- A certificate must be valid at the moment of signing. If it expires between the issue and the signature, the chain stops at the signing step, before anything is sent. The document is retried once a valid certificate is uploaded.

## Calculation and XML

- Withholdings the customer applies go in WithholdingTaxTotal as informative values. The annex describes that group for self-withholdings, and 11.9.1 keeps them out of the totals. Notes carry none, since their XSD has no such group.
- Own software only (ppp 000, no technology provider). The demo has only code 999 items.
- Phone numbers over ten digits are left out. Descriptions are split at 300 characters, and notes at 500.
- Rates outside table 13.3.11 raise a notification.
- Official concept names shorter than the 20 characters of CBF04 and DBF04 are kept, as in the 2023 examples.
- The debit note example in 11.4.5 does not match its own data.
- The optional rounding of IVA to 10 pesos (5.2.1.2) is not applied.
- ReteICA rates are entered as percentages (9.66 per thousand is 0.966 %).
- The CUFE or CUDE, the issue date and time, and the due date are final only once the simulator accepts the document. Generating or signing the XML on a later day moves them (FAD09e). The invoice.issued webhook keeps the first dates.

## Delivery and PDF

- The AttachedDocument is built from the annex's table, since the toolbox has no example. Its ID repeats every year. For notes, its literals stay an invoice's. ValidatorID names the simulator. The PDF is named after the XML.
- dompdf draws the PDF. Its warnings are silenced while it draws, and the result is checked to be a PDF of at least one page. The PDF is drawn inside its document's lock. dompdf's limits:
  - no OpenType layout
  - page numbers and the watermark drawn by a page script
  - simplified SVG
  - fixed margins and page breaks
  - no PDF/UA or PDF/A
- The PDF and the email come in the company's document language, English or Spanish, and in no other. The 2 MB limit in section 9.1 is checked, but the demo never reaches it.
- Email is delivered at least once. If a worker or a function dies after the mail server took the email, the email is sent again.
- SPF, DKIM and DMARC are DNS records of the prod stage's domain identity, which the domain owner publishes. No hosted zone is managed here (see [Edge](#edge), below).

## API and webhooks

- API keys have two levels (read_only, read_write), with no per-resource scopes and no IP allow list. They are stored as SHA-256 hashes, which is enough for their 238 bits of randomness. There is no pepper.
- Rate limits count in fixed 60-second windows, so a burst of twice the limit fits around a window boundary.
- Web sessions are limited on sign-in, sign-up, password recovery, invitation links, company creation, the invoice preview and the search. Other session routes have no limit of their own.
- In production, webhook endpoints must be https on port 443, because the functions open no other port toward the internet.
  - An endpoint on another port cannot be subscribed: creating or changing the subscription returns 422.
  - A subscription saved earlier is refused before each attempt.
- Rotating a webhook secret replaces it at once. There is no period in which both secrets sign.
- Webhooks are delivered at least once, in no particular order. Their bodies are built when the event is published. An endpoint's response is read up to 64 KB, and the rest is discarded.
- An Idempotency-Key is kept for 24 hours. A request with one runs in a single database transaction with its key, so a deadlock inside it is not retried in place. The request returns 5xx, and the same key can be used again.
- The API reference (Scramble), in English and in Spanish, is public, and loads Stoplight Elements 8.4.2 (two files) from unpkg.com.
  - Behind the edge, it shares the origin of the web app's session. The browser runs those two files only with the bytes their Subresource Integrity pins.
  - Each page has its own Content-Security-Policy, which allows its inline scripts by hash (docs/infrastructure.md). Its styles may be inline.
  - A middleware adds the integrity to Scramble's page by finding its two tags. A Scramble version that changes those tags makes the reference fail (500), and its test too, until security-headers.json is updated.
- Actions done with an API key have no user in created_by. Their timelines name the key.

## Payments and business rules

- There is no payment gateway: payments are recorded by hand or through the API. A company's payments are recorded one at a time, under a lock on the company.
- As designed:
  - notes freeze their invoice's rates
  - a void note's prefill matches the invoice
  - credited and debited amounts count accepted notes only
  - payment status, overdue and aging follow the receivables rules
  - negative balances cannot be used
  - a void releases a whole allocation
  - receipts are not electronic documents
  - drafts are deleted for good
- Payments shares the database and the transaction with Billing, through its ports. Workspace reads other modules' tables. Splitting them into services changes both.

## Security

- There is no second factor (TOTP or WebAuthn).
- Sign-up does not verify the email, by decision. The account is created and signed in at once, so sign-up still tells a refusal from a success. A refusal means the email or the NIT is already registered. The mitigation: one message for a taken email and a taken NIT, without naming the field, and ten sign-up attempts an hour per address.
- Passwords use bcrypt and take 12 to 128 characters (bcrypt reads the first 72 bytes). They are not checked against lists of breached passwords, which would need an external service.
- The encryption key is not rotated.
  - APP_KEY encrypts the software PINs, the technical keys and the webhook secrets. Locally, it also encrypts the key store (certificates and their passwords).
  - Changing it means re-encrypting all of them, and the key lives in PHP's memory.
  - In the emulated cloud, the key store uses envelope encryption under a KMS key, and APP_KEY comes from Secrets Manager. That secret has no rotation function: rotating APP_KEY would need one that re-encrypts every value.
  - The APP_KEY secret holds nothing else. The database credentials are in the secrets of its two identities, which the AWS rotation functions rotate in prod.
- Accepting an invitation for a new address while signed in as someone else signs that person out. The page says so first.
- Some development tools run without authentication, on 127.0.0.1 only:
  - the local mailbox, which holds every reset and invitation link
  - the webhook receiver
  - in the emulated cloud, the AWS emulator, which takes any credentials and keeps every email SES took
- The simulator keeps the PINs and technical keys registered with it in plain text, in its state file. The DIAN knows them as well: it issues the technical keys, and the software's PIN is registered with it.
- Local development uses fixed database credentials (dian/dian and root/root, in compose.yaml and backend/.env.example), and the application user owns the schema it migrates. The Redis password and the simulator token are random per checkout.
- The emulated cloud has its own two database identities, with generated passwords (docs/infrastructure.md). Its migrations identity holds:
  - CREATE USER, to create the application identity. MySQL grants it on the whole server, so on the shared compose MySQL server it could also alter the users of the local stack and of the tests. It cannot alter root, which holds SYSTEM_USER.
  - PROCESS, as Aurora's master user does, so php artisan db:connections sees every connection. On the shared compose MySQL server, that includes the local stack's and the tests' connections.
- The tests' migrations identity (tests/database.sql) holds the same privileges, for the same reason.
- The web app's security headers come from the edge (docs/infrastructure.md). The local stack's development server sends none, so make e2e runs without a policy and make cloud-e2e runs under it.
- The web app's policy allows a few fixed style elements from libraries (Reka UI, VueUse) and the inline script of index.html, by their hashes. An upgrade that changes one of them shows as a violation in make cloud-e2e, and its hash goes into security-headers.json.
- FrankenPHP's Server header is left to the proxy in front.
- Under /api/v1, CORS accepts any origin without credentials, for API keys. It is not offered for the CSRF cookie. A session works only from the configured stateful origins: the edge's origin.
- Dependency audits fail on high and critical advisories. Moderate ones in development tools wait for their upstream fixes, which Dependabot proposes. At the time of writing, these were glob and minimatch, which reach the web app's test tools through @vue/test-utils (js-beautify).

## Operation and scale

- The simulator keeps each stack's state in one JSON file of its own, and writes it after every change.
- Searches use LIKE, without full-text indexes. The dashboard's activity and attention queries have no indexes of their own. Aging is computed in PHP on each request. The web app's dashboard chunk is large.
- make stress uses one numbering range and one company. Deadlock retries and lock waits were not measured at scale.
- No load test has measured the capacity the database needs. [cost.md](cost.md#assumptions) assumes it for each volume.
- When a worker is killed during a job, the job waits REDIS_QUEUE_RETRY_AFTER (45 seconds) before another worker takes it.
- Old operational records are pruned once an hour (config/retention.php). Locally, the relay prunes them; in the functions, the scheduler function's Laravel scheduler does.
- Every service has a healthcheck. The local stack has no metrics or alerts, and keeps Laravel's line format. The structured logs, metrics, alarms and notifications belong to the functions (docs/observability.md).
- The production target of the backend's FrankenPHP image copies no code and installs no dependencies: it serves the local stack only. The functions run the lambda target's image, which carries the application and its production dependencies.
- The development tools (make stress, make chaos and the checks) and the demo seeder refuse to run unless APP_ENV is local or testing. An interrupted run leaves its synthetic company behind until the next make seed.

## Emulated cloud

- The local AWS emulator is Floci 2.5.0. It accepts the templates' resources and creates each one for real: an unsupported type fails its stack (FLOCI_SERVICES_CLOUDFORMATION_ALLOW_STUB_UNSUPPORTED_RESOURCE_TYPES=false).
- The emulator evaluates no IAM policy, resource policy or key policy. So these are checked on the synthesized templates only, by the CDK app's tests and cdk-nag:
  - the functions' least-privilege roles
  - the managed policies
  - the TLS-only policies of the topic, the queues and the buckets
  - the topic as the only sender to the queues
  - the ECR repository policy
  - the signing key's encryption context
- The functions reach the emulator over plain HTTP, which those policies refuse in AWS.
- The emulator accepts these settings but does not apply them:
  - the topic's encryption key
  - the buckets' server access logs
  - the storage classes and expirations of their lifecycle rules
  - the ECR repository's lifecycle policy and scan on push
- SES keeps every email at /_aws/ses and delivers none. make cloud-check reads that emulator endpoint, and make cloud-demo clears it. The sender address is verified at once, and DKIM, SPF and DMARC are not emulated.
- One environment at a time: the local stack and the emulated cloud use the same ports. They share the MySQL server, each with a database of its own. The emulated cloud serves the production build of the web app from its edge instead of the development server, and every make cloud-up builds it again.
- One DIAN simulator serves both, and keeps each one's records apart: taxpayers, processed documents, failure modes, the activity log and the webhook receiver. The local stack uses its root paths (`http://simulator:8602/...`). The emulated cloud uses the same paths under `/cloud` (`http://simulator:8602/cloud/...`).
  - So switching between make up and make cloud-up never makes the simulator reject a new document with rule 90, and needs no reload of the demo data.
  - make seed clears only the local stack's records in the simulator, and make cloud-demo only the cloud's. make clean removes the simulator's volume, so it clears both.
- The local stage's database identities get generated passwords in the emulator's Secrets Manager. make cloud-up creates the migrations identity from its secret, as the MySQL root user, the way Aurora creates its master user. The console function creates the application identity.
- The DIAN simulator's admin token, random per checkout in backend/.env, reaches the local stage's functions as an environment variable. So it is in their configuration, and in their stack's template in the emulator. The simulator is a development tool, and prod has no such token.
- The functions get their roles' temporary credentials from the emulator, which takes any.
- The emulated cloud's functions connect to the compose MySQL server over TLS 1.3, and the identities are refused without TLS. But the server certificate is not verified: the compose MySQL server signs its own, for a name other than db. Prod verifies it against the RDS bundle in the image (DB_SSL_VERIFY_SERVER_CERT), which only a real account can prove.
- The emulator cannot run the AWS rotation functions, which are Serverless Application Repository applications published from AWS accounts. So the secrets of the local stage's identities have no rotation schedule, which cdk-nag acknowledges.
- make cloud-check stands in for the rotation with alternating users. The console function, with the migrations identity as the superuser, does the rotation's steps:
  - a new password on the user that is not current, which it creates the first time, with the identity's privileges
  - a test of the new credentials
  - a new current version of the secret
- The console function writes the secret through the emulator, which evaluates no IAM. Its role has no permission to write a secret; in AWS, the rotation functions do it.
- Floci 2.5.0 does not apply a SecretStringTemplate built with intrinsic functions: it stored the generated password alone. So the secret of the local stage's application identity names no masterarn. Only the AWS rotation function reads that field, and prod keeps it.
- The functions run as Lambda functions, in containers the emulator starts through the Docker socket. Only the cloud profile mounts that socket.
- Everything the emulator creates carries the project's name and the label dian-einvoice=floci, which make cloud-down and make up use to remove it:
  - The function containers are named `dian-einvoice-dian-einvoice-<function>-<id>`: the prefix, then the emulator's namespace, then the function's name.
  - The registry behind its ECR is named floci-aws-dian-einvoice-ecr, and its web console floci-aws-dian-einvoice-console. The registry's volume is floci-aws-dian-einvoice-ecr-registry-data.
  - These names start with floci-aws- because the emulator puts that prefix in front of the containers and volumes it starts besides the functions.
- The emulator keeps at most 8 idle function containers in all (2 per function), and removes one after 5 minutes without an invocation. Busy ones are not counted.
- The registry has no memory limit from compose, because the emulator starts it.
- AWS runs a function on a read-only file system, except for /tmp, as a user that owns nothing. The emulator runs it as an unprivileged user (993:990, FLOCI_SERVICES_LAMBDA_DOCKER_FLAGS) on a writable file system. So a write outside /tmp fails for lack of permission, not because the file system is read-only. Root owns every file in the image.
- AWS freezes an execution environment as soon as the function returns its response; the emulator does not. So work left for after the response would run here, but stop half done in AWS. The application leaves none, and a test refuses the calls that would (docs/infrastructure.md).
- The emulator's cold starts are not AWS's: AWS also pulls and caches a new version's image first. Here, a cold start of the API takes about 1 s and a warm response about 15 ms. make cloud-check reports both as emulator figures.
- Before it starts a container, the emulator checks that the Lambda user may enter each directory of the working directory. It does so by copying the directory out of the container through Docker's archive API. For /var/task, the application's directory (about 125 MB), that took 8 to 9 s. So the image works from /, and nothing depends on its working directory.
- Floci 2.5.0 waits for a queue's visibility timeout (60 s) before it returns a record that a function reported as failed, whatever visibility the function set. AWS keeps the visibility the function set. So, here:
  - an unreadable message, due again a second later, reaches the dead-letter queue after its 10 receives in about 10 minutes instead of seconds
  - a record returned before its invocation's deadline comes back after a minute instead of at once
  - a retry due in 2 to 144 s (DIAN_RETRY_DELAYS and its jitter) runs one to three minutes later, and spends a receive per minute
- make cloud-check therefore stops at the second receive of its unreadable message, and purges the chain queue.
- The emulator fixed this on its main branch on 2026-11-19 (floci-io/floci pull request #9003), after 2.5.0, and no release has the fix yet. make cloud-check reads the emulator's version and, on a later release, waits for the dead-letter queue again.
- The emulator runs one invocation at a time per queue mapping: it keeps MaximumConcurrency but does not apply it. So the make cloud-check chain runs one batch at a time, and the issue-to-delivery time it reports is a single consumer's. Reserved concurrency is applied.
- Behind API Gateway and Lambda, a request carries at most 6 MB, the limit of a synchronous invocation (API Gateway takes 10). So does a response, in base64 for binary ones.
  - The largest file the application serves, a zip of at most 2 MB (the annex's section 9.1), fits.
  - Uploads of larger files would need S3 presigned URLs, which the application does not use.
- The emulator serves API Gateway and CloudFront by host name only (`<id>.execute-api.localhost.floci.io`, `<id>.cloudfront.localhost.floci.io`). So the web app's and the API's ports (8601 and 8600) belong to a minimal reverse proxy in front of the distribution: the compose service cloud-gateway (nginx). make cloud-up points it at the distribution it deployed (infra/functions/.env.cloud). In AWS there is no such proxy.
- The local stage's queues hide a message for 60 s: their consumers' timeout, and the least Lambda takes. The emulator returns failed records after their queue's visibility timeout (docs/infrastructure.md).
- The prod stage's queues hide a message for 360 s, six times their consumers' timeout, as AWS recommends against duplicates after a throttle.
- A message that a killed function held comes back after that time. In the emulator, when the invocation fails outright instead of returning an error, the message comes back after 120 s. That is how long the emulator hides every message it hands a function: the function's timeout, the 30 s of FLOCI_SERVICES_LAMBDA_INIT_TIMEOUT_SECONDS, and 30 s more.
- A message scheduled for later (a transmission or webhook retry) spends one of its queue's receives while it waits, invisible. After such a wait, its job has up to 9 attempts left of the 10 that maxReceiveCount allows.
- A message SNS cannot deliver ends in the same dead-letter queue.
- A record that a consumer could not finish before its invocation's deadline goes back to the queue at once, and also spends a receive.
- Each queue has one job per event type. The consumer function sends an event to the dead-letter queue when its queue has no job for the event's type, or more than one. A backend test checks the subscriptions against the routes the CDK app filters on.
- The secrets are read once per execution environment: the configuration is cached at start, with them. A rotated secret reaches the environments started after the rotation. Those already running keep the old values until Lambda replaces them.
- For the database, this is allowed for twice:
  - The application identity rotates with alternating users, so a warm function's credentials stay valid until the rotation after next.
  - The connector reads its identity's secret again, once, when the database refuses the credentials.
- The migrations identity, used by the console function only, rotates as a single user. That leaves a window of seconds, between the password change and the secret change, in which a connection fails.
- The CLIs reach their deployment buckets by host name, which compose gives the emulator as aliases. These are the cdk bootstrap assets bucket and the osls deployments bucket.
- The prod stage is synthesized, packaged and validated, never deployed. These are placeholders, which a real account's deployment parameters replace (docs/deployment.md):
  - its account (111111111111)
  - its domain (app.dian-einvoice.example) and that domain's certificate
  - the alerts' fallback address (operators@dian-einvoice.example)
  - the repository that deploys it (example-owner/dian-einvoice)
- The template packaged offline names its image by a placeholder digest (sha256 of zeros). The deployment replaces it with the digest it pushed.
- [cost.md](cost.md) estimates what the prod stage would cost a month, and says which of its values are assumptions.
- The tests of the AWS adapters and the function handlers run with the AWS SDK responding from memory, and with sample Lambda events. Only make cloud-check runs the functions in the emulator, and CI runs it on main only.

## Edge

The edge is the CloudFront distribution in front of the web app and the API (docs/infrastructure.md). The emulator serves the local stage's distribution, so make cloud-check and make cloud-e2e go through it. It applies the distribution's behaviors, the web app's response headers policy, the origin's custom header and the origin request policy. It also appends the client's address to X-Forwarded-For. What it does not reproduce:

- No AWS WAF. The firewall exists in the prod stage only, and is checked on the synthesized template: the rules, the overrides that count, the limits and the association. Only a real account would show that its rules block what they should and let the API through (multi-line bodies, the certificate, clients without a User-Agent).
- No cache and no compression: every request reaches the bucket or API Gateway. So the files' Cache-Control, the cache policy's TTLs, gzip and Brotli, and an invalidation are checked on the template and on the files' headers, not on a cache. panel.mjs skips the invalidation in the local stage.
- No CloudFront Functions, even when associated with the distribution. The proxy in front of it (cloud-gateway) rewrites the web app's routes instead, with the same cases as the function. The function's code runs in the CDK app's tests the way CloudFront runs it (a plain script with a handler), not in the CloudFront runtime.
- The emulator's CloudFormation (Floci 2.5.0) keeps only part of each behavior: its path, origin, viewer protocol policy, response headers policy and compression. It drops the methods the behavior allows and caches, its cache and origin request policies, and its functions. Through it, the API would get only GET and HEAD, without query strings or cookies.
- make cloud-up gives the distribution those settings as its deployed template declares them, through the emulator's CloudFront API (infra/functions/scripts/edge.mjs reconcile). This workaround for the emulator:
  - creates nothing, and changes nothing the template does not declare
  - refuses to run against anything but the emulator
  - is idempotent
  - prints, per behavior, each setting it had to give back
  - when run again with nothing new, says it keeps what it gave before (the distribution changed after CloudFormation last deployed it)
  - when the distribution, as CloudFormation deployed it, already holds every setting, says that nothing had to be applied and that the step can be retired
- make cloud-check checks that the distribution holds its template. AWS applies the template as it is.
- Plain HTTP: the emulator serves the distribution over HTTP, whatever its viewer protocol policy says. So the redirect to HTTPS, HTTP/2 and HTTP/3, TLS 1.2 and the domain's certificate are checked on the template only. HSTS reaches the browser over HTTP on localhost, where the browser ignores it.
- The emulator replaces a request's User-Agent with CloudFront's when the origin request policy does not name it.
- The emulator writes no access logs to the logs bucket, and applies no lifecycle to them.
- The emulator evaluates no bucket policy, so origin access control (only the distribution reads the bucket) is checked on the template.
- The edge secret's value reaches the local stage's distribution as a deployment parameter, because the emulator expands dynamic references to Secrets Manager only in RDS credentials.
  - make cloud-up pipes it to the CDK CLI, so it is in the stack's parameters in the emulator (NoEcho hides it from DescribeStacks).
  - As in AWS, it is also in the distribution's configuration, which shows custom headers as they are.
  - Prod resolves it from Secrets Manager.
- Rotating the edge secret is a procedure (docs/infrastructure.md) that make cloud-check does not run.
- The origin verifier runs Node.js 24, the newest Lambda runtime the emulator has an image for. Its first call pulls the Lambda Node.js runtime image (public.ecr.aws/lambda/nodejs). It uses that runtime's AWS SDK, which AWS updates on its own.

What only a real account would show:

- that CloudFront replaces an X-Origin-Verify header a viewer sends (documented for an origin's custom headers; the emulator does replace it)
- the South American edge locations in the price class
- the TLS policy TLSv1.2_2021, with the ACM certificate
- HTTP/3
- the AWS WAF managed rules on real traffic, and its per-address limits
- the API Gateway authorizer cache (5 minutes per value)
- rotating the edge secret while the distribution deploys
- the standard logs written with ACLs
- the domain's DNS records: the distribution's alias, the certificate validation, and the SES DKIM, MAIL FROM, SPF and DMARC records, which the domain owner publishes (no hosted zone is managed here)

## Observability

The structured logs, metrics, alarms, dashboard, saved queries and the notifier (docs/observability.md) are deployed to the emulator, and make cloud-check runs them. It checks that:

- every line the functions write is JSON in the schema
- the saved query follows an invoice by its correlation id
- no log holds a secret
- a metric filter produces its metric
- a state forced on an alarm reaches the chat's local receiver through the notifier

make cloud-alarm-check has the emulator evaluate alarms for real, in three to four minutes:

- a message in the chain's dead-letter queue turns its alarm to ALARM and back to OK, and each change reaches the chat
- a notification the chat refuses goes to the notifier's dead-letter queue, whose alarm reaches the chat

What the emulator (Floci 2.5.0) does not reproduce, or reproduces only with help:

- **Alarms written as metric data queries.** The emulator's CloudFormation creates an alarm whose metric is a metric data query without its metrics, so the alarm could never evaluate. Such queries are the Metrics of a metric math alarm, or of a metric with a label. Alarms on one metric are written with the metric itself, which the emulator keeps.
  - make cloud-up gives the three metric math alarms (function-errors, function-throttles, dian-error-rate) the metrics their template declares, through the CloudWatch API (infra/functions/scripts/observability.mjs reconcile).
  - This reconciliation works under the same conditions as the edge one. It refuses to run against anything but the emulator. It is idempotent: it gives back only what is missing, and leaves alone an alarm that holds its metrics.
  - It says what it gave back, and which alarms keep what it gave before (their configuration changed after CloudFormation last created or updated them).
  - When every alarm holds its metrics as CloudFormation deployed them, it says that the emulator has stopped dropping them and that the step can be retired.
  - AWS applies the template as it is.
- **No metric math evaluation, no composite alarm actions.** The three metric math alarms are kept but never evaluated. They stay in INSUFFICIENT_DATA, as their notifications would show. The service-degraded composite alarm exists in prod only, since the emulator runs none of its actions. Both are checked on the templates (the CDK app's tests, cdk-nag, cfn-lint).
- **No native metrics for Lambda, API Gateway, CloudFront, AWS WAF, Aurora or the NAT gateway.** The emulator publishes the SQS metrics every minute and the metrics of the log metric filters, and no others. So the alarms on errors, throttles, 5xx and latency are deployed but stay without data (missing data does not breach), and their widgets are empty. Only a real account would show that they fire on real traffic.
- **No saved queries through CloudFormation.** The emulator's CloudFormation cannot create AWS::Logs::QueryDefinition. So the local stage declares the four queries in the metadata of the observability stack's template (lib/stage.ts, SavedQueries), and make cloud-up saves them from there through the Logs API. Prod declares them as resources.
  - Saving them is part of the same step, under the same conditions. It saves only a query that is missing or differs from the template, by its name, and says which.
  - The emulator validates any template, so only a deployment tells whether a later release creates the queries. On a release later than 2.5.0, the step says so, and says that this part can be retired once the stage declares the queries as resources.
- **Logs Insights evaluates fields, filter, sort, dedup and limit only.** The follow-a-journey query is written with those, and make cloud-check runs it. The errors-by-function and dian-rejections-by-rule queries use stats, and could not be exercised. The slow-requests query is saved but not run.
- **Dashboards are stored, not drawn.** The dashboard is checked on its template, and a test holds the limits it draws to the functions' reserved concurrency.
- **No Lambda system logs and no API Gateway access logs.** The emulator writes each container's output to its log group, and nothing of its own. There is no record of an invocation's start, end or report, in JSON or otherwise. API Gateway's access log group stays empty. LogFormat JSON, the access log format and the AWS WAF logs (prod) are checked on the templates.
- **A container's runtime prints its own lines when the emulator removes the container.** The emulator removes an idle container after 5 minutes without an invocation, or on a redeployment. The function's runtime then loses its invocation endpoint. It prints the error in its own format as the last lines of its stream (the Node.js runtime's format, and Bref's for PHP).
  - make cloud-check sets those lines aside, from the first of them to the end of their stream, and says how many. Every other line must be JSON in the schema.
  - AWS freezes and removes a sandbox without such lines.
- **Accepted, not applied:**
  - the alarm topics' KMS key and its policy (the emulator does not even report it)
  - the policies of the topics and of the notifier's dead-letter queue
  - the fallback topic's email subscription, which sends no email and stays unconfirmed
- Lambda's asynchronous retries and the notifier's failure destination (for an invocation that fails outright) are not exercised. make cloud-alarm-check takes a notification the chat refuses to its dead-letter queue the way the notifier itself does.
- **The embedded metric format** is not documented by the emulator, and is not used (docs/observability.md).
- No endpoint receives Content-Security-Policy violation reports, so a violation in a browser reaches no log. make cloud-e2e fails on the first one it sees.
- The service objectives are written, and the alarms defend them against a fast burn. Nothing measures each one over its 30 days yet.

## Operation and delivery

Limits of the runbooks, the incident drill and the deployment pipeline (docs/runbooks, docs/deployment.md):

- **The deployment has never run.** The workflow .github/workflows/deploy.yml was never executed. actionlint validates it, and the steps it runs are the ones make cloud-up runs against the emulator.
  - The deploy role, its trust through OpenID Connect and the role CloudFormation deploys the functions with are written from the AWS, CDK CLI and osls documentation. So are the push to ECR, the order of a first deployment and every way of rolling back.
  - All of these are checked offline (synthesis, assertions, cdk-nag, cfn-lint), and never exercised with real credentials. docs/deployment.md lists what was never verified.
  - The execution policy CloudFormation gets from the CDK bootstrap is the account owner's choice: AdministratorAccess, unless they give another. This repository writes no narrower one.
  - The workflow's actions are pinned to their major versions, as in CI, not to a commit.
- **The drill's times are the emulator's** (Floci 2.5.0 on this machine) and the local stage's.
  - The local stage's transmissions retry twice (2 and 5 s) instead of five times (2, 5, 15, 45 and 120 s).
  - The reason: the emulator returns a scheduled retry after its queue's 60 s visibility timeout, whatever its delay. So five retries would take six minutes, and the drill more than ten.
  - In AWS, a transmission gives up after about three minutes, so the dead letters arrive first. The DIAN alarm, a metric math alarm that the emulator never evaluates, needs 10 minutes of failures and would fire after them.
  - The report (docs/runbooks/drill-report.md) covers one run.
- **The real-account commands** that the runbooks and the deployment guide give were checked offline, against the AWS SDK models, to be AWS CLI operations. They never ran. In the emulator, only the make targets ran.
- The emulator runs StartMessageMoveTask and ListMessageMoveTasks. Without a destination, it moves only the messages its redrive policy moved, and keeps the task active for the others, which refuses the queue's next task. So the runbooks and make cloud-redrive always name the channel's queue as the destination, which AWS also accepts.
- The emulator's ECR lists every image as pushed at the moment it is listed. So the recent images of make cloud-deploy STEP=versions come in no meaningful order there.
- A step that gave up shows as failed to its company until an operator moves its dead letter back or the company presses Retry now. No state says that it waits for the DIAN, and there is no automatic redrive when the DIAN returns.
- The DIAN's contingency procedure for long outages (invoices issued without its validation and sent later) is not built.
- A step that gave up starts again when its message comes back, for a document still failed at that step in its run. SQS delivers at least once, so a rare duplicate of such a message, delivered right after the step gave up, starts the step once more. The transmission asks GetStatus first, and a repeated number gets a rule 90 response, so nothing is validated twice.
- The web app does not show an error's correlation id to its user. An integration reads it from X-Correlation-Id or the problem body; a web app user reads it from the browser's developer tools.
- make migrations-check reads the column and table drops and renames in up(). A column type change (change()) and a data migration are left to review. A release is a vMAJOR.MINOR.PATCH tag: v1.0.0 and v1.0.1 so far.
- There is no status page for the companies, and no on-call rotation. The alarms arrive in the chat and the fallback email.

## Prod network and database

The network and database stacks exist in the prod stage only, and are never deployed, not even to the emulator. They are proven on what synthesis produces (docs/infrastructure.md). What only a real account could show:

- That AWS takes the templates as written:
  - Aurora MySQL 8.4.8 Serverless v2, with a reader in promotion tier 1. CDK's schema validation and cfn-lint know Aurora MySQL 8.4 up to 8.4.8; 8.4.10 (2026-10-26) is newer than what they know.
  - The cluster parameters (require_secure_transport, tls_version, slow_query_log, server_audit_*).
  - The exports to log groups created beforehand.
  - The instances' CA.
  - The Elastic IPs given to the NAT gateways.
  - The flow logs, through their role.
  - The endpoints and their private DNS.
  - The references between the stacks (Fn::GetStackOutput, CDK's weak references).
- The figures taken from the AWS documentation and not measured:
  - max_connections of 135 at 4 ACU
  - a failover to the reader, typically within 30 seconds, and a replacement without a reader in less than 10 minutes
  - about 15 s to resume a paused instance
  - 300 to 1000 MiB of memory for IAM authentication
  - the prices
- The prices are in [cost.md](cost.md#prices), read from the AWS Price List API and the pricing pages on 2026-11-22:
  - an ACU-hour of Aurora Standard costs $0.12
  - a NAT gateway costs $0.045 an hour and $0.045 per GB
  - an interface endpoint costs $0.01 an hour per zone and $0.01 per GB
  - RDS Proxy costs $0.015 per ACU-hour, with a minimum of 8 ACUs
- That the AWS rotation functions:
  - run in the private subnets
  - reach the database and Secrets Manager through the NAT gateway
  - clone the application user with its privileges on the first rotation, and alternate after it
- That the migrations identity's first rotation, at creation, leaves the cluster usable.
- That the functions verify the server certificate against the RDS bundle, with the cluster endpoint's name.
- That RDS Proxy, when on, takes caching_sha2_password over TLS, and the single-user rotation behind it.
- That the security groups and routes behave as the templates say:
  - only the functions, RDS Proxy and the rotation functions reach the database
  - the isolated subnets have no way out
  - a lost NAT gateway affects exactly what docs/infrastructure.md lists
  - the interface endpoints, when on, exist in the chosen zones (some services offer them in some zones only)
- How the functions ride out a failover and a lost zone (the failure table in docs/infrastructure.md).
- The reserved concurrency against the account's quota.
- The volume of the audit log and of the flow logs.

## Interface

- Keyboard use, screen readers and contrast have not been reviewed.
- The theme is kept in localStorage, and the sidebar state in a cookie. The demo account button exists only on the development server.
- Ctrl+K is taken everywhere. Search requests are not canceled as the user types. The guard against leaving unsaved changes has gaps.

## Tests and tooling

- The integration tests run the chain inline (InlineChain) and send email to Laravel's array mailer. The relay, the queue workers and the SMTP leg to the local mailbox are exercised by make checks, make e2e and CI's stack job.
- Each make e2e and make cloud-e2e run adds two invoices to Demo Comercial SAS, one of them with its payment. Each make docs-examples run adds four invoices, two contacts and two items: an invoice for each README, and a contact, an item and an invoice for each API guide.
- The make cloud-e2e tour visits every route and opens the main dialogs without sending them. Only the invoice journey covers the actions the tour does not take (sending a form, issuing from the editor).
- The end-to-end test opens the app at http://localhost:8601 inside its container, the origin the API accepts for sessions. Its files follow the web app's Prettier configuration, but no linter runs over them.
- The simulator tests use synthetic demo documents as fixtures, with the clock fixed inside their test certificate's validity.
- Not covered by a test: the 64 KB cap on webhook responses, and the limits on the preview and the search. The PDF's QR code is tested as the text it encodes (QrContentTest), not read back from the image, which would take a QR decoder.
- make e2e and make cloud-e2e measure the layout in the DOM (e2e/tests/layout.spec.ts), with the demo data only. They cover every route, the dashboard included, and the main dialogs, at 1280×720, 1440×900 and 1920×1080, in English and in Spanish. Nothing may stick out of its card, dialog, table container or panel, or be cut where the reader cannot scroll to it; no dialog may be larger than the window; and every list fits 1440×900 or more without scrolling sideways. Two elements pass on purpose, each with its reason in the test: the decorative circles of the sign-in screens and the sidebar rail. Windows narrower than 1280 px are not measured. A name longer than the demo's is cut with an ellipsis, but a longer amount or label could still push a table past its box.
- The DIAN simulator keeps the local stack's records apart from the emulated cloud's ([Emulated cloud](#emulated-cloud)). make cloud-e2e still runs a guard first (cloud:check demo, in the console function). The guard checks that the cloud's records hold no document of the demo companies under a number the cloud's database has not issued yet.
  - Such a document holds a number the cloud's next documents would take, and the simulator would reject them with rule 90.
  - The target stops at once and says to run make cloud-demo. It does not repair this by itself, since that means loading the demo data again.
- make test-tools tests the make screenshots image encoder (e2e/screenshots/encode.mjs). The tests read its output back with PNG, LZW and GIF decoders written apart from the encoder, in the test file itself. No image library checks the images.
- PHPStan runs with one worker in the api-analyse container (2 GB), because the AWS SDK's type shapes take about 1.4 GB.
- PHPUnit runs in a container of its own, api-test (1.5 GB). The concurrency tests fork it into up to 8 processes, and each process copies every page it writes. The container peaks at about 380 MiB, and make test-backend prints the peak and the limit at the end of every run.
- vue-tsc's checkUnknownProps and checkUnknownEvents stay off: 390 attributes and listeners pass through to the shadcn-vue and reka-ui wrappers.
- The ESLint rules turned off carry their reason in eslint.config.ts.
- Scramble shows the create rules for the PATCH bodies of taxes and withholdings.
- Scramble lists processing as the only value of the electronic_status filter of the document lists. Their description names the other values.
- Scramble keeps state between two documents it builds in one process, and the second one loses parameters. Each request builds one, and so does each export of make openapi. ApiReferenceTest exports the references in a process of its own.
- TypeScript is pinned to 6.x, and pnpm runs with a build-script allow list.

## Documentation in two languages

- The Spanish documents are translations, and English is the source. Where the two disagree, the English one holds.
- make docs-check keeps each translation in step with its original and checks what is not prose. Each translation carries its original's SHA-256 and keeps its code, inline code, links, figures and structure. A paragraph left in English and a term the glossary replaces fail too.
- It does not judge the prose. Whether a sentence reads naturally, or says just what its original says, rests on the review of whoever writes it.
- make docs-stamp seals a translation whose code, links, figures and structure match its original. A sentence of the original that changed in words only passes, even when its translation did not follow.
- The check for English left behind counts common English and Spanish words in each paragraph. A short sentence, or one made mostly of names, can pass it in English.
- Some things stay in English only:
  - the code and its comments, and the commit messages;
  - the names of fields, events, alarms and functions;
  - the logs and the output of the commands, make help included;
  - the alarms' descriptions, the operations dashboard and the notifier's messages;
  - the names of the CI jobs;
  - the legal text of LICENSE.
- The API reference comes in both languages: http://localhost:8600/docs/api and openapi.json in English, http://localhost:8600/docs/api/es and openapi.es.json in Spanish. make docs-check holds the Spanish one to the English one (e2e/docs/reference.mjs).
  - Its keys, names and values stay in English, as a technical standard: the paths, the operationId, the names of parameters, fields and schemas, the enum values, the examples, the status codes, the header names and the media types.
  - The labels of its viewer stay in English too: Stoplight Elements, a third party's, has no Spanish.
- Each alarm names its runbook in English (docs/runbooks/<name>.md). The runbook's language line leads to its Spanish translation.
- NOTICE.es.txt translates NOTICE for reference only. NOTICE is the file that counts, and the Spanish one quotes the notices of others in their own words.

## Data and demo

- Every person, company, document and payment in the demo is synthetic. Municipalities come from DIVIPOLA (datos.gov.co), with their names title-cased automatically. Demo addresses look real.
- The demo history counts back from the day of the run, using Carbon's test time, while the simulator stamps real dates.
- The README's screenshots and GIFs (make screenshots) show the demo's story day, Thursday, November 19, 2026.
  - While the script runs, the backend and the simulator run on a clock moved to that afternoon (scripts/screenshots/compose.yaml), and so does the browser. So the demo data, the dates on screen and the simulator's responses agree, whatever day the script runs.
  - Mailpit and MySQL keep the machine's clock. The script reloads the demo data, as make seed does.
  - Two runs in a row give the same images, except for what each load of the demo data draws at random. These values are the test certificate's serial number, the demo API keys' identifiers, the company id in the webhook receiver's address, and the jitter of the outage's retries. The differences touch a few tenths of a percent of the pixels in four screenshots.
- Each screenshot is cut where the script finds the end of a card or a table row, so their heights differ.
- The recovered invoice's screenshot starts with a whole timeline entry rather than the top of the card. A timeline with six failed attempts, the retry and the delivery is taller than a screen.
- In the GIFs, a dialog's backdrop is a flat shade instead of the app's blur, which comes out in blotches with a GIF's 256 colors. The app and the PNG screenshots keep the blur.
- make screenshots runs its browser in British English for docs/assets and in Colombian Spanish for docs/assets/es, so the native date field reads day, month and year in both.
- A company's default taxes and withholdings are named in the same notation as the rates on screen, such as "IVA 19 %" and "ReteFuente 2,5 % (purchases)". A company created by an earlier version keeps its earlier names ("IVA 19%"), which an owner can edit. make seed loads the demo data with the current names.
- The DIAN toolbox stays out of git, and most of its examples name real companies. make dian-toolbox downloads it, with its SHA-256, when make dian-schematron needs it. The third-party files the repository does keep, such as the official schemas, are listed in NOTICE.
