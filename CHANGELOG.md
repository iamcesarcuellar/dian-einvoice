# Changelog

All notable changes to dian-einvoice are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
- A XAdES-EPES signature with the company's certificate, verified by xmlsec1. The certificates are synthetic test certificates that only the simulator accepts.
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

[1.0.0]: https://github.com/iamcesarcuellar/dian-einvoice/releases/tag/v1.0.0
