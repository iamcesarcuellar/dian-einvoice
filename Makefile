# Local development commands. Everything runs inside Docker Compose; nothing is installed on the host.

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

COMPOSE := docker compose

# Size of make stress.
INVOICES ?= 60
CONCURRENCY ?= 20

# Size of make chaos.
CHAOS_INVOICES ?= 40

.PHONY: up down logs ps seed demo stress chaos dian-check dian-schematron delivery-check clean nuke sh-api sh-web screenshots payments-check api-check openapi ui-check

# Build images if needed, start the stack, wait for the database, Redis and the local mailbox, install missing dependencies,
# generate the key, migrate, and start the queue workers and the outbox relay with the current code.
up:
	$(COMPOSE) build
	$(COMPOSE) up -d --wait db redis mail
	test -f backend/.env || cp backend/.env.example backend/.env
	test -d backend/vendor || $(COMPOSE) run --rm --no-deps api composer install --no-interaction
	grep -Eq '^APP_KEY=.+' backend/.env || $(COMPOSE) run --rm --no-deps api php artisan key:generate --no-interaction
	test -d frontend/node_modules || $(COMPOSE) run --rm --no-deps web pnpm install --frozen-lockfile
	$(COMPOSE) up -d api web simulator
	$(COMPOSE) exec api php artisan migrate --force --no-interaction
	$(COMPOSE) up -d --force-recreate worker relay
	@echo "Web app: http://localhost:8601  API: http://localhost:8600/api/v1/health  API reference: http://localhost:8600/docs/api  DIAN simulator and webhook receiver: http://localhost:8602/health  Local mailbox: http://localhost:8603"

# Stop and remove this project's containers; the database, Redis, simulator and mailbox volumes are kept.
down:
	$(COMPOSE) down

# Follow the logs of every service.
logs:
	$(COMPOSE) logs -f --tail=100

# Show the state of this project's containers.
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
	$(COMPOSE) exec -T web sh -c 'pnpm exec vue-tsc --noEmit && pnpm exec vite build --outDir /tmp/ui-check-dist --emptyOutDir'
	$(COMPOSE) exec -T api php artisan ui:check

# Export the public API reference (Scramble) to openapi.json at the root of the repository.
openapi:
	$(COMPOSE) exec -T api sh -c 'php artisan scramble:export --path=/tmp/openapi.json >/dev/null && cat /tmp/openapi.json' > openapi.json
	@echo "openapi.json written; the reference is also served at http://localhost:8600/docs/api"

# Remove this project's containers and volumes, which deletes the database, the queue, the simulator state and the mailbox.
clean:
	$(COMPOSE) down --volumes --remove-orphans

# Like clean, and also remove the images built for this project.
nuke:
	$(COMPOSE) down --volumes --remove-orphans --rmi local

# Open a shell in the api container.
sh-api:
	$(COMPOSE) exec api sh

# Open a shell in the web container.
sh-web:
	$(COMPOSE) exec web sh

# Capture interface screenshots; arrives in the Polish phase.
screenshots:
	@echo "screenshots: implemented in the Polish phase."
