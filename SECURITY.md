# Security policy

dian-einvoice is a reference implementation that runs on your machine. A local simulator stands in for the DIAN, a local emulator stands in for the AWS services, and every person and company in the demo data is synthetic. Nothing was ever deployed to a real AWS account.

A report is still welcome when it shows a way to break what the code promises:

- one company's isolation from another;
- the sessions and API keys;
- the document and webhook signatures;
- the secrets;
- the infrastructure as code that a real account would deploy.

## How to report a vulnerability

Report it privately through GitHub: open this repository's **Security** tab and choose **Report a vulnerability**. The report stays between you and the maintainer until a fix is published. Please do not open a public issue, pull request or discussion about it.

A useful report includes:

- the commit you tested (`git rev-parse HEAD`) and the environment: the local stack (`make demo`) or the emulated cloud (`make cloud-demo`);
- the steps to reproduce it, with the requests or the commands, and what you expected to happen;
- what an attacker gains, and what they need first (an account, a read-only or read-write API key, a role in a company).

There is no bug bounty. This is a personal project maintained by one person. The maintainer reads each report and replies on the advisory. A confirmed issue is fixed on `main` and credited in the advisory, unless you prefer otherwise.

## Supported versions

The latest release, 1.0.0, and the latest commit on `main`. A fix lands on `main` first, then in the next release ([CHANGELOG.md](CHANGELOG.md)).

## In scope

- The backend (`backend/`):
  - authentication, sessions and CSRF, and roles;
  - tenant isolation (every business table is scoped to its company);
  - API keys, idempotency, rate limits, and webhooks and their signatures;
  - certificate uploads, the key store, PDF and XML generation, and email delivery.
- The web app (`frontend/`): cross-site scripting, session handling, and anything that leaks another company's data.
- The infrastructure as code (`infra/cdk`, `infra/functions`):
  - IAM policies broader than their functions need;
  - a resource reachable from where it should not be;
  - the edge's origin verification and the security headers;
  - the secrets and their rotation;
  - the deployment workflow (`.github/workflows/deploy.yml`) and its trust in GitHub's OpenID Connect.
- The scripts and the Docker setup: a step that would expose a secret, publish a port beyond `127.0.0.1`, or touch what belongs to another project on the same machine.

## Out of scope

- The DIAN and its services. This project never talks to them: the local simulator (`simulator/`) stands in for them, and it is a development tool.
- The local stack's development tools, which run without authentication on `127.0.0.1` by design: the local mailbox, the webhook receiver and the simulator's admin API. In the emulated cloud, also the AWS emulator and its web console ([docs/limitations.md](docs/limitations.md#security)).
- The synthetic demo data. Its users and passwords (`demo@example.com` / `password`), its API key and its self-signed test certificates are public on purpose, and exist only for local development. The demo seeder refuses to run unless `APP_ENV` is `local` or `testing`.
- Findings that need a production deployment that does not exist, or that only restate a limitation already listed in [docs/limitations.md](docs/limitations.md).
- Vulnerabilities in a dependency that this project gives no way to reach: report them to the project that owns the dependency. `make security` audits every application's dependencies.
- Denial of service against your own local stack, social engineering, and anything that needs physical access to the machine.

## Where to read first

- [docs/threat-model.md](docs/threat-model.md): the assets, the actors, the trust boundaries, the threats with their mitigations, and the remaining risks.
- [docs/limitations.md](docs/limitations.md): what the project does not do, or does only in part, including security.
