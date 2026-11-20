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
# functions, the CDK app, the serverless service of the functions (osls) and the proxy in front of API Gateway.
CLOUD := $(COMPOSE) --profile cloud
# What make up stops of the emulated cloud: the proxy on the port of the API, and the emulator, which runs the
# functions (scripts/cloud/containers.sh then removes the containers it started for them).
CLOUD_APP := cloud-gateway floci
# osls and the scripts of the functions, on the network of the stack.
FUNCTIONS := $(CLOUD) run --rm --no-deps -T functions-deploy

# Size of make cloud-check: invoices of the fast path, then those issued while the functions are killed.
CLOUD_INVOICES ?= 30
CLOUD_CHAOS_INVOICES ?= 10
export CLOUD_CHAOS_INVOICES

# docker compose builds the images that changed before using them. CI builds them beforehand with its layer
# cache and sets BUILD empty, so compose uses them as they are.
BUILD ?= --build

TARGETS := up down logs ps seed demo stress chaos dian-check dian-schematron delivery-check clean nuke sh-api sh-web screenshots payments-check api-check openapi ui-check lint analyse fix test test-backend test-frontend test-simulator test-infra e2e checks security cloud-up cloud-demo cloud-down cloud-clean cloud-check cloud-artisan cdk-check

.PHONY: env $(TARGETS)

# Every target runs compose with backend/.env: it is created and its secrets filled in first.
$(filter-out screenshots,$(TARGETS)): env

# backend/.env from backend/.env.example when missing, with a random Redis password and simulator admin
# token when they are empty (scripts/env.sh). Never committed; values already set are kept.
env:
	@sh scripts/env.sh backend/.env

# Build images if needed, start the stack, wait for the database, Redis and the local mailbox, install missing dependencies,
# generate the key, migrate, and start the queue workers and the outbox relay with the current code.
up:
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
down:
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down

# Follow the logs of every service.
logs:
	$(COMPOSE) logs -f --tail=100

# Show the state of this project's containers, with the result of the healthcheck of each service.
ps:
	$(COMPOSE) ps

# Rebuild the database from scratch and load the synthetic demo data; local data is lost. The workers and
# the relay are stopped meanwhile: the demo runs its invoices through the chain itself, in line.
seed:
	$(COMPOSE) stop worker relay
	$(COMPOSE) exec api php artisan migrate:fresh --seed --force --no-interaction || { $(COMPOSE) up -d worker relay; exit 1; }
	$(COMPOSE) up -d worker relay

# Start the stack and load the synthetic demo data.
demo: up
	@$(MAKE) --no-print-directory seed

# On a temporary company, issue INVOICES invoices from CONCURRENCY parallel processes, check the numbers have
# no gaps or repeats, and time the whole chain from issue to acceptance by the DIAN simulator.
stress:
	$(COMPOSE) exec api php artisan billing:stress --invoices=$(INVOICES) --concurrency=$(CONCURRENCY)

# On a temporary company, issue CHAOS_INVOICES invoices, kill both workers and the relay while the chain runs,
# start them again, restart the DIAN simulator, and check every invoice ends accepted once, without gaps.
chaos:
	@sh scripts/chaos.sh $(CHAOS_INVOICES)

# Check the CUFE against the DIAN annex example, validate an issued demo invoice XML against the official XSD, sign it and verify the signature with xmlsec1 (and that a tampered copy fails).
dian-check:
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

dian-schematron:
	$(COMPOSE) --profile tools run --rm --no-deps -T schematron

# On an accepted demo invoice: validate its AttachedDocument against the official XSD (signed invoice and
# ApplicationResponse inside), read its PDF with poppler (pages, number, CUFE), and find its email in the local mailbox.
delivery-check:
	$(COMPOSE) exec api php artisan delivery:check

# On a temporary company of its own, deleted at the end: an accepted invoice and 20 simultaneous payments
# of a tenth of its balance. Exactly 10 must fit, the balance must end at zero, and the receipts have no gaps.
payments-check:
	$(COMPOSE) exec api php artisan payments:check

# On a temporary company of its own, deleted at the end, and over HTTP with API keys only: idempotent issue
# (repeat, other body, 10 at once), a read-only key refused, signed webhooks in the local receiver, a
# webhook retried after two HTTP 500, and the rate limit answering 429 with Retry-After.
api-check:
	$(COMPOSE) exec api php artisan api:check

# Type-check and build the web app for production (into /tmp of its container), then sign in over HTTP as the synthetic demo user and check that GET /dashboard and GET /search?q=SETP of Demo Comercial SAS return data.
ui-check:
	$(COMPOSE) exec -T web sh -c 'pnpm typecheck && pnpm exec vite build --outDir /tmp/ui-check-dist --emptyOutDir'
	$(COMPOSE) exec -T api php artisan ui:check

# Formatters and linters, checking only: Laravel Pint for the backend, ESLint and Prettier for the web app, ESLint
# for the simulator and the CDK app, and the syntax of the scripts of the functions. Each one runs in a throwaway
# container of its service; the stack need not be up.
lint:
	$(COMPOSE) run --rm --no-deps -T api vendor/bin/pint --test
	$(COMPOSE) run --rm --no-deps -T web sh -c 'pnpm lint && pnpm format:check'
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm run lint
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm run lint
	$(CLOUD) run --rm --no-deps $(BUILD) -T functions sh -c 'for script in scripts/*.mjs; do node --check "$$script" || exit 1; done'

# Static analysis: PHPStan with Larastan (level in backend/phpstan.neon, no baseline), vue-tsc and tsc (simulator and
# CDK app), all strict.
analyse:
	$(COMPOSE) --profile tools run --rm --no-deps -T api-analyse vendor/bin/phpstan analyse --no-progress --memory-limit=1800M
	$(COMPOSE) run --rm --no-deps -T web pnpm typecheck
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm run typecheck
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm run typecheck

# Apply the formatters and the automatic fixes of the linters.
fix:
	$(COMPOSE) run --rm --no-deps -T api vendor/bin/pint
	$(COMPOSE) run --rm --no-deps -T web pnpm fix
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm run fix
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm run fix

# Security of the dependencies and the repository, also a job of CI. Known vulnerabilities of the backend, the
# web app, the simulator, the CDK app, the serverless service of the functions and the end-to-end test: high and
# critical ones fail. Production dependencies whose
# license is only GPL or AGPL fail (scripts/security/licenses.mjs). Secrets in the git history and in the
# changes not committed yet fail (gitleaks, profile security, rules in .gitleaks.toml). Each step runs in a
# container; the audits ask the public advisory databases of Packagist and npm.
security:
	$(COMPOSE) run --rm --no-deps -T api composer audit --locked --no-interaction --abandoned=report --ignore-severity=low --ignore-severity=medium
	$(COMPOSE) run --rm --no-deps -T web pnpm audit --audit-level high
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev sh -c 'cd /workspace && npm audit --audit-level=high'
	$(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/infra/cdk:/infra:ro web sh -c 'cd /infra && npm audit --audit-level=high --package-lock-only'
	$(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/infra/functions:/infra:ro web sh -c 'cd /infra && npm audit --audit-level=high --package-lock-only'
	$(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/e2e:/e2e:ro web sh -c 'cd /e2e && npm audit --audit-level=high --package-lock-only'
	$(COMPOSE) run --rm --no-deps -T api composer licenses --no-dev --format=json | $(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/scripts/security:/security:ro web node /security/licenses.mjs composer backend
	$(COMPOSE) run --rm --no-deps -T -v $(CURDIR)/scripts/security:/security:ro web sh -c 'pnpm licenses list --prod --json | node /security/licenses.mjs pnpm web-app'
	$(COMPOSE) --profile tools run --rm -T -v $(CURDIR)/scripts/security:/security:ro simulator-dev sh -c 'cd /workspace && npm ls --omit=dev --all --parseable | node /security/licenses.mjs npm simulator'
	$(CLOUD) run --rm --no-deps -T -v $(CURDIR)/scripts/security:/security:ro cdk sh -c 'cd /workspace && npm ls --omit=dev --all --parseable | node /security/licenses.mjs npm cdk-app'
	$(CLOUD) run --rm --no-deps -T -v $(CURDIR)/scripts/security:/security:ro functions sh -c 'cd /workspace && npm ls --omit=dev --all --parseable | node /security/licenses.mjs npm functions'
	$(COMPOSE) --profile security run --rm -T gitleaks git /repo --config /repo/.gitleaks.toml --redact --no-banner
	$(COMPOSE) --profile security run --rm -T gitleaks git /repo --config /repo/.gitleaks.toml --redact --no-banner --pre-commit
	$(COMPOSE) --profile security run --rm -T gitleaks git /repo --config /repo/.gitleaks.toml --redact --no-banner --pre-commit --staged

# Every test that needs no browser: backend, web app, simulator and CDK app.
test: test-backend test-frontend test-simulator test-infra

# Unit tests of the backend, then its integration tests against the real MySQL (database dian_einvoice_test, created
# here and recreated by the tests), Redis (databases 3 and 4, not those of the stack) and the local DIAN simulator.
test-backend:
	$(COMPOSE) up -d $(BUILD) --wait db redis simulator
	$(COMPOSE) exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysql -uroot' < backend/tests/database.sql
	$(COMPOSE) run --rm $(BUILD) --no-deps -T api vendor/bin/phpunit

# Unit tests of the web app (Vitest).
test-frontend:
	$(COMPOSE) run --rm --no-deps -T web pnpm test

# Tests of the simulator (node:test): its rules, failure modes and webhook receiver.
test-simulator:
	$(COMPOSE) --profile tools run --rm $(BUILD) -T simulator-dev npm test

# Tests of the CDK app (node:test), without a network: assertions on the synthesized templates of both stages, on
# the templates osls packages for the functions (both stages, packaged first, without a network or credentials),
# and cdk-nag (AWS Solutions) over both, with every exception acknowledged with its reason.
test-infra:
	$(CLOUD) run --rm --no-deps $(BUILD) -T functions sh -c 'npm run --silent package:local && npm run --silent package:prod'
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk npm test

# The infrastructure as code as the CI job of the infrastructure checks it, without a network: the templates of the
# functions packaged by osls for both stages, the tests of the CDK app, cdk synth of both stages, which stops on any
# finding of cdk-nag (report in infra/cdk/cdk.out/validation-report.json), and cfn-lint over every template.
cdk-check:
	$(CLOUD) run --rm --no-deps $(BUILD) -T functions sh -c 'npm run --silent package:local && npm run --silent package:prod'
	$(CLOUD) run --rm --no-deps $(BUILD) -T cdk sh -c 'npm test && npm run synth'
	$(CLOUD) run --rm --no-deps $(BUILD) -T cfn-lint

# The end-to-end test in a browser (Playwright, profile e2e) against the full stack with the demo data (make demo):
# sign in, issue an invoice, see it accepted and delivered, open its PDF, record its payment and see it paid.
e2e:
	$(COMPOSE) --profile e2e run --rm --no-deps -T e2e sh -c 'npm ci --no-audit --no-fund && npx playwright test'

# The checks of the stack, in a row, against the demo data (make demo). make dian-schematron stays optional, apart.
checks:
	@$(MAKE) --no-print-directory ui-check
	@$(MAKE) --no-print-directory dian-check
	@$(MAKE) --no-print-directory delivery-check
	@$(MAKE) --no-print-directory payments-check
	@$(MAKE) --no-print-directory api-check
	@$(MAKE) --no-print-directory stress
	@$(MAKE) --no-print-directory chaos

# Export the public API reference (Scramble) to openapi.json at the root of the repository.
openapi:
	$(COMPOSE) exec -T api sh -c 'php artisan scramble:export --path=/tmp/openapi.json >/dev/null && cat /tmp/openapi.json' > openapi.json
	@echo "openapi.json written; the reference is also served at http://localhost:8600/docs/api"

# The emulated cloud, one environment at a time with the local one (the same ports), where the application runs as
# Lambda functions: stop the processes of the application of the local stack, start the local AWS emulator (Floci,
# 127.0.0.1:8604, with the Docker socket), deploy the CDK stage local to it with the normal CDK commands (cdk
# bootstrap, cdk deploy), give it a database of its own, push the image of the functions to its ECR, deploy the
# functions and their triggers with osls, start the proxy in front of API Gateway on the port of the API, and run the
# migrations with the console function. Nothing of the application runs all the time. make up goes back to the local stack.
cloud-up:
	$(if $(BUILD),$(CLOUD) build cdk functions lambda)
	$(COMPOSE) stop api worker relay
	$(CLOUD) up -d --wait db floci simulator
	test -d frontend/node_modules || $(COMPOSE) run --rm --no-deps web pnpm install --frozen-lockfile
	$(CLOUD) run --rm --no-deps -T cdk-deploy sh -c 'cdk bootstrap aws://000000000000/us-east-1 && cdk deploy "local/*" --require-approval never --outputs-file cdk.out/outputs-local.json'
	$(COMPOSE) exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysql -uroot' < scripts/cloud/database.sql
	sh scripts/cloud/push-image.sh > infra/functions/.image
	$(FUNCTIONS) sh -c 'osls deploy --stage local --param="image=$$(cat .image)" --param="simulatorToken=$$SIMULATOR_TOKEN"'
	$(FUNCTIONS) node scripts/gateway-env.mjs > infra/functions/.env.cloud
	$(CLOUD) up -d --wait --force-recreate cloud-gateway
	$(FUNCTIONS) node scripts/console.mjs migrate --force --no-interaction
	$(CLOUD) up -d --no-deps web
	@echo "Emulated cloud. Web app: http://localhost:8601  API (API Gateway and Lambda): http://localhost:8600/api/v1/health  API reference: http://localhost:8600/docs/api  DIAN simulator: http://localhost:8602/health  AWS emulator: http://localhost:8604/_floci/health  Its web console: http://localhost:8604/_floci/ui  Emails taken by SES: http://localhost:8604/_aws/ses"

# The emulated cloud with the synthetic demo data, through the real chain with the AWS adapters (the demo documents
# in the bucket, their emails in SES), run by the console function. The seeder runs the chain of its documents in
# line and holds back the relay function and the scheduled sweepers meanwhile, as make seed stops the local relay.
cloud-demo: cloud-up
	$(FUNCTIONS) node scripts/console.mjs migrate:fresh --seed --force --no-interaction

# Stop and remove the containers of the emulated cloud and of the stack it uses, and those its emulator started for
# the functions; every volume is kept.
cloud-down:
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down
	@sh scripts/cloud/containers.sh remove

# Like cloud-down, and also remove the state of the emulated cloud: the volume of the emulator (every resource of the
# stage local, the documents, the emails, the keys and the secret), the volume of the registry behind its ECR, its
# database, and the settings make cloud-up wrote.
cloud-clean:
	$(CLOUD) up -d --wait db
	$(COMPOSE) exec -T db sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE IF EXISTS dian_einvoice_cloud"'
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down
	@sh scripts/cloud/containers.sh remove
	docker volume rm --force dian-einvoice_floci-data
	@sh scripts/cloud/containers.sh remove-volumes
	rm -f infra/functions/.env.cloud infra/functions/.image

# In the emulated cloud (make cloud-up first), on a temporary company of its own, deleted at the end, every step in
# the console function: no process of the application running all the time, every function and trigger present, the
# cold start and warm answers of the API, CLOUD_INVOICES invoices through the fast path of the outbox (their time
# from issue to delivery), the session cookie with CSRF and the downloads through API Gateway, a partial batch
# response with an unreadable message, CLOUD_CHAOS_INVOICES more invoices with the containers of the functions killed
# mid-process and an outbox event left for the relay function, nothing done twice, the scheduler run, and the topic,
# the queues, the bucket, SES, the tables, KMS and Secrets Manager.
cloud-check:
	@sh scripts/cloud-check.sh $(CLOUD_INVOICES)

# Run php artisan in the console function of the emulated cloud, as an operator would: make cloud-artisan ARGS="migrate:status".
cloud-artisan:
	$(FUNCTIONS) node scripts/console.mjs $(ARGS)

# Remove this project's containers and volumes, which deletes the database, the queue, the simulator state and the mailbox,
# and what the emulator of the emulated cloud created for this project.
clean:
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down --volumes --remove-orphans
	@sh scripts/cloud/containers.sh remove-volumes

# Like clean, and also remove the images built for this project.
nuke:
	@sh scripts/cloud/containers.sh remove
	$(CLOUD) down --volumes --remove-orphans --rmi local
	@sh scripts/cloud/containers.sh remove-volumes

# Open a shell in the api container.
sh-api:
	$(COMPOSE) exec api sh

# Open a shell in the web container.
sh-web:
	$(COMPOSE) exec web sh

# Capture interface screenshots; arrives in the Polish phase.
screenshots:
	@echo "screenshots: implemented in the Polish phase."
