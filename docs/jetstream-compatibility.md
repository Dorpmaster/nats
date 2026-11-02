# JetStream compatibility and protocol capabilities (planned 1.3.0)

Existing `ClientInterface`, `JetStreamAdminInterface`,
`JetStreamControlPlaneTransportInterface` and `JetStreamPublisherInterface` are
unchanged. Their third-party implementations remain supported. Existing
constructor parameter order and names remain valid; new options are appended.
PHP and package requirements are unchanged.

Optional contracts implemented by built-in classes:

- `ServerInformationProviderInterface::getServerInformation(): ServerInformation`
  on `Client` exposes the latest INFO server ID/version, nullable JetStream flag,
  nullable `apiLevel`, and a connection generation ID.
- `JetStreamCapabilitiesProviderInterface::getCapabilities(): JetStreamCapabilities`
  on transport, Admin and publisher provides advisory protocol checks.
- `JetStreamJsonTransportInterface::requestJson(string $apiSubjectSuffix,
  JsonObject $payload, ?int $timeoutMs = null): JsonObject` preserves JSON tokens.
- `SingleAttemptRequestClientInterface::requestOnce(PubMessageInterface|HPubMessageInterface $message,
  float $timeout = 30)` and `SingleAttemptWriteBufferInterface` opt into removable
  request snapshots and no replay after an attempted write.
- `JetStreamSingleAttemptTransportInterface::requestJsonOnce(string $apiSubjectSuffix,
  JsonObject $payload, ?int $timeoutMs = null): JsonObject` extends the optional
  JSON transport contract for mutation requests.
- `JetStreamUpdatingAdminInterface::updateStream(string $stream,
  StreamConfigPatch $patch, ?int $timeoutMs = null): StreamInfo` performs a selected
  configuration update. A legacy transport keeps its old CRUD behavior; UPDATE
  requires both its explicit lossless JSON and single-attempt capabilities and otherwise fails locally.

## Capability rules

`$transport->getCapabilities()->check(Feature::ATOMIC_PUBLISH)` returns a
`FeatureSupport` with `support` (`SUPPORTED`, `UNSUPPORTED`, `UNKNOWN`) and a
machine-readable `reason`. It performs no network calls and creates no resources.
The old INFO `proto` field describes the Core protocol and is never substituted
for `api_lvl`.

| Feature | Minimum announced JetStream API level | Fixed-source evidence |
| --- | ---: | --- |
| `ATOMIC_PUBLISH` | 2 | [NATS 2.12.6 versioning](https://github.com/nats-io/nats-server/blob/v2.12.6/server/jetstream_versioning.go): atomic publishing requires level 2 |
| `CONSUMER_RESET` | 4 | [NATS 2.14.0 API](https://github.com/nats-io/nats-server/blob/v2.14.0/server/jetstream_api.go): RESET subject/response; [consumer handler](https://github.com/nats-io/nats-server/blob/v2.14.0/server/consumer.go); [versioning](https://github.com/nats-io/nats-server/blob/v2.14.0/server/jetstream_versioning.go): server level 4; endpoint absent in 2.12.6 |
| `ATOMIC_EOB` | 4 | [NATS 2.14.0 stream](https://github.com/nats-io/nats-server/blob/v2.14.0/server/stream.go): `Nats-Batch-Commit: eob`; absent from atomic commit handling in [2.12.6](https://github.com/nats-io/nats-server/blob/v2.12.6/server/stream.go) |
| `PLACEMENT_MANAGEMENT` | 5 | [NATS 2.15.2 API](https://github.com/nats-io/nats-server/blob/v2.15.2/server/jetstream_api.go): stream cancel/peer evacuate, consumer peer evacuate and system server evacuate |
| `FAST_BATCH_PUBLISH` | 4 | [NATS 2.14.0 versioning](https://github.com/nats-io/nats-server/blob/v2.14.0/server/jetstream_versioning.go): `AllowBatchPublish` requires level 4 |

Fixed integration versions advertise levels 3 (2.12.6), 4 (2.14.0), and 5
(2.15.2). This is not a single version comparison: atomic publishing is supported
at level 2, while the three later capabilities require level 4. A future version
with a sufficient announced API level is accepted; a missing `api_lvl` remains
UNKNOWN even if the version string looks recent. A lower announced level yields
UNSUPPORTED for that particular feature. Malformed/negative `api_lvl` is treated
as unannounced.

Support describes the protocol, not account permission, server enablement, or the
stream's configuration. `server.jetStream` remains a separate nullable INFO fact.
A permission refusal, missing stream or timeout is an operation error and does
not rewrite capability information.

An INFO update replaces the snapshot and invalidates previously obtained
snapshots. A disconnect, failed transport or reconnect also invalidates them;
`ServerInformation::isCurrentConnection()` and capability checks consult the
current lifetime rather than keeping a stale positive result. The connection ID
is local to that `Client` instance. With an older client lacking the optional
provider, or an older transport that cannot establish its target, checks return
UNKNOWN.

For a domain or imported API prefix, connected-server INFO does not identify the
target JetStream server. Checks conservatively return UNKNOWN with reason
`remote_api_target_unknown`; no probing or inferred remote support is attempted.

These are preparatory contracts. Reset, atomic/fast batch publication operations,
scheduling, and expanded operational metadata are outside this implementation.

## Reproducible matrix

Run `make integration-jetstream-compatibility`. It starts isolated fixed servers
with a real `EU` domain and an exported/imported API between two accounts. It runs
new configuration/prefix tests plus the original Admin/publish/pull/consume suites
on each version, including a restart. All tests must execute without skips.
`--version 2.12.6` (repeatable) and `--image IMAGE` can be supplied directly to
`python3 tests/Support/run-jetstream-compatibility.py` for a narrower run.

## Message scheduling (1.4.0)

`MESSAGE_SCHEDULE_AT` requires API level 2. `MESSAGE_SCHEDULE_EXTENDED` requires
level 4 for intervals, cron/time zones, source sampling or scheduled rollup.
Typed publication verifies the current target and flags before writing; see
[message schedules](jetstream-schedules.md). Existing batch/reset thresholds
remain unchanged.

### Sources and mirrors (1.4.0)

`Feature::DURABLE_SOURCING` requires API level 4 for explicitly provided consumers and flow_control ACK. Basic Limits sources/mirrors work on 2.12.6; NATS-managed durable sourcing for WorkQueue/Interest requires 2.14+. [Admission and ownership](jetstream-sources.md).

## Replica placement (1.4.0)

`JetStreamPlacementAdminInterface` and
`JetStreamConnectionBoundTransportInterface::requestJsonOnConnection()` are
optional additions. Existing Admin and transport implementations retain their
contracts. Placement commands and bounded observers require the new tracked transport capability;
legacy CRUD, UPDATE and RESET keep their previous requirements. Actual target
admission requires the native default API route and complete ingress/account diagnostics;
observers remain on one connection generation. The four placement commands are
rejected through ordinary replayable transport methods. Separate convergence
checks and rollback instance identity are described in
[replica placement](jetstream-placement.md).
