# dian-einvoice

Multi-tenant electronic invoicing platform for Colombian SMEs, API first.

- Requirement: Docker with Docker Compose. Nothing else is installed on the host.
- Run: `make demo` builds the stack, migrates and loads synthetic demo data.
- Web app: http://localhost:8601
- API: http://localhost:8600/api/v1/health
- Demo sign-in (synthetic data): demo@example.com / password
- Status: under construction. Not in production use; DIAN transmission is not built yet.
