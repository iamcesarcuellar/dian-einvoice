# Local development commands. Everything runs inside Docker Compose; nothing is installed on the host.

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

COMPOSE := docker compose

# Size of make stress.
INVOICES ?= 60
CONCURRENCY ?= 20

.PHONY: up down logs ps seed demo stress dian-check clean nuke sh-api sh-web screenshots

# Build images if needed, start the stack, wait for the database, install missing dependencies, generate the key and migrate.
up:
	$(COMPOSE) build
	$(COMPOSE) up -d --wait db
	test -f backend/.env || cp backend/.env.example backend/.env
	test -d backend/vendor || $(COMPOSE) run --rm --no-deps api composer install --no-interaction
	grep -Eq '^APP_KEY=.+' backend/.env || $(COMPOSE) run --rm --no-deps api php artisan key:generate --no-interaction
	test -d frontend/node_modules || $(COMPOSE) run --rm --no-deps web pnpm install --frozen-lockfile
	$(COMPOSE) up -d api web
	$(COMPOSE) exec api php artisan migrate --force --no-interaction
	@echo "Web app: http://localhost:8601  API: http://localhost:8600/api/v1/health"

# Stop and remove this project's containers; the database volume is kept.
down:
	$(COMPOSE) down

# Follow the logs of every service.
logs:
	$(COMPOSE) logs -f --tail=100

# Show the state of this project's containers.
ps:
	$(COMPOSE) ps

# Rebuild the database from scratch and load the synthetic demo data; local data is lost.
seed:
	$(COMPOSE) exec api php artisan migrate:fresh --seed --force --no-interaction

# Start the stack and load the synthetic demo data.
demo: up
	@$(MAKE) --no-print-directory seed

# Issue INVOICES invoices from CONCURRENCY parallel processes on a temporary company and check the numbers have no gaps or repeats.
stress:
	$(COMPOSE) exec api php artisan billing:stress --invoices=$(INVOICES) --concurrency=$(CONCURRENCY)

# Compare the CUFE of the DIAN annex example with its published result and validate the XML of an issued demo invoice against the official XSD.
dian-check:
	$(COMPOSE) exec api php artisan dian:check

# Remove this project's containers and volumes, which deletes the database.
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
