# Local development commands. Everything runs inside Docker Compose; nothing is installed on the host.

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

COMPOSE := docker compose

# Size of make stress.
INVOICES ?= 60
CONCURRENCY ?= 20

# Size of make chaos.
CHAOS_INVOICES ?= 40

.PHONY: up down logs ps seed demo stress chaos dian-check delivery-check clean nuke sh-api sh-web screenshots payments-check

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
	@echo "Web app: http://localhost:8601  API: http://localhost:8600/api/v1/health  DIAN simulator: http://localhost:8602/health  Local mailbox: http://localhost:8603"

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

# On an accepted demo invoice: validate its AttachedDocument against the official XSD (signed invoice and
# ApplicationResponse inside), read its PDF with poppler (pages, number, CUFE), and find its email in the local mailbox.
delivery-check:
	$(COMPOSE) exec api php artisan delivery:check

# On a temporary company of its own, deleted at the end: an accepted invoice and 20 simultaneous payments
# of a tenth of its balance. Exactly 10 must fit, the balance must end at zero, and the receipts have no gaps.
payments-check:
	$(COMPOSE) exec api php artisan payments:check

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
