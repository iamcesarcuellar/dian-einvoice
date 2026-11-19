# dian-einvoice

Multi-tenant electronic invoicing platform for Colombian SMEs, API first.

- Requirement: Docker with Docker Compose. Nothing else is installed on the host.
- Run: `make demo` builds the stack (API, web app, MySQL, Redis, two queue workers, the outbox relay, the DIAN simulator with a local webhook receiver, and a local mailbox), migrates, loads six months of synthetic demo data through the real chain, and prints a synthetic demo API key once.
- Web app: http://localhost:8601 · API: http://localhost:8600/api/v1/health · API reference: http://localhost:8600/docs/api (`make openapi` writes openapi.json) · DIAN simulator: http://localhost:8602/health · Local mailbox: http://localhost:8603
- Demo sign-in (synthetic data): demo@example.com / password (owner of both demo companies); admin@example.com, member@example.com and viewer@example.com / password show the other roles in Demo Comercial SAS.
- Checks: `make ui-check` (type-check and production build of the web app; the dashboard and the search answer), `make dian-check` (CUFE and CUDE, XSD, XAdES signature), `make delivery-check` (AttachedDocument, PDF and email), `make payments-check` (20 simultaneous payments: exactly 10 fit), `make api-check` (public API over HTTP with API keys), `make stress` and `make chaos`.
- Simulated: the DIAN is always a local simulator (`simulator/`), webhooks go to a local receiver inside it, and every email lands in a local mailbox (Mailpit) that relays none. PDFs and emails are test documents without tax validity.
- Status: under construction. Not in production use; demo documents are signed with synthetic test certificates.
