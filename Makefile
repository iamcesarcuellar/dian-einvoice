# Local development commands. Everything runs inside Docker Compose; nothing is installed on the host.

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

# Compose reads the secrets of the stack (Redis password, simulator admin token) from the environment file
# of the backend, the same file Laravel reads; make env creates it and fills them in.
COMPOSE := docker compose --env-file backend/.env

# Size of make stress.
INVOICES ?= 60
CONCURRENCY ?= 20

# Size of make chaos.
CHAOS_INVOICES ?= 40

# The emulated cloud (compose profile cloud): the local AWS emulator, which runs the application as Lambda
# functions behind the edge, the CDK app, the serverless service of the functions (osls) and the proxy in front of
# the edge.
CLOUD := $(COMPOSE) --profile cloud
# What make up stops of the emulated cloud: the proxy on the ports of the web app and the API, and the emulator,
# which runs the functions (scripts/cloud/containers.sh then removes the containers it started for them).
CLOUD_APP := cloud-gateway floci
# osls and the scripts of the functions, on the network of the stack.
FUNCTIONS := $(CLOUD) run --rm --no-deps -T functions-deploy
# The deployment of the stage local, the same script as a real account (scripts/deploy.sh, docs/deployment.md).
DEPLOY = BUILD="$(BUILD)" sh scripts/deploy.sh local

# Size of make cloud-drill: invoices of the baseline, then those issued while the DIAN is down.
DRILL_INVOICES ?= 3
DRILL_INCIDENT_INVOICES ?= 5

# Size of make cloud-check: invoices of the fast path, then those issued while the functions are killed.
CLOUD_INVOICES ?= 30
CLOUD_CHAOS_INVOICES ?= 10
export CLOUD_CHAOS_INVOICES

# docker compose builds the images that changed before using them. CI builds them beforehand with its layer
# cache and sets BUILD empty, so compose uses them as they are.
BUILD ?= --build

TARGETS := up down logs ps seed demo stress chaos dian-check dian-schematron delivery-check clean nuke sh-api sh-web screenshots payments-check api-check openapi ui-check lint analyse fix test test-backend test-frontend test-simulator test-infra e2e checks security licenses docs-check cloud-up cloud-demo cloud-down cloud-clean cloud-check cloud-alarm-check cloud-follow cloud-alarms cloud-e2e cloud-artisan cdk-check migrations-check cloud-deploy cloud-drill cloud-dead-letters cloud-dead-letters-purge cloud-redrive cloud-errors cloud-functions cloud-rotate

.PHONY: env help $(TARGETS)

# make with no target lists the targets.
.DEFAULT_GOAL := help

# The groups of make help, in order. Each target names its group and says what it does on its own line, after ##.
HELP_GROUPS := Get started|Local stack|Quality|Checks of the running stack|Emulated cloud|Operations of the emulated cloud|Clean up

help: ## Get started | List these targets, grouped, one line each (the default target)
	@awk -v groups='$(HELP_GROUPS)' 'BEGIN { FS = ":.*## " } /^[a-z][a-z0-9-]*:.*## / { split($$2, part, " [|] "); lines[part[1]] = lines[part[1]] sprintf("  %-26s %s\n", $$1, part[2]) } END { count = split(groups, order, "|"); for (i = 1; i <= count; i++) if (order[i] in lines) printf "%s\n%s\n", order[i], lines[order[i]] }' $(firstword $(MAKEFILE_LIST))

# Every target runs compose with backend/.env: it is created and its secrets filled in first.
$(TARGETS): env

# backend/.env from backend/.env.example when missing, with a random Redis password and simulator admin
# token when they are empty (scripts/env.sh). Never committed; values already set are kept.
env: ## Local stack | Create backend/.env from backend/.env.example with random local secrets (every target runs it)
	@sh scripts/env.sh backend/.env

# Build images if needed, start the stack, wait for the database, Redis and the local mailbox, install missing dependencies,
# generate the key, migrate, and start the queue workers and the outbox relay with the current code.
up: ## Get started | Build what changed and start the local stack: API, web app, workers, relay, DIAN simulator, mailbox
	$(if $(BUILD),$(COMPOSE) build)
	$(CLOUD) stop $(CLOUD_APP)
	@sh scripts/cloud/containers.sh remove
	$(COMPOSE) up -d --wait db redis mail
	test -d backend/vendor || $(COMPOSE) run --rm --no-deps api composer install --no-interaction
	grep -Eq '^APP_KEY=.+' backend/.env || $(COMPOSE) run --rm --no-deps api php artisan key:generate --no-interaction
	test -d frontend/node_modules || $(COMPOSE) run --rm --no-deps web pnpm install --frozen-lockfile
	$(COMPOSE) up -d api web simulator
	$(COMPOSE) exec api php artisan migrate --force --no-interaction
	$(COMPOSE) up -d --force-recreate worker relay
	@echo "Web app: http://localhost:8601  API: http://localhost:8600/api/v1/health  API reference: http://localhost:8600/docs/api  DIAN simulator and webhook receiver: http://localhost:8602/health  Local mailbox: http://localhost:8603"

# Stop and remove this project's containers, those of the emulated cloud and those its emulator started for
# the functions too; every volume is kept.
down: ## Get started | Stop and remove the containers of this project and of its emulated cloud; volumes are kept
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down

# Follow the logs of every service.
logs: ## Local stack | Follow the logs of every service
	$(COMPOSE) logs -f --tail=100

# Show the state of this project's containers, with the result of the healthcheck of each service.
ps: ## Local stack | Show the containers of this project with their health
	$(COMPOSE) ps

# Rebuild the database from scratch and load the synthetic demo data; local data is lost. The workers and
# the relay are stopped meanwhile: the demo runs its invoices through the chain itself, in line.
seed: ## Local stack | Rebuild the database and load the synthetic demo data again (local data is lost)
	$(COMPOSE) stop worker relay
	$(COMPOSE) exec api php artisan migrate:fresh --seed --force --no-interaction || { $(COMPOSE) up -d worker relay; exit 1; }
	$(COMPOSE) up -d worker relay

# Start the stack and load the synthetic demo data.
demo: up ## Get started | Start the local stack and load the synthetic demo data: the quick start
	@$(MAKE) --no-print-directory seed

# On a temporary company, issue INVOICES invoices from CONCURRENCY parallel processes, check the numbers have
# no gaps or repeats, and time the whole chain from issue to acceptance by the DIAN simulator.
stress: ## Checks of the running stack | Issue INVOICES invoices from CONCURRENCY processes: no gaps, no repeats, timed
	$(COMPOSE) exec api php artisan billing:stress --invoices=$(INVOICES) --concurrency=$(CONCURRENCY)

# On a temporary company, issue CHAOS_INVOICES invoices, kill both workers and the relay while the chain runs,
# start them again, restart the DIAN simulator, and check every invoice ends accepted once, without gaps.
chaos: ## Checks of the running stack | Kill the workers and the relay mid-chain: every invoice ends accepted once
	@sh scripts/chaos.sh $(CHAOS_INVOICES)

# Check the CUFE against the DIAN annex example, validate an issued demo invoice XML against the official XSD, sign it and verify the signature with xmlsec1 (and that a tampered copy fails).
dian-check: ## Checks of the running stack | CUFE against the annex, the XML against the official XSD, the signature with xmlsec1
	$(COMPOSE) exec api php artisan dian:check

# Optional, outside every other check: the schematron of the DIAN toolbox (XSL/DIAN-UBL21-model-compiled.xsl and
# Schemes/listacodigos v1.6, both of 2019) over the demo invoice with a discount and ReteIVA, credit note and debit
# note of Demo Comercial SAS (run make demo first). SaxonJS and SchXslt are downloaded once into the
# schematron-tools volume. The findings listed below come from rules older than annex 1.9 and pass, each with its
# reason (document|rule|text of the message|reason); any other finding fails.
define DIAN_SCHEMATRON_OUTDATED
invoice|FAD03||ProfileID: annex 1.9 (FAD03) asks "DIAN 2.1: Factura Electrónica de Venta", the 2019 rule the literal "DIAN 2.1".
invoice|FAD09c||Issue date more than five days back: annex 1.9 removed FAD09c (section 2.1), and the demo dates its documents in the past.
invoice|FAJ26|O-48|Fiscal responsibility O-48 of table 13.2.6.1, newer than the 2019 list.
invoice|AA06|'10'|CustomizationID 10, the standard operation of table 13.1.5.1, is missing from the 2019 list.
invoice|AA11||Annex 1.9 asks the literal CUFE-SHA384 (FAD08); the 2019 list has SHA-256, SHA-384 and SHA-512.
invoice|HD06|'O-48'|Fiscal responsibility O-48 of table 13.2.6.1, newer than the 2019 list.
invoice|HD06|'O-15;O-23'|Several responsibilities joined with ";", as FAJ26 of annex 1.9 asks; the 2019 rule compares the whole value with its list.
credit-note|CAD03||ProfileID: annex 1.9 (CAD03) asks "DIAN 2.1: Nota Crédito de Factura Electrónica de Venta".
credit-note|CAD09c||Issue date more than five days back: annex 1.9 removed CAD09c (section 2.1).
credit-note|CAD02||CustomizationID 20, a credit note that references an invoice (table 13.1.5.2 of annex 1.9).
credit-note|CAJ26|O-48|Fiscal responsibility O-48 of table 13.2.6.1, newer than the 2019 list.
credit-note|AA06|'20'|CustomizationID 20 of table 13.1.5.2 is missing from the 2019 list.
credit-note|AA11||Annex 1.9 asks the literal CUDE-SHA384 (CAD08); the 2019 list has SHA-256, SHA-384 and SHA-512.
credit-note|HF03|'3'|Concept 3 of table 13.2.4: annex 1.9 and the toolbox examples write it with one digit, the 2019 list as 03.
credit-note|HD06|'O-48'|Fiscal responsibility O-48 of table 13.2.6.1, newer than the 2019 list.
debit-note|DAD03||ProfileID: annex 1.9 (DAD03) asks "DIAN 2.1: Nota Débito de Factura Electrónica de Venta".
debit-note|DAD09c||Issue date more than five days back: annex 1.9 removed DAD09c (section 2.1).
debit-note|DAD02||CustomizationID 30, a debit note that references an invoice (table 13.1.5.3 of annex 1.9).
debit-note|DAJ26|O-48|Fiscal responsibility O-48 of table 13.2.6.1, newer than the 2019 list.
debit-note|AA06|'30'|CustomizationID 30 of table 13.1.5.3 is missing from the 2019 list.
debit-note|AA11||Annex 1.9 asks the literal CUDE-SHA384 (DAD08); the 2019 list has SHA-256, SHA-384 and SHA-512.
debit-note|HF03|'1'|Concept 1 of table 13.2.5: the 2019 rule checks every ResponseCode against the credit note list, written with two digits.
debit-note|HD06|'O-48'|Fiscal responsibility O-48 of table 13.2.6.1, newer than the 2019 list.
debit-note|HD06|'O-15;O-23'|Several responsibilities joined with ";", as DAJ26 of annex 1.9 asks; the 2019 rule compares the whole value with its list.
endef
export DIAN_SCHEMATRON_OUTDATED

dian-schematron: ## Checks of the running stack | Optional: the 2019 schematron of the DIAN toolbox over the demo documents
	$(COMPOSE) --profile tools run --rm --no-deps -T schematron

# On an accepted demo invoice: validate its AttachedDocument against the official XSD (signed invoice and
# ApplicationResponse inside), read its PDF with poppler (pages, number, CUFE), and find its email in the local mailbox.
delivery-check: ## Checks of the running stack | The AttachedDocument against its XSD, the PDF read with poppler, the email in the mailbox
	$(COMPOSE) exec api php artisan delivery:check

# On a temporary company of its own, deleted at the end: an accepted invoice and 20 simultaneous payments
# of a tenth of its balance. Exactly 10 must fit, the balance must end at zero, and the receipts have no gaps.
payments-check: ## Checks of the running stack | Twenty simultaneous payments of a tenth of a balance: exactly ten fit
	$(COMPOSE) exec api php artisan payments:check

# On a temporary company of its own, deleted at the end, and over HTTP with API keys only: idempotent issue
# (repeat, other body, 10 at once), a read-only key refused, signed webhooks in the local receiver, a
# webhook retried after two HTTP 500, and the rate limit answering 429 with Retry-After.
api-check: ## Checks of the running stack | The public API over HTTP with API keys: idempotency, webhooks, rate limits
	$(COMPOSE) exec api php artisan api:check

# Type-check and build the web app for production (into /tmp of its container), then sign in over HTTP as the synthetic demo user and check that GET /dashboard and GET /search?q=SETP of Demo Comercial SAS return data.
ui-check: ## Checks of the running stack | Type-check and build the web app, then read the dashboard and the search over HTTP
	$(COMPOSE) exec -T web sh -c 'pnpm typecheck && pnpm exec vite build --outDir /tmp/ui-check-dist --emptyOutDir'
	$(COMPOSE) exec -T api php artisan ui:check

# Formatters and linters, checking only: Laravel Pint for the backend, ESLint and Prettier for the web app, ESLint
# for the simulator and the CDK app, the syntax of the scripts of the functions, and the documentation (make
# docs-check). Each one runs in a throwaway container of its service; the stack need not be up.
lint: ## Quality | Formatters and linters, then docs-check; the stack need not be up
	$(COMPOSE) run --rm --no-deps -T api vendor/bin/pint --test
	$(COMPOSE) run --rm --no-deps -T web sh -c 'pnpm lint && pnpm format:check'
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm run lint
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm run lint
	$(CLOUD) run --rm --no-deps $(BUILD) -T functions sh -c 'for script in scripts/*.mjs; do node --check "$$script" || exit 1; done'
	@$(MAKE) --no-print-directory docs-check

# The documentation holds true (e2e/docs/check.mjs, in the browser tooling of profile e2e, the repository read-only):
# every make target and file README.md and docs/README.md name exists, every relative link and anchor of every
# Markdown file resolves, every image exists and keeps to its size (a screenshot 400 KB, a GIF 5 MB), and Mermaid
# draws every diagram.
docs-check: ## Quality | The make targets, files, links, anchors, images and Mermaid diagrams of the docs
	$(COMPOSE) --profile e2e run --rm --no-deps -T -v $(CURDIR):/repo:ro e2e sh -c 'npm ci --no-audit --no-fund && node docs/check.mjs /repo'

# Static analysis: PHPStan with Larastan (level in backend/phpstan.neon, no baseline), vue-tsc and tsc (simulator, CDK
# app and the origin verifier of the edge), all strict.
analyse: ## Quality | Static analysis: PHPStan level 10 without a baseline, vue-tsc and tsc, strict
	$(COMPOSE) --profile tools run --rm --no-deps -T api-analyse vendor/bin/phpstan analyse --no-progress --memory-limit=1800M
	$(COMPOSE) run --rm --no-deps -T web pnpm typecheck
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm run typecheck
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm run typecheck
	$(CLOUD) run --rm --no-deps $(BUILD) -T functions npm run --silent typecheck

# Apply the formatters and the automatic fixes of the linters.
fix: ## Quality | Apply the formatters and the automatic fixes of the linters
	$(COMPOSE) run --rm --no-deps -T api vendor/bin/pint
	$(COMPOSE) run --rm --no-deps -T web pnpm fix
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm run fix
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm run fix

# Security of the dependencies and the repository, also a job of CI. Known vulnerabilities of the backend, the
# web app, the simulator, the CDK app, the serverless service of the functions and the browser tooling (e2e): high and
# critical ones fail. A production dependency whose license is not compatible with the MIT License of the project
# fails, and so does docs/third-party-licenses.md when it is not what the lock files give (scripts/licenses.sh
# --check; make licenses writes it). Secrets in the git history and in the changes not committed yet fail (gitleaks,
# profile security: the rules and the public values of .gitleaks.toml, and the findings that are not secrets, by
# fingerprint, in .gitleaksignore). Each step runs in a container; the audits ask the public advisory databases of
# Packagist and npm.
security: ## Quality | Dependency audits, licenses compatible with MIT, and the secret scan of the history
	$(COMPOSE) run --rm --no-deps -T api composer audit --locked --no-interaction --abandoned=report --ignore-severity=low --ignore-severity=medium
	$(COMPOSE) run --rm --no-deps -T web pnpm audit --audit-level high
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev sh -c 'cd /workspace && npm audit --audit-level=high'
	$(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/infra/cdk:/infra:ro web sh -c 'cd /infra && npm audit --audit-level=high --package-lock-only'
	$(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/infra/functions:/infra:ro web sh -c 'cd /infra && npm audit --audit-level=high --package-lock-only'
	$(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/e2e:/e2e:ro web sh -c 'cd /e2e && npm audit --audit-level=high --package-lock-only'
	@sh scripts/licenses.sh --check
	$(COMPOSE) --profile security run --rm -T gitleaks git /repo --config /repo/.gitleaks.toml --gitleaks-ignore-path /repo/.gitleaksignore --redact --no-banner
	$(COMPOSE) --profile security run --rm -T gitleaks git /repo --config /repo/.gitleaks.toml --gitleaks-ignore-path /repo/.gitleaksignore --redact --no-banner --pre-commit
	$(COMPOSE) --profile security run --rm -T gitleaks git /repo --config /repo/.gitleaks.toml --gitleaks-ignore-path /repo/.gitleaksignore --redact --no-banner --pre-commit --staged

# docs/third-party-licenses.md: every production dependency of the five applications with its version and license, from
# their lock files (scripts/licenses.sh); it fails when a license is not compatible with the MIT License of the project.
licenses: ## Quality | Write docs/third-party-licenses.md from the lock files
	@sh scripts/licenses.sh

# Every test that needs no browser: backend, web app, simulator and CDK app.
test: test-backend test-frontend test-simulator test-infra ## Quality | Every test that needs no browser: backend, web app, simulator and infrastructure

# Unit tests of the backend, then its integration tests against the real MySQL (database dian_einvoice_test, created
# here and recreated by the tests), Redis (databases 3 and 4, not those of the stack) and the local DIAN simulator.
test-backend: ## Quality | Unit and integration tests of the backend, against MySQL, Redis and the simulator
	$(COMPOSE) up -d $(BUILD) --wait db redis simulator
	$(COMPOSE) exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysql -uroot' < backend/tests/database.sql
	$(COMPOSE) run --rm $(BUILD) --no-deps -T -v $(CURDIR)/docs:/docs:ro -e RUNBOOKS_DIR=/docs/runbooks api vendor/bin/phpunit

# Unit tests of the web app (Vitest).
test-frontend: ## Quality | Unit tests of the web app (Vitest)
	$(COMPOSE) run --rm --no-deps -T web pnpm test

# Tests of the simulator (node:test): its rules, failure modes and webhook receiver.
test-simulator: ## Quality | Tests of the DIAN simulator (node:test)
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm test

# Tests of the infrastructure as code (node:test), without a network: the origin verifier of the edge, then the CDK
# app: assertions on the synthesized templates of both stages, on the templates osls packages for the functions
# (both stages, packaged first, without a network or credentials), the CloudFront Function of the routes of the web
# app with its cases, and cdk-nag (AWS Solutions) over both, with every exception acknowledged with its reason.
test-infra: ## Quality | Tests of the CDK app and of the functions, with cdk-nag, without a network
	$(CLOUD) run --rm --no-deps $(BUILD) -T functions sh -c 'npm test && npm run --silent package:local && npm run --silent package:prod && npm run --silent package:prod-unreserved'
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm test

# The infrastructure as code as the CI job of the infrastructure checks it, without a network: the tests of the origin
# verifier, the templates of the functions packaged by osls for both stages, the tests of the CDK app, cdk synth of
# both stages, which stops on any finding of cdk-nag (report in infra/cdk/cdk.out/validation-report.json), cfn-lint
# over every template, and actionlint over the workflows of GitHub Actions, the deployment one included, never run.
cdk-check: ## Quality | The infrastructure as code offline: tests, synthesis of both stages, cfn-lint, actionlint
	$(CLOUD) run --rm --no-deps $(BUILD) -T functions sh -c 'npm test && npm run --silent package:local && npm run --silent package:prod && npm run --silent package:prod-unreserved'
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk sh -c 'npm test && npm run synth'
	$(CLOUD) run --rm --no-deps $(BUILD) -T cfn-lint
	$(COMPOSE) --profile tools run --rm -T actionlint

# The migrations are safe to run before the functions take the new code, as the deployment does (scripts/deploy.sh
# migrate): a statement that drops or renames a column or a table names the release from which it is safe. Also a test
# of make test (MigrationSafetyTest) and a step of the deployment workflow.
migrations-check: ## Quality | Refuse a migration that drops or renames without naming the release it is safe from
	$(COMPOSE) run --rm --no-deps -T api php artisan migrations:check

# The end-to-end test in a browser (Playwright, profile e2e) against the full stack with the demo data (make demo):
# sign in, issue an invoice, see it accepted and delivered, open its PDF, record its payment and see it paid.
e2e: ## Checks of the running stack | End-to-end test in a browser: an invoice from draft to accepted, delivered and paid
	$(COMPOSE) --profile e2e run --rm --no-deps -T e2e sh -c 'npm ci --no-audit --no-fund && npx playwright test'

# The checks of the stack, in a row, against the demo data (make demo). make dian-schematron stays optional, apart.
checks: ## Checks of the running stack | ui-check, dian-check, delivery-check, payments-check, api-check, stress and chaos in a row
	@$(MAKE) --no-print-directory ui-check
	@$(MAKE) --no-print-directory dian-check
	@$(MAKE) --no-print-directory delivery-check
	@$(MAKE) --no-print-directory payments-check
	@$(MAKE) --no-print-directory api-check
	@$(MAKE) --no-print-directory stress
	@$(MAKE) --no-print-directory chaos

# Export the public API reference (Scramble) to openapi.json at the root of the repository.
openapi: ## Local stack | Export the reference of the public API to openapi.json
	$(COMPOSE) exec -T api sh -c 'php artisan scramble:export --path=/tmp/openapi.json >/dev/null && cat /tmp/openapi.json' > openapi.json
	@echo "openapi.json written; the reference is also served at http://localhost:8600/docs/api"

# The emulated cloud, one environment at a time with the local one (the same ports), where the application runs as
# Lambda functions behind the edge: stop the processes of the application and the development server of the web app
# of the local stack, start the local AWS emulator (Floci, 127.0.0.1:8604, with the Docker socket), and deploy the stage
# local to it with scripts/deploy.sh, the same script and steps a real account takes (docs/deployment.md): the stacks
# before the functions (cdk bootstrap and cdk deploy), the image of the functions pushed to its ECR, the migrations with
# the console function on the new image first (its own database and migrations identity first), the functions, their
# triggers, the origin verifier and the notifier (osls deploy), the stack edge (the value of the secret of the edge as a
# parameter, piped and never printed), the stack observability, the web app built for production and uploaded to the
# bucket of the edge, the proxy in front of the edge on the ports of the web app and the API, and a smoke test through
# the edge. The steps marked EMULATOR ONLY in the script are workarounds for the emulator. Nothing of the application
# runs all the time. make up goes back to the local stack.
cloud-up: ## Emulated cloud | Deploy the stage local of the AWS architecture to the local emulator, behind the edge
	$(if $(BUILD),$(CLOUD) build cdk functions)
	$(COMPOSE) stop api worker relay web
	$(CLOUD) up -d --wait db floci simulator
	test -d frontend/node_modules || $(COMPOSE) run --rm --no-deps web pnpm install --frozen-lockfile
	$(DEPLOY) stacks
	$(DEPLOY) image > infra/functions/.image
	$(DEPLOY) migrate "$$(cat infra/functions/.image)"
	$(DEPLOY) functions "$$(cat infra/functions/.image)"
	$(DEPLOY) edge
	$(DEPLOY) observability
	$(DEPLOY) panel
	$(DEPLOY) gateway
	$(DEPLOY) smoke
	@echo "Emulated cloud, behind the edge. Web app and API on one origin: http://localhost:8601  API: http://localhost:8600/api/v1/health  API reference: http://localhost:8601/docs/api  DIAN simulator: http://localhost:8602/health  AWS emulator: http://localhost:8604/_floci/health  Its web console: http://localhost:8604/_floci/ui  Emails taken by SES: http://localhost:8604/_aws/ses"

# One step of scripts/deploy.sh against the stage local, as make cloud-up runs it: make cloud-deploy STEP=functions
# IMAGE=<image> (the last one pushed by default), STEP=versions, STEP=rollback-image IMAGE=<earlier image>,
# STEP=rollback-panel...
cloud-deploy: ## Operations of the emulated cloud | One step of scripts/deploy.sh against the stage local: STEP=<step> [IMAGE=<image>]
	$(DEPLOY) $(STEP) $(or $(IMAGE),$(shell cat infra/functions/.image 2>/dev/null))

# The emulated cloud with the synthetic demo data, through the real chain with the AWS adapters (the demo documents
# in the bucket, their emails in SES), run by the console function. The seeder runs the chain of its documents in
# line and holds back the relay function and the scheduled sweepers meanwhile, as make seed stops the local relay.
cloud-demo: cloud-up ## Emulated cloud | cloud-up, then the synthetic demo data through the functions
	$(FUNCTIONS) node scripts/console.mjs migrate:fresh --seed --force --no-interaction

# Stop and remove the containers of the emulated cloud and of the stack it uses, and those its emulator started for
# the functions; every volume is kept.
cloud-down: ## Emulated cloud | Stop the emulated cloud; its state is kept
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down
	@sh scripts/cloud/containers.sh remove

# Like cloud-down, and also remove the state of the emulated cloud: the volume of the emulator (every resource of the
# stage local, the documents, the emails, the keys and the secrets), the volume of the registry behind its ECR, its
# database with its two identities (scripts/cloud/clean.sql), and the settings make cloud-up wrote.
cloud-clean: ## Emulated cloud | Stop the emulated cloud and remove its state
	$(CLOUD) up -d --wait db
	$(COMPOSE) exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysql -uroot' < scripts/cloud/clean.sql
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down
	@sh scripts/cloud/containers.sh remove
	docker volume rm --force dian-einvoice_floci-data
	@sh scripts/cloud/containers.sh remove-volumes
	rm -f infra/functions/.env.cloud infra/functions/.image

# In the emulated cloud (make cloud-up first), on temporary companies of its own, deleted at the end, every step in
# the console function: no process of the application running all the time, the bundle of the RDS certificate
# authorities in the image, every function and trigger present, each function with its identity of the database, the
# migrations identity holding the schema and the application identity refused any change to it, the cold start and
# warm answers of the API, CLOUD_INVOICES invoices through the fast path of the outbox (their time from issue to
# delivery), the session cookie with CSRF and the downloads through API Gateway, a partial batch response with an
# unreadable message, CLOUD_CHAOS_INVOICES more invoices with the containers of the functions killed mid-process and
# an outbox event left for the relay function, nothing done twice, the scheduler run, the topic, the queues, the
# bucket, SES, the tables, KMS and Secrets Manager, and two rotations of the application identity with the functions
# warm, requests and invoices working before and after each; then the observability: every line the functions wrote
# during the check is JSON in the schema of the log lines, an invoice issued through the edge is followed with the
# saved query by the X-Correlation-Id its client got, no log holds a secret or personal data, a metric filter
# produced its metric, and a state forced on an alarm reaches the local receiver of the chat through the notifier.
cloud-check: ## Emulated cloud | The edge, the functions, the queues, the rotations and the observability, end to end
	@sh scripts/cloud-check.sh $(CLOUD_INVOICES)

# In the emulated cloud (make cloud-up first), the real evaluation of alarms, apart from make cloud-check since it
# takes minutes: a message in the dead-letter queue of the chain turns its alarm to ALARM when the emulator publishes
# the metrics of SQS and evaluates it, the notification reaches the local receiver of the chat, and taking the
# message out turns it back to OK; then the receiver refuses a notification, the notifier keeps it in its dead-letter
# queue, and the alarm on its failures reaches the chat.
cloud-alarm-check: ## Emulated cloud | The emulator evaluates an alarm for real, and its notification reaches the chat
	$(FUNCTIONS) node scripts/observability.mjs evaluation

# In the emulated cloud, every line of one journey, in order, with the saved query follow-a-journey: make cloud-follow
# ID=<the X-Correlation-Id of an answer of the API> (HOURS=24 back by default).
cloud-follow: ## Operations of the emulated cloud | Every log line of one journey: ID=<correlation id>
	$(FUNCTIONS) node scripts/observability.mjs follow $(ID) $(or $(HOURS),24)

# In the emulated cloud, the state of every alarm with its runbook, and the last messages the notifier posted to the
# local receiver of the chat.
cloud-alarms: ## Operations of the emulated cloud | The state of every alarm and the last messages of the chat
	$(FUNCTIONS) node scripts/observability.mjs alarms

# The end-to-end test in a browser against the emulated cloud with the demo data (make cloud-demo first): the web app
# as the edge serves it on http://localhost:8601, built for production and under its Content-Security-Policy, the
# same journey as make e2e (sign in, issue an invoice through the functions, see it accepted and delivered, open its
# PDF, record its payment), the API reference, and a tour of every route the router of the web app declares, each a
# deep link with the demo data, with its main dialogs, menus and lists, the command palette, the themes and the hover
# card of a chart; it fails on the first violation of a policy the browser reports.
cloud-e2e: ## Emulated cloud | The end-to-end test and a tour of every route behind the edge, under its CSP
	$(COMPOSE) --profile e2e run --rm --no-deps -T -e WEB_URL=http://cloud-gateway:8000 -e EDGE=1 e2e sh -c 'npm ci --no-audit --no-fund && npx playwright test'

# Run php artisan in the console function of the emulated cloud, as an operator would: make cloud-artisan ARGS="migrate:status".
cloud-artisan: ## Operations of the emulated cloud | Run php artisan in the console function: ARGS="outbox:status"
	$(FUNCTIONS) node scripts/console.mjs $(ARGS)

# The operation commands of the runbooks (docs/runbooks/) in the emulated cloud; a real account takes the AWS CLI
# commands each runbook gives next to them. The messages of a dead-letter queue without consuming them, QUEUE=chain,
# webhooks or notifier: when each came, its type, company, document and journey, and why its job gave up.
cloud-dead-letters: ## Operations of the emulated cloud | List a dead-letter queue without consuming it: QUEUE=chain|webhooks|notifier
	$(FUNCTIONS) node scripts/operations.mjs dead-letters $(or $(QUEUE),chain)

# Every message of a dead-letter queue moved back to its queue, QUEUE=chain or webhooks, with the native redrive of SQS
# (StartMessageMoveTask): their steps start again.
cloud-redrive: ## Operations of the emulated cloud | Move a dead-letter queue back to its queue: QUEUE=chain|webhooks
	$(FUNCTIONS) node scripts/operations.mjs redrive $(or $(QUEUE),chain)

# The dead-letter queue of the notifier emptied, once its notifications were read (docs/runbooks/notifier.md).
cloud-dead-letters-purge: ## Operations of the emulated cloud | Empty the dead-letter queue of the notifier, once read
	$(FUNCTIONS) node scripts/operations.mjs purge-notifier

# The lines of error of the functions in the last MINUTES (60), worst first, FN=<function> for one: the saved query
# errors-by-function as the emulator can run it.
cloud-errors: ## Operations of the emulated cloud | The error lines of the functions in the last MINUTES, FN=<function> for one
	$(FUNCTIONS) node scripts/operations.mjs errors $(or $(FN),all) $(or $(MINUTES),60)

# Each function with its image, reserved concurrency and state, the mappings of the queues, the schedules and the
# concurrency of the account.
cloud-functions: ## Operations of the emulated cloud | Each function with its image, concurrency and state, and the triggers
	$(FUNCTIONS) node scripts/operations.mjs functions

# A rotation out of schedule (docs/runbooks/rotate-secrets.md), SECRET=edge (a new value of the secret of the edge and the
# stack edge deployed with it; the previous value stays valid), chat-webhook URL=<address> (the address the notifier
# posts to), database-application (the alternating rotation of the application identity, twice, with the functions
# warm, as make cloud-check stands in for the rotation function of AWS) or database-migrations (a new password of the
# migrations identity, set on its user as the single-user rotation does).
cloud-rotate: ## Operations of the emulated cloud | Rotate a secret out of schedule: SECRET=edge|chat-webhook|database-application|database-migrations
	@case "$(SECRET)" in \
	  edge) $(FUNCTIONS) node scripts/operations.mjs rotate-edge && $(DEPLOY) edge && $(DEPLOY) smoke ;; \
	  chat-webhook) $(FUNCTIONS) node scripts/operations.mjs chat-webhook "$(URL)" ;; \
	  database-application) $(FUNCTIONS) node scripts/console.mjs cloud:check rotation --timeout=300 ;; \
	  database-migrations) $(FUNCTIONS) node scripts/operations.mjs rotate-migrations && $(FUNCTIONS) node scripts/database.mjs migrations-identity | $(COMPOSE) exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysql -uroot' && $(FUNCTIONS) node scripts/console.mjs migrate:status ;; \
	  *) echo "usage: make cloud-rotate SECRET=edge|chat-webhook|database-application|database-migrations [URL=<address of the chat webhook>]" >&2; exit 2 ;; \
	esac

# In the emulated cloud (make cloud-up first), apart from make cloud-check since it takes about four minutes: the
# incident drill of a DIAN that goes down, on a temporary company of its own deleted at the end (scripts/cloud-drill.sh):
# DRILL_INVOICES invoices of the baseline, then DRILL_INCIDENT_INVOICES issued through the edge while the DIAN answers
# HTTP 500; their transmissions give up into the dead-letter queue of the chain, its alarm reaches the chat, the commands
# of the runbook bring them back with the native redrive of SQS, and every invoice ends accepted and delivered once,
# without a gap. It prints the timeline: the time to detect, to notify and to recover (figures of the emulator); the
# report of one run is docs/runbooks/drill-report.md.
cloud-drill: ## Emulated cloud | Rehearse a DIAN outage: dead letters, alarm, runbook, every invoice recovered once
	@sh scripts/cloud-drill.sh $(DRILL_INVOICES) $(DRILL_INCIDENT_INVOICES)

# Remove this project's containers and volumes, which deletes the database, the queue, the simulator state and the mailbox,
# and what the emulator of the emulated cloud created for this project.
clean: ## Clean up | Remove the containers and volumes of this project: the local data is deleted
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down --volumes --remove-orphans
	@sh scripts/cloud/containers.sh remove-volumes

# Like clean, and also remove the images built for this project.
nuke: ## Clean up | Like clean, and also remove the images built for this project
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down --volumes --remove-orphans --rmi local
	@sh scripts/cloud/containers.sh remove-volumes

# Open a shell in the api container.
sh-api: ## Local stack | Open a shell in the api container
	$(COMPOSE) exec api sh

# Open a shell in the web container.
sh-web: ## Local stack | Open a shell in the web container
	$(COMPOSE) exec web sh

# The screenshots and the GIFs of the README in docs/assets, made by script only (scripts/screenshots.sh): the demo data
# reloaded as make seed does (local data is lost) under the story clock of the demo, Thursday, November 19, 2026, 3 p.m.
# in Medellín, then the browser of profile e2e (e2e/screenshots) at 1440×900, the screenshots at twice the density.
# Any error in the console of the browser or on screen fails it, and so does a screenshot over 400 KB or a GIF over
# 5 MB. The backend and the simulator get the clock of the machine back at the end.
screenshots: up ## Checks of the running stack | Reload the demo data at the story day and write the screenshots and GIFs of docs/assets
	@sh scripts/screenshots.sh
