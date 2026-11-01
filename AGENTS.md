# Development guide for the NATS PHP driver

These instructions apply throughout this repository. Read [CONTRIBUTING.md](CONTRIBUTING.md) for the Docker workflow and [docs/architecture.md](docs/architecture.md) for component responsibilities. For changes to handshake, reconnect, drain, backpressure or JetStream, read the corresponding document under `docs/` and the existing tests first.

## PHP and public contracts

- Target the PHP version and extensions in [composer.json](composer.json). Use `declare(strict_types=1);` in new PHP files. Match the namespace, filename and case to the production or test PSR-4 prefix. Follow [phpcs.xml](phpcs.xml), including its PSR-1 and PSR-12 rules, exclusions and additional checks.
- Use native parameter, property and return types. Document array shapes, callback signatures, units and exceptions where native types cannot express the contract. Use the vocabulary already exposed to callers, including stream, durable consumer, subject, reply-to, SID and queue group.
- For a patch release, preserve public interfaces, constructor compatibility, named arguments, exception semantics and supported inputs except for the specific behavior being fixed. Check interfaces against third-party implementations as well as built-in classes. Introduce optional capabilities compatibly when an existing interface needs more information.
- Before adding a dependency, check existing project and AMPHP facilities. Declare packages whose APIs a new implementation directly requires. Verify changes to dependency floors against the exact minimum. Keep the library lockfile out of version control as described in the release history.

## Boundaries and invariants

- Put socket I/O and TLS in `Connection`, frame parsing and serialization in `Protocol`, and lifecycle orchestration, dispatch and buffering in `Client`. Use the existing JetStream Admin, Publish, Pull and Transport responsibilities for JetStream changes.
- Keep resource and message invariants with their owning types or use-case boundaries. Keep wire representation and JSON mapping in protocol or transport code where practical. Prefer existing interfaces for dependencies that cross those boundaries. Preserve the public namespaces when improving an existing implementation.
- Validate untrusted parameters before the first observable effect, including subscriptions, enqueueing and writes. Apply the rule for the specific field. A stream name, a subscription pattern, a publish subject and a command token have different constraints. Cover valid legacy inputs as well as rejection cases.
- Calculate declared lengths, queue byte counts and bytes written from the same stable representation. A PHP readonly property can still reference a mutable object. Account for changes made through retained references and getters between message construction, enqueueing and sending.

## Asynchronous behavior and diagnostics

- Use AMPHP and Revolt primitives consistently with the surrounding component. Bound waits and retries, and use monotonic time for elapsed durations. Keep per-request inboxes, completion state and timers independent across concurrent requests.
- Give the owner of each subscription, timer or socket a cleanup path for success, failure, cancellation and timeout. Complete operations once, handle late callbacks and preserve the original failure if cleanup also fails. Preserve existing ordering guarantees across handshake, subscription replay and drain.
- Distinguish local validation, transport failures, server status and application payloads. Preserve useful server codes and descriptions and chain the originating exception when translating errors.
- Use PSR-3 through `LoggerInterface`. Put a logged throwable in the `exception` context key. For protocol diagnostics, select safe fields such as operation, byte count, status and elapsed time. Omit credentials, raw CONNECT frames, message bodies and raw socket chunks at every log level. A formatter can serialize an entire object placed in context.

## Verification and delivery

- Use Arrange, Act, Assert and the existing harnesses in `tests/Support/`. For a bug fix, cover the original trigger through the public entry point. For a race, deliver the callback on a later event-loop turn. Use an acknowledgement, barrier or bounded deadline to establish ordering.
- Run `make phpcs` and `make phpunit` for PHP changes. Choose the integration target by behavior: `make integration` for Core, `make integration-jetstream` for JetStream, and `make integration-tls` for TLS. For failover or replay, also use the corresponding `integration-cluster`, `integration-cluster-tls`, `integration-jetstream-cluster` or `integration-jetstream-cluster-tls` Make target.
- Use `make test` for the Full suite when the change crosses components or the task requires it. Report failures, skips and the actual PHP, dependency and NATS Server versions relevant to the change. A skipped environment-dependent test requires its own target to establish coverage.
- Record user-visible changes in [CHANGELOG.md](CHANGELOG.md) and update the corresponding guide under `docs/` when its contract changes. Keep unrelated working-tree changes intact and scope formatting to the task.
