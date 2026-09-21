SHELL        := /bin/sh
.DEFAULT_GOAL := help

COMPOSE      := docker compose
ALL_PROFILES := $(COMPOSE) --profile test --profile candidate
SUPERVISOR   := $(COMPOSE) exec -T app supervisorctl -c /etc/supervisor/conf.d/supervisord.conf
ARGS         ?=
SERVICES     ?= app
TAIL         ?= 50
BACKUP       ?=

# Installations and database checks must also run in order with make -j.
.NOTPARALLEL:
.PHONY: certificates-shared help first-install install create-env-file check-env-file config-check \
        build-app run up stop down restart restart-background status logs ssh shell \
        composer composer-install composer-update naf migrate roles seed health assets assets-check \
        test test-up test-down test-http test-plugins \
        test-unit test-database test-database-postgres test-worker-suite test-js \
        style-install style-check style-fix backup verify-restore \
        candidate-build candidate-up candidate-down plugin-check certificates supervisor-status mailpit

help: ## Show available commands (the default)
	@awk 'BEGIN { FS = ":.*## " } /^[a-zA-Z_-]+:.*## / { printf "  %-22s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

first-install: create-env-file install ## Prepare .env and install the complete local demo

storage-dirs: ## Create the runtime directories a fresh checkout does not carry
	@mkdir -p app/storage/sessions app/storage/logs app/storage/attachments \
		app/storage/queue app/storage/schedule app/storage/oauth
	@mkdir -p .test-storage/sessions .test-storage/logs .test-storage/attachments \
		.test-storage/queue .test-storage/schedule .test-storage/oauth

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
	@$(ALL_PROFILES) stop

down: check-env-file ## Remove Nafinity containers and network, retaining database and files
	@$(ALL_PROFILES) down

restart: check-env-file ## Restart app, worker and scheduler
	@$(COMPOSE) restart app

restart-background: check-env-file ## Reload worker and scheduler after PHP source changes
	@$(SUPERVISOR) restart queue-worker schedule-ticker

mailpit: config-check ## Start the local test mailbox at http://localhost:8025
	@$(COMPOSE) up -d --wait mailpit

supervisor-status: check-env-file ## Show nginx, PHP-FPM, queue worker and scheduler
	@$(SUPERVISOR) status

status: check-env-file ## Show all Nafinity services
	@$(ALL_PROFILES) ps -a

logs: check-env-file ## Follow logs; optional SERVICES='app db' TAIL=100
	@$(ALL_PROFILES) logs --follow --tail=$(TAIL) $(SERVICES)

ssh: check-env-file ## Open a shell as www in the app container
	@$(COMPOSE) exec --user www app /bin/sh

shell: ssh ## Alias for ssh

composer: check-env-file ## Run development Composer; e.g. ARGS='show naf/framework'
	@bin/dev-composer $(ARGS)

composer-install: check-env-file ## Install dependencies using local NAF sources
	@bin/dev-composer install --no-interaction

composer-update: check-env-file ## Update the development lock using local NAF sources
	@bin/dev-composer update --no-interaction

naf: check-env-file ## Run the NAF CLI; e.g. ARGS='db:migrate up'
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf $(ARGS)

migrate: check-env-file ## Apply app and plugin migrations using NAF
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf db:migrate up

# Roles are declared in code and written once; a package that ships one brings
# it along, and an installation that changed what a role carries keeps that.
websocket: check-env-file ## Run the socket server in the foreground, for watching it work
	@$(COMPOSE) exec app php vendor/bin/naf websocket:serve

websocket-status: check-env-file ## Is the supervised socket server up?
	@$(COMPOSE) exec app sh -c 'ls -l /tmp/naf-websocket.sock 2>/dev/null || echo "kein Steuersocket -- läuft der Server?"'

roles: check-env-file ## Write the roles the installed packages declare
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf rbac:sync

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

# The board is a dependency, and its suite belongs to it. It runs here because
# this is where a container is: the tests boot this installation and reach the
# board through it, which is also what a person gets. BOARD is the working copy
# among the other NAF packages; in the container it is mounted at /var/board.
BOARD ?= ../nafphp/board
BOARD_IN_CONTAINER = /var/board
BOARD_TEST = -e NAF_HOST=/var/www
# The host serves the certificate and holds docker/; the board ships neither.
BOARD_HOST_ENV = NAF_HOST_CA=$(CURDIR)/docker/rootfs/etc/nginx/ssl/ca.pem \
	NAF_HOST_ROOT=$(CURDIR)

test: test-unit test-database test-database-postgres test-worker-suite test-http test-js test-plugins ## Run every suite: unit, both databases, worker, HTTP and the extension hosts

# --reapply on the sync, which a real installation must never get: there the
# rule is that whatever an installation changed about a declared role is its own
# and survives an upgrade. The price of that rule is that a permission declared
# after the roles were written reaches nobody until somebody grants it -- which
# is right for a customer and wrong for a database that exists to check what the
# code currently declares.
test-up: config-check certificates storage-dirs ## Prepare nafinity_test and start the isolated test services
	@$(COMPOSE) up -d --wait db
	@bin/prepare-test-database
	@$(COMPOSE) --profile test up -d app-test postgres
	@$(COMPOSE) exec -T app-test php vendor/bin/naf db:migrate up
	@$(COMPOSE) exec -T app-test php vendor/bin/naf rbac:sync --reapply
	@$(COMPOSE) --profile test up -d --wait app-test postgres

test-down: check-env-file ## Stop test services, preserving the development environment
	@$(COMPOSE) --profile test stop app-test postgres

PHPUNIT = php /var/www/vendor/bin/phpunit -c $(BOARD_IN_CONTAINER)/phpunit.xml

test-unit: test-up ## Run the board's unit tests: no database, no services, no fixtures
	@$(COMPOSE) exec -T $(BOARD_TEST) app-test $(PHPUNIT) --testsuite Unit

test-database: test-up ## Run the board's database tests against MariaDB
	@$(COMPOSE) exec -T $(BOARD_TEST) app-test $(PHPUNIT) --testsuite Database

test-database-postgres: test-up ## Run the board's database tests against PostgreSQL
	@$(COMPOSE) exec -T $(BOARD_TEST) -e DB_DRIVER=pgsql -e DB_HOST=postgres -e DB_PORT=5432 app-test $(PHPUNIT) --testsuite Database

test-worker-suite: test-up ## Run the board's worker tests: a real worker, really killed
	@$(COMPOSE) exec -T $(BOARD_TEST) app-test $(PHPUNIT) --testsuite Worker

# The acceptance suite works on the demo data, because that is what a new
# installation is given -- so this also checks the seed produces something the
# application can serve.
test-http: test-database ## Seed the disposable database and check the application over HTTPS
	@$(COMPOSE) exec -T app-test php vendor/bin/naf nafinity:seed
	@$(COMPOSE) exec -T $(BOARD_TEST) app-test $(PHPUNIT) --testsuite Http

# naf/websocket ships a browser module too, and the rules in it -- which answer
# to a token request means retry and which means give up -- are the kind that is
# easy to get subtly wrong and impossible to notice.
SOCKETS ?= ../nafphp/websocket

test-js: ## Run the JavaScript tests of naf/board and naf/websocket with node's own runner
	@node --test $(BOARD)/tests/js/*.test.js $(SOCKETS)/tests/js/*.test.js

test-plugins: test-up ## Boot Nafinity with and without both example extensions
	@node bin/check-extensions

style-install: check-env-file ## Install the pinned development formatters
	@bin/style install

style-check: check-env-file ## Check PER Coding Style, JavaScript, CSS and Python formatting
	@bin/style check

style-fix: check-env-file ## Apply the project's code formatting rules
	@bin/style fix

backup: check-env-file ## Back up the development database and private files
	@bin/backup

verify-restore: check-env-file ## Verify a backup in nafinity_restore_test; BACKUP=work/backups/...
	@test -n "$(BACKUP)" || { echo 'Pass BACKUP=work/backups/TIMESTAMP.' >&2; exit 2; }
	@bin/verify-restore "$(BACKUP)"

candidate-build: ## Build the immutable local RC snapshot image
	@bin/build-candidate

candidate-up: check-env-file certificates ## Start the previously built snapshot at https://localhost:8445
	@$(COMPOSE) --profile candidate up -d --wait candidate

candidate-down: check-env-file ## Stop the local snapshot container
	@$(COMPOSE) --profile candidate stop candidate

plugin-check: check-env-file ## Boot minimal and complete NAF plugin stacks in isolated hosts
	@bin/check-plugin-stack
