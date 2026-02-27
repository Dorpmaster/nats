.SILENT: ;               # no need for @
.ONESHELL: ;             # recipes execute in same shell
.NOTPARALLEL: ;          # wait for this target to finish
.EXPORT_ALL_VARIABLES: ; # send all vars to shell
default: help ;   		 # default target
Makefile: ;              # skip prerequisite

# use the rest as arguments for "run"
RUN_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
NATS_COMPOSE_FILE := docker/compose.nats.test.yml
NATS_PROJECT := nats-it
NATS_NETWORK := nats-client-test-network
DOCKER_SOCKET_HOST := $(shell if [ -S /var/run/docker.sock ]; then echo /var/run/docker.sock; elif [ -S $$HOME/.docker/run/docker.sock ]; then echo $$HOME/.docker/run/docker.sock; fi)
NATS_CONTAINER := $(NATS_PROJECT)-nats-1
# ...and turn them into do-nothing targets
$(eval $(RUN_ARGS):;@:)

.PHONY: help
help:
	echo "To be implemented"

.PHONY: build
build:
	docker build -t nats-client .

.PHONY: up
up:
	docker compose up -d

#.PHONY: watch
#watch: build
#	docker compose watch

.PHONY: down
down:
	docker compose down --timeout=0 --volumes --remove-orphans

.PHONY: nats-up
nats-up:
	if ! docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_NETWORK); \
	fi
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) up -d --wait --remove-orphans

.PHONY: nats-down
nats-down:
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) down --timeout=0 --volumes --remove-orphans
	if docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then \
		docker network rm $(NATS_NETWORK); \
	fi

.PHONY: nats-restart
nats-restart:
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) restart nats

.PHONY: nats-stop
nats-stop:
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) stop nats

.PHONY: nats-start
nats-start:
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) start nats

.PHONY: nats-kill
nats-kill:
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) kill nats

.PHONY: composer
composer: build
	docker run --rm --interactive \
		--volume $(PWD):/app \
		--workdir /app \
		nats-client composer $(RUN_ARGS)

.PHONY: phpunit
phpunit: build
	docker run --rm --interactive \
		nats-client composer phpunit

.PHONY: integration
integration: build
	set -e
	if [ -z "$(DOCKER_SOCKET_HOST)" ]; then echo "Docker socket not found"; exit 1; fi
	trap 'docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_NETWORK); fi' EXIT
	if ! docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_NETWORK); \
	fi
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) up -d --wait --remove-orphans
	docker run --rm --interactive \
		--user root \
		--network=$(NATS_NETWORK) \
		--env NATS_HOST=nats \
		--env NATS_PORT=4222 \
		--env NATS_CONTAINER=$(NATS_CONTAINER) \
		--env NATS_DOCKER_SOCKET=/var/run/docker.sock \
		--volume $(DOCKER_SOCKET_HOST):/var/run/docker.sock \
		nats-client composer integration

.PHONY: test
test: build
	set -e
	if [ -z "$(DOCKER_SOCKET_HOST)" ]; then echo "Docker socket not found"; exit 1; fi
	trap 'docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_NETWORK); fi' EXIT
	if ! docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_NETWORK); \
	fi
	docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) up -d --wait --remove-orphans
	docker run --rm --interactive \
		--user root \
		--network=$(NATS_NETWORK) \
		--env NATS_HOST=nats \
		--env NATS_PORT=4222 \
		--env NATS_CONTAINER=$(NATS_CONTAINER) \
		--env NATS_DOCKER_SOCKET=/var/run/docker.sock \
		--volume $(DOCKER_SOCKET_HOST):/var/run/docker.sock \
		nats-client composer test

.PHONY: phpcs
phpcs: build
	docker run --rm --interactive \
    	nats-client composer phpcs

.PHONY: phpcs-file
phpcs-file: build
	docker run --rm --interactive \
    	nats-client composer phpcs:file $(RUN_ARGS)

.PHONY: phpcs-fix
phpcs-fix: build
	docker run --rm --interactive \
    	nats-client composer phpcs:fix

.PHONY: phpcs-fix-file
phpcs-fix-file: build
	docker run --rm --interactive \
    	nats-client composer phpcs:fix:file $(RUN_ARGS)
