# Contributing

## Requirements

- Docker is required.
- Local PHP/Composer installation is optional; project workflows are container-based.

## Setup

```bash
make build
make up
make composer ARGS='install'
```

## Test Commands

```bash
make phpcs
make phpunit
make integration
make integration-tls
make integration-cluster
make integration-cluster-tls
make test
```

## Coding Style

- Follow PHPCS rules configured in this repository.
- Run `make phpcs` before pushing.
- Auto-fix where possible using `make phpcs-fix`.

## Test Philosophy

- Use AAA structure (`Arrange`, `Act`, `Assert`).
- Do not use unbounded `sleep()`.
- Use bounded polling/timeouts for async/integration behavior.
- Keep tests deterministic and order-independent.

## Adding Integration Tests

- Prefer existing harnesses in `tests/Support/`.
- Reuse compose targets in `Makefile`.
- Ensure cleanup (`down -v`) happens even on failures.
