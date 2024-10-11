.SILENT: ;               # no need for @
.ONESHELL: ;             # recipes execute in same shell
.NOTPARALLEL: ;          # wait for this target to finish
.EXPORT_ALL_VARIABLES: ; # send all vars to shell
default: help ;   		 # default target
Makefile: ;              # skip prerequisite

# use the rest as arguments for "run"
RUN_ARGS := $(wordlist 2,$(words $(MAKECMDGOALS)),$(MAKECMDGOALS))
# ...and turn them into do-nothing targets
$(eval $(RUN_ARGS):;@:)

.PHONY: help
help:
	echo "To be implemented"

.PHONY: build
build:
	docker compose build

.PHONY: up
up: build
	docker compose up -d

.PHONY: watch
watch: build
	docker compose watch

.PHONY: down
down:
	docker compose down --timeout=0 --volumes --remove-orphans

.PHONY: composer
composer:
	docker run --rm --interactive --tty \
		--volume $(PWD):/app \
		--volume $(HOME)/.cache/composer}:/tmp \
		--user $(id -u):$(id -g) \
		composer \
		  --ignore-platform-req=ext-event \
		  --ignore-platform-req=ext-pcntl \
		  $(RUN_ARGS)

.PHONY: test
test:
	docker compose run --rm --interactive \
		--volume $(PWD):/app \
		--volume $(HOME)/.cache/composer}:/tmp \
		--user $(id -u):$(id -g) \
		--workdir /app \
		client composer phpunit

.PHONY: phpcs
phpcs:
	docker run --rm --interactive --tty \
    	--volume $(PWD):/app \
    	--user $(id -u):$(id -g) \
    	composer composer \
		  --ignore-platform-req=ext-event \
		  --ignore-platform-req=ext-pcntl \
		  phpcs

.PHONY: phpcs-file
phpcs-file:
	docker run --rm --interactive --tty \
    	--volume $(PWD):/app \
    	--user $(id -u):$(id -g) \
    	composer composer \
		  --ignore-platform-req=ext-event \
		  --ignore-platform-req=ext-pcntl \
		  phpcs:file $(RUN_ARGS)

.PHONY: phpcs-fix
phpcs-fix:
	docker run --rm --interactive --tty \
    	--volume $(PWD):/app \
    	--user $(id -u):$(id -g) \
    	composer composer \
		  --ignore-platform-req=ext-event \
		  --ignore-platform-req=ext-pcntl \
		  phpcs:fix

.PHONY: phpcs-fix-file
phpcs-fix-file:
	docker run --rm --interactive --tty \
    	--volume $(PWD):/app \
    	--user $(id -u):$(id -g) \
    	composer composer \
    	  --ignore-platform-req=ext-event \
    	  --ignore-platform-req=ext-pcntl \
    	  phpcs:fix:file $(RUN_ARGS)