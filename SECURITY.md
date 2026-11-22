# Security policy

DIAN e-Invoice is a reference implementation that runs on your machine: the DIAN is a local simulator, the AWS services are a local emulator, every person and company of the demo is synthetic, and nothing was ever deployed to a real AWS account. A report is still welcome when it shows a way to break what the code promises: the isolation of one company from another, the sessions and API keys, the signatures of documents and webhooks, the secrets, or the infrastructure as code that a real account would deploy.

## How to report a vulnerability

Report it privately through GitHub: open the **Security** tab of this repository and choose **Report a vulnerability**. The report stays between you and the maintainer until a fix is published. Please do not open a public issue, pull request or discussion about it.

A useful report has:

- the commit you tested (`git rev-parse HEAD`) and the environment: the local stack (`make demo`) or the emulated cloud (`make cloud-demo`);
- the steps to reproduce it, with the requests or the commands, and what you expected to happen;
- what an attacker gains, and what they need first (an account, an API key of some level, a role in a company).

There is no bug bounty. This is a personal project maintained by one person: reports are read and answered on the advisory, and a confirmed issue is fixed on `main` and credited in the advisory unless you prefer otherwise.

## Supported versions

Only the latest commit of `main`. There are no releases yet.

## In scope

- The backend (`backend/`): authentication, sessions and CSRF, roles, tenant isolation (every business table is scoped to its company), API keys, idempotency, rate limits, webhooks and their signatures, uploads of certificates, the key store, the PDF and XML generation, and the email delivery.
- The web app (`frontend/`): cross-site scripting, the handling of the session, anything that leaks data of another company.
- The infrastructure as code (`infra/cdk`, `infra/functions`): IAM policies broader than their functions need, a resource reachable from where it should not be, the origin verification of the edge, the security headers, the secrets and their rotation, the deployment workflow (`.github/workflows/deploy.yml`) and its trust in GitHub's OpenID Connect.
- The scripts and the Docker setup: a step that would expose a secret, publish a port beyond `127.0.0.1`, or touch what belongs to another project on the same machine.

## Out of scope

- The DIAN and its services. This project never talks to them; the local simulator (`simulator/`) stands in for them and is a development tool.
- The development tools of the local stack, which run without authentication on `127.0.0.1` by design: the local mailbox, the webhook receiver and the admin API of the simulator, and, in the emulated cloud, the AWS emulator and its web console ([docs/limitations.md](docs/limitations.md#security)).
- The synthetic demo data: its users and passwords (`demo@example.com` / `password`), its API key and its self-signed test certificates are public on purpose and exist only for local development; the demo seeder refuses to run unless `APP_ENV` is `local` or `testing`.
- Findings that need a production deployment that does not exist, or that only restate a limitation already listed in [docs/limitations.md](docs/limitations.md).
- Vulnerabilities of a dependency without a way to reach them through this project: report them to the project that owns the dependency. `make security` audits the dependencies of every application.
- Denial of service against your own local stack, social engineering, and anything that needs physical access to the machine.

## Where to read first

- [docs/threat-model.md](docs/threat-model.md): the assets, the actors, the trust boundaries, the threats with their mitigations, and the risks that remain.
- [docs/limitations.md](docs/limitations.md): what the project does not do, or does only in part, security included.
