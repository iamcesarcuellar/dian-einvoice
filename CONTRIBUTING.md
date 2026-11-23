# Contributing

Thank you for taking the time. dian-einvoice is a personal reference project maintained by one person. So the bar for a change is that it keeps every claim in the README true and every check green.

## Before you start

- Open an issue first for anything beyond a small fix, so we agree on the approach before you spend time on it.
- Report security issues privately, never in a public issue: see [SECURITY.md](SECURITY.md).
- Everything runs in Docker Compose through the Makefile, and nothing is installed on the host. `make help` lists the targets, and `make demo` starts the local stack with the synthetic demo data. [docs/README.md](docs/README.md) indexes the documentation.

## Repository rules

- **The DIAN is always the local simulator** (`simulator/`), and AWS is always the local emulator (`make cloud-up`). A change never talks to the real DIAN, never needs an AWS account, and costs nothing to run.
- **Synthetic data only.** Every person, company, NIT and document in the demo data, the tests and the docs is made up and labeled as such. Examples are set in Medellín, Antioquia. Never name a real company as a user, customer or inspiration of the project.
- **Multi-tenancy.** Every business table carries `company_id`, and every query is scoped to the current company. A change to a query keeps it scoped and comes with an isolation test.
- **The web app and the backend stay apart.** They talk only through the HTTP API under `/api/v1`.
- **Cloud resources are code.** Shared and stateful resources and IAM roles live in the CDK app (`infra/cdk`). Functions and their triggers live in the serverless service (`infra/functions`). Application code reaches AWS only through the adapters behind its interfaces.
- **English** in code, comments, docs and commit messages.

## Checks

Run the checks for the parts you touched. Continuous integration runs the same targets (`.github/workflows/ci.yml`):

```sh
make lint            # formatters and linters; the docs' links, targets, files, images and diagrams; the cost estimate
make analyse         # PHPStan level 10, vue-tsc and tsc, strict
make test            # backend, web app, simulator, infrastructure and tooling tests
make demo && make e2e && make checks   # the checks include the examples of the README and the API guide
make security        # dependency audits, licenses, the files of third parties (NOTICE) and the secret scan
make cdk-check       # synthesis of both stages, cdk-nag, cfn-lint and actionlint
```

New behavior comes with a test or a check. `make fix` applies the formatters.

## Commits and pull requests

- One topic per pull request, with a short description of what changed and how you verified it.
- Commit messages follow `type: summary` in the imperative mood (`feat:`, `fix:`, `docs:`, `test:`, `style:`), as in the history.
- By contributing, you agree that your contribution is licensed under the project's [MIT License](LICENSE).
