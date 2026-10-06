SHELL        := /bin/sh
.DEFAULT_GOAL := help

COMPOSE      := docker compose
SUPERVISOR   := $(COMPOSE) exec -T app supervisorctl -c /etc/supervisor/conf.d/supervisord.conf
ARGS         ?=
SERVICES     ?= app
TAIL         ?= 50
BACKUP       ?=

# Installations and database checks must also run in order with make -j.
.NOTPARALLEL:
.PHONY: help first-install storage-dirs install assets create-env-file check-env-file \
        certificates certificates-shared config-check build-app run up stop down restart \
        restart-background mailpit supervisor-status status logs ssh shell \
        composer composer-install composer-update naf migrate roles seed websocket websocket-status \
        health assets-check style-install style-check style-fix backup verify-restore

help: ## Show available commands (the default)
	@awk 'BEGIN { FS = ":.*## " } /^[a-zA-Z_-]+:.*## / { printf "  %-22s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

first-install: create-env-file install ## Prepare .env and install the complete local demo

storage-dirs: ## Create the runtime directories a fresh checkout does not carry
	@mkdir -p app/storage/sessions app/storage/logs app/storage/attachments \
		app/storage/queue app/storage/schedule app/storage/oauth

install: check-env-file certificates storage-dirs ## Build, install dependencies, migrate, seed and start all dev services
	@$(MAKE) build-app
	@$(MAKE) composer-install
	@$(COMPOSE) up -d --wait db
	@$(MAKE) migrate
	@$(MAKE) roles
	@$(MAKE) assets
	@$(MAKE) seed
	@$(MAKE) run
	@$(MAKE) health

assets: check-env-file ## Copy the stylesheets and scripts of naf/board and every plugin into public/
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf nafinity:assets:publish

create-env-file: ## Create a private .env with random local passwords if missing
	@node bin/init-env

check-env-file:
	@test -f .env || { echo 'Run make first-install, or copy .env.example and set both passwords.' >&2; exit 2; }

# A certificate authority kept outside every project, so it survives a
# restructuring of this one and a machine only has to trust it once.
CERT_AUTHORITY ?= ../cert-authority

certificates: ## Issue the HTTPS certificate, from $(CERT_AUTHORITY) when it is there
	@if [ -f "$(CERT_AUTHORITY)/ca/ca-key.pem" ]; then \
		$(MAKE) --no-print-directory certificates-shared; \
	else \
		node bin/generate-certificates; \
	fi

certificates-shared:
	@if node bin/certificate-is-current "$(CERT_AUTHORITY)/ca/ca.pem"; then \
		echo "Existing certificate from $(CERT_AUTHORITY) retained."; \
	else \
		$(MAKE) -C "$(CERT_AUTHORITY)" --no-print-directory cert HOST=localhost; \
		$(MAKE) -C "$(CERT_AUTHORITY)" --no-print-directory install HOST=localhost \
			TO="$(CURDIR)/docker/rootfs/etc/nginx/ssl"; \
		echo "Reissued from $(CERT_AUTHORITY); restart the services to serve it."; \
	fi

config-check: check-env-file ## Validate Compose without printing secrets
	@$(COMPOSE) config --quiet

build-app: config-check ## Build the development image
	@$(COMPOSE) build app

run: config-check certificates ## Start app, database, queue worker and scheduler; wait for health
	@$(COMPOSE) up -d --wait app

up: run ## Alias for run

stop: check-env-file ## Stop all Nafinity services, retaining containers and data
	@$(COMPOSE) stop

down: check-env-file ## Remove Nafinity containers and network, retaining database and files
	@$(COMPOSE) down

restart: check-env-file ## Restart app, worker and scheduler
	@$(COMPOSE) restart app

restart-background: check-env-file ## Reload worker and scheduler after PHP source changes
	@$(SUPERVISOR) restart queue-worker schedule-ticker

mailpit: config-check ## Start the local test mailbox at http://localhost:8025
	@$(COMPOSE) up -d --wait mailpit

supervisor-status: check-env-file ## Show nginx, PHP-FPM, queue worker and scheduler
	@$(SUPERVISOR) status

status: check-env-file ## Show all Nafinity services
	@$(COMPOSE) ps -a

logs: check-env-file ## Follow logs; optional SERVICES='app db' TAIL=100
	@$(COMPOSE) logs --follow --tail=$(TAIL) $(SERVICES)

ssh: check-env-file ## Open a shell as www in the app container
	@$(COMPOSE) exec --user www app /bin/sh

shell: ssh ## Alias for ssh

composer: check-env-file ## Run Composer in the container; e.g. ARGS='show naf/board'
	@bin/composer $(ARGS)

composer-install: check-env-file ## Install dependencies (NAF working copies when compose.dev.yaml is on)
	@bin/composer install --no-interaction

composer-update: check-env-file ## Update dependencies (NAF working copies when compose.dev.yaml is on)
	@bin/composer update --no-interaction

naf: check-env-file ## Run the NAF CLI; e.g. ARGS='db:migrate up'
	@bin/naf $(ARGS)

migrate: check-env-file ## Apply app and plugin migrations using NAF
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf db:migrate up

# Roles are declared in code and written once; a package that ships one brings
# it along, and an installation that changed what a role carries keeps that.
roles: check-env-file ## Write the roles the installed packages declare
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf rbac:sync

websocket: check-env-file ## Run the socket server in the foreground, for watching it work
	@$(COMPOSE) exec app php vendor/bin/naf websocket:serve

websocket-status: check-env-file ## Is the supervised socket server up?
	@$(COMPOSE) exec app sh -c 'ls -l /tmp/naf-websocket.sock 2>/dev/null || echo "No control socket -- is the server running?"'

seed: check-env-file ## Add demo data only when the development database is empty
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf nafinity:seed

health: check-env-file ## Check app readiness (database, migrations, storage and published assets)
	@$(COMPOSE) exec -T app nafinity-healthcheck
	@$(COMPOSE) exec -T app curl --fail --silent --show-error --cacert /etc/nginx/ssl/ca.pem https://localhost:8443/health/ready
	@printf '\n'
	@$(MAKE) --no-print-directory assets-check

# A package registers its stylesheets and scripts, and a separate step copies
# them into public/. So a package added later is registered and absent at the
# same time -- which the browser reports as a 404 on a module tag, which is to
# say silently. The comparison already exists; running it here is what turns
# that silence into a sentence. Quiet when everything matches.
assets-check: check-env-file ## Report registered package assets that were never published
	@report=`$(COMPOSE) exec -T app php vendor/bin/naf nafinity:assets:check` || { \
		printf '%s\n' "$$report"; \
		echo 'Published assets are not what the packages ship -- run make assets.' >&2; \
		exit 1; \
	}

style-install: check-env-file ## Install the pinned php-cs-fixer
	@bin/style install

style-check: check-env-file ## Check this installation's PHP against PER Coding Style
	@bin/style check

style-fix: check-env-file ## Apply PER Coding Style to this installation's PHP
	@bin/style fix

backup: check-env-file ## Back up the development database and private files
	@bin/backup

verify-restore: check-env-file ## Verify a backup in nafinity_restore_test; BACKUP=work/backups/...
	@test -n "$(BACKUP)" || { echo 'Pass BACKUP=work/backups/TIMESTAMP.' >&2; exit 2; }
	@bin/verify-restore "$(BACKUP)"

