# dian-einvoice

Multi-tenant electronic invoicing platform for Colombian SMEs, API first.

- Requirement: Docker with Docker Compose. Nothing else is installed on the host.
- Run: `make demo` builds the stack (API, web app, MySQL, Redis, two queue workers, the outbox relay, the DIAN simulator and a local mailbox), migrates and loads synthetic demo data.
- Web app: http://localhost:8601 · API: http://localhost:8600/api/v1/health · DIAN simulator: http://localhost:8602/health · Local mailbox: http://localhost:8603
- Demo sign-in (synthetic data): demo@example.com / password
- Checks: `make dian-check` (CUFE and CUDE, XSD of invoices and notes, XAdES signature), `make delivery-check` (AttachedDocument XSD, PDF and email of a delivered invoice), `make payments-check` (20 simultaneous payments on one invoice: exactly 10 fit), `make stress` (issue-to-acceptance throughput), `make chaos` (kills workers, relay and simulator mid-run; every invoice must end accepted once).
- Simulated: the DIAN is always a local simulator (`simulator/`), and every email lands in a local mailbox (Mailpit) that relays none. PDFs and emails are test documents without tax validity.
- Status: under construction. Not in production use; demo documents are signed with synthetic test certificates.
