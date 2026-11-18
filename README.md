# dian-einvoice

Multi-tenant electronic invoicing platform for Colombian SMEs, API first.

- Requirement: Docker with Docker Compose. Nothing else is installed on the host.
- Run: `make demo` builds the stack (API, web app, MySQL, Redis, two queue workers, the outbox relay and the DIAN simulator), migrates and loads synthetic demo data.
- Web app: http://localhost:8601 · API: http://localhost:8600/api/v1/health · DIAN simulator: http://localhost:8602/health
- Demo sign-in (synthetic data): demo@example.com / password
- Checks: `make dian-check` (CUFE, XSD and XAdES signature), `make stress` (issue-to-acceptance throughput), `make chaos` (kills workers, relay and simulator mid-run; every invoice must end accepted once).
- The DIAN is always a local simulator (`simulator/`): documents are validated there and never sent to, or registered with, the DIAN.
- Status: under construction. Not in production use; demo documents are signed with synthetic test certificates.
