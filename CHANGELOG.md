# Changelog

**English** · [Español](CHANGELOG.es.md)

All notable changes to dian-einvoice are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-11-25

A fix in the invoice editor, the web app's two moderate advisories, and one word of the Spanish API reference. No new features: the API and its reference are the same.

### Interface

- An invoice saved or issued before the editor had loaded its taxes came out without tax. Each line written without an item had no tax, though the editor showed the IVA a moment later. The editor now waits for its numbering ranges, taxes and withholdings. Until they are in, "Save draft" and "Issue invoice" stay disabled, and if one of those lists fails to load, the editor shows the error.
- An invoice issued while those lists were still loading could stop on "Leave without saving?" instead of opening. A document just issued or deleted, or a new draft saved on its way to its own page, has no unsaved changes: nothing that arrives afterwards stops the page from leaving. `make cloud-e2e` failed this way once, on cold functions.

### Checks

- The editor's tests hold back its numbering and its taxes, then save or issue as soon as the editor allows it. On 1.1.0 they find both problems above.

### Security and dependencies

- `pnpm audit` no longer reports glob (GHSA-3g3m-2fjr-6pw4) or minimatch (GHSA-g3hv-75wg-m249) in the web app. Both advisories are moderate, and both packages come through js-beautify, which @vue/test-utils declares. js-beautify and @vue/test-utils are at their latest versions, and their ranges stop short of the fixes. So frontend/pnpm-workspace.yaml overrides those two paths, with glob 14.0.6 and minimatch 11.0.9. Only js-beautify's command line loads them, and the tests never run it.

### Documentation

- The Spanish API reference calls the Health group "Estado" instead of "Salud".
- docs/limitations.md no longer lists the race of the invoice editor, and says that an override closes a moderate advisory that no upstream release fixes.

## [1.1.0] - 2026-11-24

The documentation and the API reference in Spanish, a memory budget of their own for the backend tests, and the upkeep after 1.0.1. The API is the same, and its reference still names it 1.0.0.

### API reference

- The API reference also comes in Spanish: the page at /docs/api/es, its document at /docs/api/es.json, and openapi.es.json at the root, which `make openapi` writes next to openapi.json. It describes the same contract. Only the texts a person reads are translated: the summaries, the descriptions and the names of the groups, 322 blocks in one catalog keyed by the English text (backend/resources/api-reference/es.json). The paths, names, values, examples, status codes, header names and media types stay in English, and so do the labels of Stoplight Elements, its viewer.
- Each reference links to the other on the first line of its description, and the Spanish page declares its language. The About dialog and the Quick start of Settings → Developers open the reference in the language of the interface.

### Observability

- The log redactor keeps every UUID whole. A UUID whose first or last block was all digits lost that block to the rule for identification numbers ("[number]-…"), and a UUID joined to a prefix, such as `request_<uuid>`, was masked whole as a token. A random UUID has a first block of digits 2.3% of the time and a last block of digits 0.4% of the time, so the redactor's test, which drew a random one, failed now and then. The test now uses fixed UUIDs: one with its first block in digits, one with its last block in digits, one all in digits and an ordinary one. Emails, NITs, phone numbers and the values of keys that name a secret are still masked.

### Security and dependencies

- docs/third-party-licenses.md lists each production dependency with its license, without its version: the versions are in the lock files. A version bump no longer makes `make security` fail, as it did on every Dependabot pull request. A package that comes or goes, or a license that changes, still does. A package whose installed versions declare different licenses is listed with each of them. The inventory counts 472 packages.
- The backend takes aws/aws-sdk-php 3.407.1, with guzzlehttp/guzzle 8.6.0, guzzlehttp/promises 3.2.0 and guzzlehttp/psr7 3.6.0.
- The web app takes @lucide/vue 1.77.0, @tanstack/vue-query 5.108.2, vue 3.5.46 and vue-i18n 11.4.23, and its tests @vue/test-utils 2.6.0 and vitest 5.1.1. The simulator takes @types/node 26.6.6.

### Documentation

- Every document also comes in Spanish. The documents of docs/ and docs/runbooks have their translation under docs/es, with the same names, and SECURITY, CONTRIBUTING, CHANGELOG and NOTICE have theirs at the root. NOTICE.es.txt is for reference only: NOTICE is the file that counts. A line under each title leads to the other language, and README.es.md links the Spanish documents.
- The glossary in docs/README.md gives one Spanish term for each term of the interface and the documents, with the variants the Spanish texts do not use.
- `make cost` and `make licenses` write their pages in both languages from the same data. The Spanish pages write their figures in Colombian notation (US$ 1.234,56).
- docs/limitations.md says what the translation is and what it is not. English is the source, and the checks keep each translation in step and check what is not prose, not the prose itself.
- README.md now says what only README.es.md said: `make seed LOCALE=es` and `make cloud-demo LOCALE=es` load the demo data in Spanish, an `Accept-Language` header gets the API's messages in Spanish, and the web app keeps its messages in `frontend/src/i18n`.
- docs/infrastructure.md names actionlint 1.7.12, the version compose.yaml runs.
- The Spanish documents say "script", the word Colombian engineers use, instead of "guion". The glossary takes it as the term, and adds "issue" to the words that stay in English.
- docs/limitations.md says what the Spanish reference translates and what it does not, two limits of Scramble (it lists processing as the only value of the electronic_status filter, and a second document it builds in the same process loses parameters), and a race of the invoice editor: an invoice issued before the numbering ranges and the taxes load asks before the page leaves, as if it had unsaved changes.
- CONTRIBUTING.md says how a change keeps the Spanish reference in step.

### Fixes

- The translation brought up errors in the English documents, now fixed in both languages:
  - docs/api-guide.md: the payment means code `42` is a bank deposit, not a transfer.
  - docs/architecture.md: four counts of the module table, the tables the data model leaves out, what Payments imports from Billing, what a new company gets, and the attempts after a deadlock.
  - docs/deployment.md: the application's functions hold 60 database connections at most, not 72; the environment admits the release tags the workflow runs from; the stacks come down in the reverse order of the deployment.
  - docs/observability.md and docs/infrastructure.md: which functions write the DIAN events, a webhook's longest wait between retries (144 s), and the order in which the local stage deploys.
  - docs/threat-model.md: the sessions kept in DynamoDB, how an unreadable message reaches the dead-letter queue, and a risk listed as a mitigation.
  - The runbooks: how often the origin verifier reads its secret, the notifier's fallback topic, a widget's full title, the event types that fail a message, and the secrets the PHP functions read.
- The description of the `origin-verifier-errors` alarm (infra/cdk/lib/observability-stack.ts) says what happens: a warm verifier keeps using the values it holds, and a new one has none.
- The backend tests run in a container of their own, api-test, with a memory budget of 1.5 GB. They shared the 512 MiB of the development API, and their peak passed it, at 524 to 568 MiB. Under that limit the kernel reclaimed memory about 300 times a run, and in CI it once ended PHPUnit (exit code 137). The test runner now hands back its free memory before it forks, and the tests that build the API reference run in processes of their own, so the peak is 368 to 381 MiB. `make test-backend` prints the container's peak and limit at the end of every run.

### Checks

- `make docs-check` checks the translations (e2e/docs/translations.mjs). Every document has its pair. Each translation written by hand carries the SHA-256 of its original and fails once the original changes, until `make docs-stamp` seals it again. A translation keeps its original's code blocks, inline code, links, figures and structure, section by section. In Spanish, a paragraph left in English and a term the glossary replaces fail too. The legitimate differences are declared in e2e/docs/translations.json, each with its reason. `make test-tools` tests the check, the cases it must fail on included.
- `make docs-check` also checks the make targets and files that the Spanish versions of the README, the index, the architecture, the API guide, the DIAN conformance and the cost estimate name, as it does for the English ones, and the links of the files not committed yet.
- `make docs-examples` also runs the examples of the Spanish API guide, its webhook signature examples in Node.js and PHP included.
- The runbook tests read the Spanish runbooks and deployment guide too. Their commands exist, every page has its translation, the Spanish index lists every alarm with its runbook, and each runbook of an alarm has its six parts.
- `make security` checks that NOTICE.es.txt names every third-party file that NOTICE names.
- `make docs-check` checks the Spanish reference (e2e/docs/reference.mjs). Without the texts a person reads, it is the English contract. Every block of an English text has its translation, or is declared the same in both languages with its reason, and no entry of the catalog is left over. Each translation keeps its original's inline code, links and figures, and passes the glossary and the check for English left. openapi.es.json holds the catalog's translation of every text. `make test-tools` tests the check, the cases it must fail on included.
- `make test-backend` checks that openapi.json and openapi.es.json are what the code generates, by exporting them in a process of their own; nothing checked openapi.json before. Both references are analyzed with no warning, and building the Spanish one leaves no Spanish in the English one.
- `make cloud-check` and `make cloud-e2e` open the Spanish reference too, through the edge and under its policy.
- The translations check takes a link to the API reference, served or exported, as a link to the reference in the other language.
- `make docs-check` prints every problem it finds. It used to exit before a long list reached its pipe.

## [1.0.1] - 2026-11-24

Fixes found while using the interface, a check that nothing spills out of its box, and the upkeep after the first release. No new features: the API and its reference are the same.

### Interface

- The dashboard's "Electronic documents" card no longer cuts its status bar and its "Needs attention" list on the right when a row has a long detail, such as a rejection by the DIAN simulator. "Top customers" and both charts stay inside their cards too, also after the window shrinks.
- "View XML" and "View response" keep the XML inside the dialog. It scrolls sideways there, and its lines stay whole.
- Both had one cause. An item of a CSS grid without columns of its own takes the content it cannot wrap as its minimum width, and the grid grows past its box. A base style now lets such an item be narrower than its content, which then truncates, wraps or scrolls inside it.
- A debit note's lines fit the page at 1440×900 in Spanish: a tax label such as "Excluido (sin impuesto)" wraps inside its cell.
- "Needs attention" says which step a failed document stopped at ("The transmission failed."), in both languages, instead of the step's internal name.
- In English, the simulator's activity writes "not found", "invalid request", "error 500" and "no response" as words.
- The "Signing certificate" card starts with the holder's name, so a cut line still says something useful. The full subject stays in its tooltip.
- The API reference no longer shows "Scramble: 5 warnings": Scramble now reads every validation rule.

### Checks

- A layout check in `make e2e` and `make cloud-e2e`, measured in the DOM. It covers every route and the main dialogs at 1280×720, 1440×900 and 1920×1080, in English and in Spanish. It fails when the page scrolls sideways, when an element sticks out of its card, dialog, table container or panel or is cut by it, and when a dialog does not fit the window. On 1.0.0 it found each of the overflows above.

### Security and dependencies

- The simulator takes ESLint 10.15.0 and typescript-eslint 8.74.0, and the CDK app takes aws-cdk-lib 2.278.0. npm audit no longer reports minimatch in either. The synthesized templates are the same.
- cfn-lint 1.61.0, on Python 3.15.
- Dependabot no longer proposes MySQL above 8.4, which keeps the local database on Aurora's version, or TypeScript's next major version. It proposes neither part of Playwright: its npm package and its image move together, by hand (CONTRIBUTING.md).

### CI

- The Emulated cloud job may run for 40 minutes instead of 30. With an empty cache it took almost 26.

## [1.0.0] - 2026-11-24

The first release: electronic invoicing for Colombian small and medium businesses, API first. The DIAN is always a local simulator, and nothing was ever deployed to AWS: the cloud runs on a local emulator.

### Invoicing

- Contacts, items, taxes (IVA and INC), withholdings (ReteFuente, ReteIVA and ReteICA) and numbering ranges with their DIAN resolution.
- Sales invoices, credit notes and debit notes, from draft to issued. A credit note can void its invoice, and an invoice's balance follows its accepted notes.
- The API calculates every total, tax and withholding, in decimal strings, never floats. The web app shows them as you type.
- Numbers are taken without a gap or a repeat, even from twenty processes at once (`make stress`).

### The electronic document chain

- The UBL 2.1 XML of each document, as annex 1.9 asks, valid against the DIAN toolbox's official XSDs.
- The CUFE and the CUDE, checked against the annex's examples (`make dian-check`).
- An XAdES-EPES signature with the company's certificate, verified by xmlsec1. The certificates are synthetic test certificates that only the simulator accepts.
- Transmission over SOAP (SendBillSync and GetStatus), retried after 2, 5, 15, 45 and 120 seconds, with Retry now after a failure and Resend after a rejection.
- When the chain signs a document on a later day, its issue date moves to that day and its CUFE or CUDE is calculated again.
- A local DIAN simulator in Node.js: a subset of annex 1.9's rules, failure modes per company and a webhook receiver.

### Delivery to the customer

- The signed AttachedDocument, with the document and the simulator's ApplicationResponse inside (annex 1.9, section 6.4).
- The PDF, with its QR code and CUFE, in the language the company chooses for its documents.
- The email with both files in a zip and the annex's subject, caught by a local mailbox. Send again and Regenerate PDF recover a failed delivery.

### Payments and receivables

- Payments recorded against one or more of a customer's invoices, voided with a reason, and receipts numbered without gaps.
- Simultaneous payments never take a balance below zero: of twenty that each pay a tenth, exactly ten are recorded (`make payments-check`).
- Receivables by age, each customer's open invoices, and a dashboard of what was invoiced and collected.

### Public API and webhooks

- A REST API under `/api/v1`, the same one the web app uses, with its OpenAPI reference (`openapi.json`, and `/docs/api` on the running stack).
- API keys per company, read only or read and write, each with a rate limit. Any `POST` takes an `Idempotency-Key`.
- Errors as `application/problem+json` (RFC 9457), with a request id and a correlation id.
- Signed webhooks (Standard Webhooks, HMAC-SHA256) for documents and payments, retried after a failure, with a test event and a local receiver.

### Web app

- A web app in Vue 3 and TypeScript: the dashboard, invoices and notes with their electronic strip and timeline, payments and receivables, contacts, items, taxes and withholdings, numbering, the DIAN settings, the team and the developers' settings.
- Roles (owner, admin, member and viewer), invitations, password resets, a command palette, and light and dark themes.

### Languages

- English and Colombian Spanish in the web app, the API's messages, the PDF, the emails and the demo data (`make demo LOCALE=es`).

### Multi-tenancy and security

- Every business table carries its company, and every query is scoped to it.
- Security headers from one source of truth, with a Content-Security-Policy for the web app and the API reference.
- The certificates' private keys are encrypted at rest, with the application key or with KMS envelope encryption.
- Audits of every dependency, license checks against the MIT License, and a scan of the git history for secrets (`make security`).

### Cloud architecture as code

- An AWS CDK app in TypeScript with two stages: the local stage, deployed to the Floci emulator, and the prod stage, synthesized and validated offline, never deployed. cdk-nag and cfn-lint check both.
- A serverless service (osls) whose functions run on Lambda behind API Gateway and CloudFront: the API, the chain, the webhooks, the relay, the scheduler, the console, the origin verifier and the notifier.
- SNS and SQS with dead-letter queues, S3, DynamoDB, KMS, Secrets Manager, SES and EventBridge Scheduler, each behind one of the application's ports.
- In the prod stage, a VPC in two zones, Aurora MySQL Serverless v2 with rotated database identities, and AWS WAF.
- One deployment script for every stage (`scripts/deploy.sh`), and a deployment workflow that has never run.

### Operations and observability

- JSON log lines in one schema from every function, with a correlation id that follows a request to its last webhook.
- Metric filters, alarms that each name a runbook, a dashboard and saved queries, and a notifier that posts every alarm to a chat.
- A runbook for every alarm, operation commands, and an incident drill in which the DIAN goes down (`make cloud-drill`).

### Getting started and documentation

- One command starts everything in Docker Compose (`make demo`), and `make help` lists every target.
- A README in English and in Spanish, the architecture, an API guide whose examples run, the DIAN conformance, a cost estimate, and screenshots and GIFs made by script.

[1.1.1]: https://github.com/iamcesarcuellar/dian-einvoice/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/iamcesarcuellar/dian-einvoice/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/iamcesarcuellar/dian-einvoice/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/iamcesarcuellar/dian-einvoice/releases/tag/v1.0.0
