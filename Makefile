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
NATS_TLS_COMPOSE_FILE := docker/compose.nats.tls.test.yml
NATS_TLS_PROJECT := nats-it-tls
NATS_TLS_NETWORK := nats-client-tls-test-network
NATS_JS_COMPOSE_FILE := docker/compose.nats.jetstream.test.yml
NATS_JS_PROJECT := nats-js-it
NATS_JS_NETWORK := nats-client-jetstream-test-network
NATS_CLUSTER_COMPOSE_FILE := docker/compose.nats.cluster.test.yml
NATS_CLUSTER_PROJECT := nats-cluster-it
NATS_CLUSTER_NETWORK := nats-client-cluster-test-network
NATS_CLUSTER_TLS_COMPOSE_FILE := docker/compose.nats.cluster.tls.test.yml
NATS_CLUSTER_TLS_PROJECT := nats-cluster-tls-it
NATS_CLUSTER_TLS_NETWORK := nats-client-cluster-tls-test-network
DOCKER_SOCKET_HOST := $(shell if [ -S /var/run/docker.sock ]; then echo /var/run/docker.sock; elif [ -S $$HOME/.docker/run/docker.sock ]; then echo $$HOME/.docker/run/docker.sock; fi)
NATS_CONTAINER := $(NATS_PROJECT)-nats-1
NATS_TLS_CONTAINER := $(NATS_TLS_PROJECT)-nats-1
NATS_JS_CONTAINER := $(NATS_JS_PROJECT)-nats-js-1
NATS_CLUSTER_N1_CONTAINER := $(NATS_CLUSTER_PROJECT)-n1-1
NATS_CLUSTER_N2_CONTAINER := $(NATS_CLUSTER_PROJECT)-n2-1
NATS_CLUSTER_N3_CONTAINER := $(NATS_CLUSTER_PROJECT)-n3-1
NATS_CLUSTER_TLS_N1_CONTAINER := $(NATS_CLUSTER_TLS_PROJECT)-n1-1
NATS_CLUSTER_TLS_N2_CONTAINER := $(NATS_CLUSTER_TLS_PROJECT)-n2-1
NATS_CLUSTER_TLS_N3_CONTAINER := $(NATS_CLUSTER_TLS_PROJECT)-n3-1
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
		docker network rm $(NATS_NETWORK) || true; \
	fi

.PHONY: nats-tls-up
nats-tls-up:
	if ! docker network inspect $(NATS_TLS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_TLS_NETWORK); \
	fi
	docker compose -f $(NATS_TLS_COMPOSE_FILE) --project-name $(NATS_TLS_PROJECT) up -d --wait --remove-orphans

.PHONY: nats-tls-down
nats-tls-down:
	docker compose -f $(NATS_TLS_COMPOSE_FILE) --project-name $(NATS_TLS_PROJECT) down --timeout=0 --volumes --remove-orphans
	if docker network inspect $(NATS_TLS_NETWORK) >/dev/null 2>&1; then \
		docker network rm $(NATS_TLS_NETWORK) || true; \
	fi

.PHONY: js-up
js-up:
	if ! docker network inspect $(NATS_JS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_JS_NETWORK); \
	fi
	docker compose -f $(NATS_JS_COMPOSE_FILE) --project-name $(NATS_JS_PROJECT) up -d --wait --remove-orphans

.PHONY: js-down
js-down:
	docker compose -f $(NATS_JS_COMPOSE_FILE) --project-name $(NATS_JS_PROJECT) down --timeout=0 --volumes --remove-orphans
	if docker network inspect $(NATS_JS_NETWORK) >/dev/null 2>&1; then \
		docker network rm $(NATS_JS_NETWORK) || true; \
	fi

.PHONY: cluster-up
cluster-up:
	if ! docker network inspect $(NATS_CLUSTER_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_CLUSTER_NETWORK); \
	fi
	docker compose -f $(NATS_CLUSTER_COMPOSE_FILE) --project-name $(NATS_CLUSTER_PROJECT) up -d --wait --remove-orphans

.PHONY: cluster-down
cluster-down:
	docker compose -f $(NATS_CLUSTER_COMPOSE_FILE) --project-name $(NATS_CLUSTER_PROJECT) down --timeout=0 --volumes --remove-orphans
	if docker network inspect $(NATS_CLUSTER_NETWORK) >/dev/null 2>&1; then \
		docker network rm $(NATS_CLUSTER_NETWORK) || true; \
	fi

.PHONY: cluster-tls-up
cluster-tls-up:
	if ! docker network inspect $(NATS_CLUSTER_TLS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_CLUSTER_TLS_NETWORK); \
	fi
	docker compose -f $(NATS_CLUSTER_TLS_COMPOSE_FILE) --project-name $(NATS_CLUSTER_TLS_PROJECT) up -d --wait --remove-orphans

.PHONY: cluster-tls-down
cluster-tls-down:
	docker compose -f $(NATS_CLUSTER_TLS_COMPOSE_FILE) --project-name $(NATS_CLUSTER_TLS_PROJECT) down --timeout=0 --volumes --remove-orphans
	if docker network inspect $(NATS_CLUSTER_TLS_NETWORK) >/dev/null 2>&1; then \
		docker network rm $(NATS_CLUSTER_TLS_NETWORK) || true; \
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
	trap 'docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_NETWORK) || true; fi' EXIT
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
	trap 'docker compose -f $(NATS_COMPOSE_FILE) --project-name $(NATS_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_NETWORK) || true; fi' EXIT
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

.PHONY: integration-tls
integration-tls: build
	set -e
	if [ -z "$(DOCKER_SOCKET_HOST)" ]; then echo "Docker socket not found"; exit 1; fi
	trap 'docker compose -f $(NATS_TLS_COMPOSE_FILE) --project-name $(NATS_TLS_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_TLS_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_TLS_NETWORK) || true; fi' EXIT
	if ! docker network inspect $(NATS_TLS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_TLS_NETWORK); \
	fi
	docker compose -f $(NATS_TLS_COMPOSE_FILE) --project-name $(NATS_TLS_PROJECT) up -d --wait --remove-orphans
	docker run --rm --interactive \
		--user root \
		--network=$(NATS_TLS_NETWORK) \
		--env NATS_HOST=nats \
		--env NATS_PORT=4223 \
		--env NATS_TLS=1 \
		--env NATS_CONTAINER=$(NATS_TLS_CONTAINER) \
		--env NATS_DOCKER_SOCKET=/var/run/docker.sock \
		--volume $(DOCKER_SOCKET_HOST):/var/run/docker.sock \
		nats-client composer integration:tls

.PHONY: integration-jetstream
integration-jetstream: build
	set -e
	if [ -z "$(DOCKER_SOCKET_HOST)" ]; then echo "Docker socket not found"; exit 1; fi
	trap 'docker compose -f $(NATS_JS_COMPOSE_FILE) --project-name $(NATS_JS_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_JS_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_JS_NETWORK) || true; fi' EXIT
	if ! docker network inspect $(NATS_JS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_JS_NETWORK); \
	fi
	docker compose -f $(NATS_JS_COMPOSE_FILE) --project-name $(NATS_JS_PROJECT) up -d --wait --remove-orphans
	docker run --rm --interactive \
		--user root \
		--network=$(NATS_JS_NETWORK) \
		--env NATS_JS=1 \
		--env NATS_HOST=nats-js \
		--env NATS_PORT=4222 \
		--env NATS_CONTAINER=$(NATS_JS_CONTAINER) \
		--env NATS_DOCKER_SOCKET=/var/run/docker.sock \
		--volume $(DOCKER_SOCKET_HOST):/var/run/docker.sock \
		nats-client composer integration:jetstream

.PHONY: integration-cluster
integration-cluster: build
	set -e
	if [ -z "$(DOCKER_SOCKET_HOST)" ]; then echo "Docker socket not found"; exit 1; fi
	trap 'docker compose -f $(NATS_CLUSTER_COMPOSE_FILE) --project-name $(NATS_CLUSTER_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_CLUSTER_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_CLUSTER_NETWORK) || true; fi' EXIT
	if ! docker network inspect $(NATS_CLUSTER_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_CLUSTER_NETWORK); \
	fi
	docker compose -f $(NATS_CLUSTER_COMPOSE_FILE) --project-name $(NATS_CLUSTER_PROJECT) up -d --wait --remove-orphans
	docker run --rm --interactive \
		--user root \
		--network=$(NATS_CLUSTER_NETWORK) \
		--env NATS_CLUSTER=1 \
		--env NATS_HOST=n1 \
		--env NATS_PORT=4222 \
		--env NATS_CLUSTER_N1_CONTAINER=$(NATS_CLUSTER_N1_CONTAINER) \
		--env NATS_CLUSTER_N2_CONTAINER=$(NATS_CLUSTER_N2_CONTAINER) \
		--env NATS_CLUSTER_N3_CONTAINER=$(NATS_CLUSTER_N3_CONTAINER) \
		--env NATS_DOCKER_SOCKET=/var/run/docker.sock \
		--volume $(DOCKER_SOCKET_HOST):/var/run/docker.sock \
		nats-client composer integration:cluster

.PHONY: integration-cluster-tls
integration-cluster-tls: build
	set -e
	if [ -z "$(DOCKER_SOCKET_HOST)" ]; then echo "Docker socket not found"; exit 1; fi
	trap 'docker compose -f $(NATS_CLUSTER_TLS_COMPOSE_FILE) --project-name $(NATS_CLUSTER_TLS_PROJECT) down --timeout=0 --volumes --remove-orphans; if docker network inspect $(NATS_CLUSTER_TLS_NETWORK) >/dev/null 2>&1; then docker network rm $(NATS_CLUSTER_TLS_NETWORK) || true; fi' EXIT
	if ! docker network inspect $(NATS_CLUSTER_TLS_NETWORK) >/dev/null 2>&1; then \
		docker network create $(NATS_CLUSTER_TLS_NETWORK); \
	fi
	docker compose -f $(NATS_CLUSTER_TLS_COMPOSE_FILE) --project-name $(NATS_CLUSTER_TLS_PROJECT) up -d --wait --remove-orphans
	docker run --rm --interactive \
		--user root \
		--network=$(NATS_CLUSTER_TLS_NETWORK) \
		--env NATS_CLUSTER_TLS=1 \
		--env NATS_HOST=n1 \
		--env NATS_PORT=4222 \
		--env NATS_CLUSTER_TLS_N1_CONTAINER=$(NATS_CLUSTER_TLS_N1_CONTAINER) \
		--env NATS_CLUSTER_TLS_N2_CONTAINER=$(NATS_CLUSTER_TLS_N2_CONTAINER) \
		--env NATS_CLUSTER_TLS_N3_CONTAINER=$(NATS_CLUSTER_TLS_N3_CONTAINER) \
		--env NATS_CLUSTER_TLS_CA=/app/tests/Support/tls/cluster/ca.pem \
		--env NATS_CLUSTER_TLS_CLIENT_CERT=/app/tests/Support/tls/cluster/client.pem \
		--env NATS_CLUSTER_TLS_CLIENT_KEY=/app/tests/Support/tls/cluster/client-key.pem \
		--env NATS_DOCKER_SOCKET=/var/run/docker.sock \
		--volume $(DOCKER_SOCKET_HOST):/var/run/docker.sock \
		nats-client composer integration:cluster:tls

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
		--volume $(PWD):/app \
		--workdir /app \
    	nats-client composer phpcs:fix

.PHONY: phpcs-fix-file
phpcs-fix-file: build
	docker run --rm --interactive \
		--volume $(PWD):/app \
		--workdir /app \
    	nats-client composer phpcs:fix:file $(RUN_ARGS)
