# Known limitations

What this project does not do, or does only in part, as it stands. It is a reference implementation run against a local simulator of the DIAN, with synthetic data; it is not in production use.

## Relationship with the DIAN

- **Not certified.** No CUFE, CUDE or software security code produced here was ever accepted by the DIAN. Every "accepted" comes from the local simulator in `simulator/`.
- **What validates the documents.** The local simulator (the subset of the rules of annex 1.9 in `simulator/src/rules.ts`; ZD05 is a notification there), the official XSDs of the DIAN toolbox, xmlsec1 for the signatures, and, once, the 2019 schematron of the toolbox (`make dian-schematron`: 24 findings from rules older than annex 1.9, each listed with its reason in the Makefile; the run depends on the demo documents).
- **No WS-Security.** The signature of the SOAP header that the real web service requires (section 7.5) is not built. The simulator does not check it, and its ApplicationResponse is unsigned.
- **Test certificates only.** Documents are signed with synthetic self-signed RSA certificates, re-dated by rewriting their DER. Only the simulator accepts them; the DIAN would not.
- **What could not be checked without the real service:** the name and NIT in the RUT (FAJ43b, FAJ44b, RUT01), the registered reception emails (FAJ71, FAK55), the issuer as owner of the certificate (ZE03), the ONAC chain of trust (ZD05, DC38 to DC46), the real answers, delays and failures of the service, and the habilitación test set.
- **Calls that may have arrived.** Whether a call without an answer reached the service is judged from its cURL error code. The next attempt asks GetStatus first, and a document sent twice is answered by rule 90.

## Signature and certificates

- SigningCertificate lists the chain only when the uploaded file brings it, while DC25 asks for three Cert entries; X509Data carries only the signing certificate, as the toolbox example Generica.xml does (10.5.2 asks for the whole chain).
- RSA with SHA-256 only.
- The signing time is the clock of the server, not synchronized with the legal time of the INM (10.11); time synchronization belongs to Infrastructure.
- No revocation check (CRL or OCSP) of the signing certificate.
- PKCS#12 files exported with legacy encryption (RC2, 3DES) do not open under OpenSSL 3 without its legacy provider: the upload answers that the file could not be opened.
- A certificate must be valid at the instant of the signature. One that expires between the issue and the signature stops the chain at the signing step, before anything is sent; the document is retried once a valid certificate is uploaded.

## Calculation and XML

- Withholdings practiced by the customer go in WithholdingTaxTotal as informative values (the annex describes the group for self-withholdings; 11.9.1 keeps them out of the totals), and notes carry none (their XSD has no such group).
- Own software only (ppp 000, no technology provider); only code 999 items in the demo.
- Phones over ten digits are left out; descriptions are split at 300 characters and notes at 500.
- Rates outside table 13.3.11 are a notification; official concept names shorter than the 20 characters of CBF04 and DBF04, as in the 2023 examples; the debit note example of 11.4.5 does not match its own data.
- The optional rounding of IVA to 10 pesos (5.2.1.2) is not applied.
- ReteICA rates are entered as percentages (9.66 per thousand is 0.966 %).
- The CUFE or CUDE, the issue date and time and the due date are final only once the simulator accepts the document: a generation or a signature on a later day moves them (FAD09e). The webhook invoice.issued keeps the first dates.

## Delivery and PDF

- The AttachedDocument is built from the table of the annex (the toolbox has no example); its ID repeats every year, its literals stay those of an invoice for notes, and ValidatorID names the simulator. The PDF is named after the XML.
- dompdf: no OpenType layout, page numbers and watermark drawn by a page script, simplified SVG, fixed margins and page breaks, no PDF/UA or PDF/A. Its warnings are silenced while it draws; the result is checked to be a PDF of at least one page. The PDF is drawn inside the lock of its document.
- Texts are in English until the Polish phase. The 2 MB limit of section 9.1 is checked but never reached by the demo.
- Email is delivered at least once: a worker that dies after the mail server took the email sends it again. SPF, DKIM and DMARC belong to Infrastructure task 2 (the DNS records of the domain identity of the stage prod).

## API and webhooks

- API keys have two levels (read_only, read_write), no scopes per resource and no IP allow list. They are stored as SHA-256, enough for their 238 bits of randomness; there is no pepper.
- Rate limits count in fixed windows of 60 seconds, so a burst of twice the limit fits around the edge of a window. Web sessions are limited on sign-in, sign-up, password recovery, invitation links, company creation, the invoice preview and the search; other session routes have no limit of their own.
- Rotating a webhook secret replaces it at once, without a period in which both secrets sign.
- Webhooks are delivered at least once and in no particular order; their bodies are built when the event is published. Answers of an endpoint are read up to 64 KB and the rest is discarded.
- An Idempotency-Key is kept 24 hours. A request with one runs in a single database transaction with its key, so a deadlock inside it is not retried in place: it answers 5xx and the same key can be used again.
- The API reference (Scramble) is public and loads Stoplight Elements 8.4.2 from unpkg.com without Subresource Integrity. It is served only on the origin of the API, which no browser session uses; Infrastructure may host its assets or turn it off.
- Actions done with an API key have no user in created_by; their timelines name the key.

## Payments and business rules

- No payment gateway: payments are recorded by hand or through the API. Payments of one company are recorded one at a time (a lock on the company).
- As designed: notes freeze the rates of their invoice; the prefill of a void note matches the invoice; credited and debited amounts count accepted notes only; payment status, overdue and aging follow the rules of the receivables; negative balances cannot be used; a void releases a whole allocation; receipts are not electronic documents; drafts are deleted for good.
- Payments shares the database and the transaction with Billing through its ports, and Workspace reads the tables of other modules; splitting them into services changes both.

## Security

- No second factor (TOTP or WebAuthn).
- No email verification at sign-up, by decision: the account is created and signed in at once, so sign-up still tells a refusal from a success, and a refusal means the email or the NIT is already registered. Mitigation: one message for a taken email and a taken NIT, without naming the field, and ten sign-up attempts an hour per address.
- Passwords: bcrypt, 12 to 128 characters (bcrypt reads the first 72 bytes), no check against lists of breached passwords, which would need an external service.
- No rotation of the encryption key. APP_KEY encrypts the software PINs, the technical keys and the webhook secrets, and locally the key store (certificates and their passwords) too; changing it needs re-encrypting them, and the key lives in the memory of PHP. In the emulated cloud the key store is envelope encryption under a KMS key and APP_KEY comes from Secrets Manager, whose secret has no rotation function yet (it belongs to the compute of Infrastructure task 2).
- Accepting an invitation for a new address while signed in as someone else signs that person out; the page says so first.
- Development tools without authentication, on 127.0.0.1 only: the local mailbox (it holds every reset and invitation link), the webhook receiver, and in the emulated cloud the AWS emulator, which takes any credentials and keeps every email SES took. The simulator keeps the PINs and technical keys registered with it in plain text in its state file, as the DIAN, which issues them, knows them.
- Local development uses fixed database credentials (dian/dian and root/root, in compose.yaml and backend/.env.example), and the application user owns the schema it migrates. The Redis password and the simulator token are random per checkout.
- The security headers of the static web app (Content-Security-Policy, HSTS, frame options) belong to its host in Infrastructure; the Server header of FrankenPHP is left to the proxy in front.
- CORS accepts any origin without credentials, for API keys; a session works only from the configured stateful origins.
- Dependency audits fail on high and critical advisories. Moderate ones in development tools (minimatch through ESLint and @vue/test-utils, and the copy of minimatch bundled in aws-cdk-lib, which runs at synthesis only, when this was written) wait for their fixes upstream, which Dependabot proposes.

## Operation and scale

- The simulator keeps its whole state in one JSON file, written after every change.
- Searches use LIKE without full-text indexes; the activity and attention queries of the dashboard have no indexes of their own; aging is computed in PHP on each request; the dashboard chunk of the web app is large.
- make stress uses one numbering and one company; deadlock retries and lock waits were not measured at scale.
- The job of a worker killed during it waits REDIS_QUEUE_RETRY_AFTER (45 seconds) before another worker takes it.
- Old operational records are pruned by the relay once an hour (config/retention.php). A deployment without the relay schedules php artisan retention:prune.
- Every service has a healthcheck; there are no metrics or alerts.
- The production target of the backend image copies no code and installs no dependencies yet (Infrastructure).
- The development tools (make stress, make chaos and the checks) and the demo seeder refuse to run outside APP_ENV local or testing. One that is interrupted leaves its synthetic company behind until the next make seed.

## Emulated cloud

- The local AWS emulator (Floci 2.5.0) accepts every resource of the templates but evaluates no IAM policy, resource policy or key policy: the least-privilege policies, the TLS-only policies of the topic, the queues and the bucket, the topic as the only sender to the queues and the encryption context of the signing key are checked on the synthesized templates only (the CDK tests and cdk-nag). The application reaches the emulator over plain HTTP, which those policies refuse in AWS.
- Accepted but not applied by the emulator: the encryption key of the topic, the server access logs of the bucket and the storage classes of its lifecycle rules. SES keeps every email at /_aws/ses, an endpoint of the emulator that make cloud-check reads and make cloud-demo clears, and delivers none; the sender address is verified at once, and DKIM, SPF and DMARC are not emulated.
- One environment at a time: both use the same ports and share the DIAN simulator, the MySQL server (each with a database of its own) and the web app; make cloud-demo resets the simulator, as make seed does.
- The secret of the stage local keeps the fixed local database credentials (dian/dian); the simulator admin token stays in backend/.env, as a development tool. The AWS clients use the credentials 000000000000/emulated, which the emulator takes; in AWS the roles of task 2 replace them.
- The secrets are read at boot and kept up to 5 minutes in a file only the user of the container can read, in its temporary directory: a rotated secret is seen within that time.
- A message scheduled for later (a transmission or webhook retry) spends one receive of its queue while it waits, invisible: after such a wait its job has up to 9 attempts left of the 10 of maxReceiveCount. A message SNS cannot deliver ends in the same dead-letter queue.
- One job per event type and queue: the SQS consumer sends to the dead-letter queue an event whose queue has no job for its type, or more than one; a backend test checks the subscriptions against the routes the CDK app filters on.
- The CDK CLI reaches the bootstrap bucket of the emulator by its host name, which compose gives the emulator as an alias.
- The stage prod is synthesized and validated, never deployed; its account (111111111111) is a placeholder. The DNS records of its domain identity (DKIM, MAIL FROM, SPF, DMARC), the rotation of the secret and the alarms on the dead-letter queues belong to tasks 2 and 3.
- The tests of the AWS adapters run with the AWS SDK answering from memory; only make cloud-check runs the adapters against the emulator, and CI runs it on main only.

## Interface

- Keyboard use, screen readers and contrast were not reviewed by the agent.
- The theme is kept in localStorage and the sidebar state in a cookie; the demo account button exists only on the development server.
- Ctrl+K is taken everywhere, search requests are not cancelled as the user types, and the guard against leaving unsaved changes has gaps.

## Tests and tooling

- The integration tests run the chain in line (InlineChain) and send email to Laravel's array mailer; the relay, the queue workers and the SMTP leg to the local mailbox are exercised by make checks, make e2e and the stack job of CI.
- Each make e2e run adds an invoice and its payment to Demo Comercial SAS. The end-to-end test opens the app at http://localhost:8601 inside its container, the origin the API takes for sessions; its files follow the Prettier configuration of the web app, but no linter runs over them.
- The simulator tests use synthetic demo documents as fixtures, with the clock fixed inside the validity of their test certificate.
- Not covered by a test: the 64 KB cap on webhook answers and the limits of the preview and the search.
- PHPStan runs with one worker in the api-analyse container (2 GB): the type shapes of the AWS SDK take about 1.4 GB. vue-tsc checkUnknownProps and checkUnknownEvents stay off (390 attributes and listeners pass through to the shadcn-vue and reka-ui wrappers); the ESLint rules turned off carry their reason in eslint.config.ts. Scramble shows the create rules for the PATCH bodies of taxes and withholdings.
- TypeScript is pinned to 6.x and pnpm runs with a build-script allow list.

## Data and demo

- Every person, company, document and payment of the demo is synthetic. Municipalities come from DIVIPOLA (datos.gov.co), with names title-cased automatically; demo addresses look real.
- The demo history counts back from the day of the run with Carbon test time, while the simulator stamps real dates.
- The DIAN toolbox stays out of git; most of its examples name real companies.
