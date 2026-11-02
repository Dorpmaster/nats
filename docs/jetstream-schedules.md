# JetStream message schedules (1.4.0)

The server stores a schedule definition and executes it. `publish()` returns the
PubAck for that definition; it does not confirm that the target has received a
scheduled delivery. Inspect or consume the target separately.

## Stream configuration

```php
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;

$admin->createStream(new StreamConfig(
    'ORDERS', ['orders.>'], 'file', 1,
    allowMessageSchedules: true,
    allowMessageTtl: true, // needed when generated messages have a TTL
));
// Enable schedules on an existing stream; unspecified settings are preserved.
$admin->updateStream('ORDERS', new StreamConfigPatch(allowMessageSchedules: true));
```

The wire flags are `allow_msg_schedules` and `allow_msg_ttl`. Nullable constructor
and patch arguments omit the field. A patch of another limit retains these flags
and the original stream configuration. NATS permits enabling schedules but does
not permit disabling them afterward. Enabling schedules also allows the server's
subject rollup for definitions; publishing another definition to that subject
replaces the previous schedule. Choose a unique definition subject per schedule.

## One publication at a specified time

```php
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\MessageSchedule;
use Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleOptions;

$publisher = new JetStreamPublisher($client);
$ack = $publisher->publish('orders.schedule.reminder', "binary\0body", new PublishOptions(
    msgId: 'schedule-reminder-1',
    headers: ['X-Trace' => 'reminder'],
    schedule: new ScheduleOptions(
        stream: 'ORDERS',
        target: 'orders.reminder',
        schedule: MessageSchedule::at('2027-01-15T12:00:00+02:00'),
        ttlSeconds: 60,
    ),
), timeoutMs: 5000);
// $ack->getSeq() identifies the saved definition, not a target delivery.
```

`at()` requires RFC3339, an explicit offset or Z, and a time representable by the
server's signed 64-bit Unix nanosecond clock (approximately 1677–2262). Fractions
up to nine digits are retained. A past time can fire immediately. The offset in
the timestamp is supported; a separate named time zone is only valid for cron.
Generated one-shot deliveries have `Nats-Schedule-Next: purge`; the definition is
removed when the server completes the scheduled publication.

## Intervals and cron

```php
$interval = new ScheduleOptions('ORDERS', 'orders.tick', MessageSchedule::every(5));
$publisher->publish('orders.schedule.tick', 'tick', new PublishOptions(schedule: $interval));

$cron = new ScheduleOptions(
    'ORDERS', 'orders.report',
    MessageSchedule::cron('0 */5 9-17 * * mon-fri', 'Europe/Athens'),
);
$publisher->publish('orders.schedule.report', 'report', new PublishOptions(schedule: $cron));
```

`every()` takes actual whole integer seconds, at least one second; floats,
booleans and duration strings are rejected. The duration bound is 9223372036 seconds; the next firing must also fit the
server's nanosecond clock. The typed API rejects a relative interval extending
beyond that horizon.
Cron has six fields: seconds, minutes, hours, day of month, month, day of week.
Wildcards (`*` and `?`), lists, ranges, steps and three-letter month/day names are
supported. The driver validates syntax but lets NATS choose firing times.
Omitting the time zone defaults to UTC; an explicit UTC or IANA name is accepted.
Named zones require corresponding tzdata on the server. Fixed offsets and Local
are not accepted by the typed API. Follow the server's DST behavior when choosing
local-time schedules. The generated message's `Nats-Schedule-Next` announces the
next server firing time; it is not part of the definition PubAck.

## Sampling, rollup and generated-message TTL

```php
use Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleRollup;

$sampling = new ScheduleOptions(
    stream: 'ORDERS',
    target: 'orders.sampled',
    schedule: MessageSchedule::every(1),
    source: 'orders.current',
    ttlSeconds: 10,
    rollup: ScheduleRollup::SUB,
);
$publisher->publish('orders.schedule.sample', 'fallback', new PublishOptions(schedule: $sampling));
```

Definition, target and optional source must be concrete, pairwise distinct
subjects of the same stream. The server reads the latest source message at each
firing, so changing the source from A to B changes subsequent target deliveries.
If no source message exists, the definition's body and headers are the fallback.
Generated deliveries identify the definition with `Nats-Scheduler`.

`ScheduleRollup::SUB` applies rollup to the target at delivery time; neighboring
subjects retain their history. There is no ALL case: the tested servers reject
scheduled rollup `all`. `ttlSeconds` sets `Nats-Schedule-TTL` for the generated
record, not the definition's lifetime. It requires `allow_msg_ttl=true` and an
actual positive integer. The driver checks the **expiration timestamp**, not
only the duration. For `@at`, the supported execution window includes **60 seconds
of target-write lateness**, separately from the definition registration deadline
(`MessageSchedule::AT_EXECUTION_DELAY_SECONDS`). The driver takes the later of the
exact nominal `@at` and the upper registration-time estimate, adds that execution
reserve, and checks that the generated TTL still fits signed Int64 nanoseconds.
A past `@at` uses the current registration-time estimate, plus the same reserve.
All arithmetic uses integers and preserves fractional nanoseconds. Requests
whose nominal expiration is Int64_MAX, Int64_MAX minus one nanosecond, or otherwise
lacks this reserve fail locally before any definition HPUB, including with rollup.

The 60 seconds are a **driver support policy**, not a universal NATS scheduling
latency bound established by the tests. The bounded expiration guarantee requires
synchronized clocks and an actual target write within this supported window.
The registration timeout does not constrain future server execution. A prolonged
server outage, slow storage or delayed in-flight delivery can exceed the window;
the driver cannot predict or enforce that future latency. If the deployment cannot
justify this execution bound, do not rely on near-horizon TTL values: choose a much
smaller TTL. There is no unconditional Int64 safety guarantee under unbounded
lateness. The reserve is not a server expiry/deadline header, and does not cancel
an overdue one-shot schedule.

A recurring definition with generated-message TTL has a bounded server lifetime
of **365 days** (`ScheduleOptions::RECURRING_TTL_LIFETIME_SECONDS`): the driver adds
`Nats-TTL: 31536000s` to the definition. It checks the end of this whole lifetime
plus the generated TTL and the publication deadline. This avoids treating an
indefinitely recurring schedule as safe based only on its first firing. The
lifetime starts when the server stores the definition; a firing does not renew it.
The server removes the definition when its lifetime expires; renew it explicitly
if needed. Renewal replaces the definition and restarts its lifetime. An `@every`
interval must be shorter than this lifetime. Sparse cron expressions may expire
without firing; plan renewal accordingly. Recurring schedules **without**
generated TTL retain their previous unbounded lifetime. One-shot definitions do
not receive this additional header. NATS strips the definition TTL before applying
the generated record's own TTL. Stream retention settings are not changed.

The expiration check assumes the client and server clocks are synchronized and
the server executes within the admitted time/lifetime. It cannot protect against
an arbitrary server clock jump or an indefinitely delayed in-flight delivery.

## Compatibility and admission

| Typed operation | Minimum announced API level | Tested servers |
| --- | ---: | --- |
| Plain `@at`, including generated TTL | 2 | 2.12.6, 2.14.0, 2.15.2 |
| `@every`, cron/time zone, source sampling, scheduled rollup | 4 | 2.14.0, 2.15.2 |

`Feature::MESSAGE_SCHEDULE_AT` and `MESSAGE_SCHEDULE_EXTENDED` expose these checks.
A source or rollup makes even an `@at` schedule require level 4. On older or
unconfirmed targets the typed operation fails before its first data frame.

The built-in client must provide the optional connection-bound single-attempt
request capability. Before writing a definition, the publisher confirms stream
identity/API metadata, the flags and unique subject-to-stream routes.
The stream must acknowledge publications: `no_ack` must be absent or the JSON
boolean `false`. `true`, `null` and other JSON types are refused before writing
a definition, with a known local outcome. A present `subject_transform` is also
refused, including `null`, an empty array or other malformed values: only an
absent field certifies that a definition retains its original subject. It reuses
the [batch topology guard](jetstream-publish.md): monitoring grants for
`$SYS.REQ.USER.INFO`, VARZ, ACCOUNTZ and ROUTEZ are required on all discovered
cluster peers. An [example configuration](../tests/Support/atomic-monitoring.conf)
provides those grants. Missing diagnostics, matching account mappings/imports/
exports, leaf/gateway exits and stream subject transforms cannot be certified.
The current implementation uses the default `$JS.API` scope. Keep topology and
routing stable during publication. These restrictions prevent an extended
schedule from reaching an old server through an unchecked route.

Existing `publish()` calls and PublishOptions named arguments retain their
behavior; the new `schedule` argument is appended to the constructor and create().
Raw header-only calls still use the legacy publication path. Use the typed option
for capability/route checks and single-attempt semantics. Typed scheduling reserves
schedule headers case-insensitively, plus expected-stream, required-API, batch,
rollup and TTL controls. Ordinary custom headers are preserved. Command subjects,
header control characters, invalid intervals/time-zone combinations and conflicts
fail locally rather than being trimmed or silently rewritten.

## Errors, uncertainty and stopping

Catch `ScheduledPublishException` (a `JetStreamApiException`) to inspect
`getApiCode()`, `getErrorCode()`, `getDescription()`, `getStream()`,
`getDefinitionSubject()` and `isOutcomeUnknown()`. The original cause is chained.
One monotonic deadline covers admission and definition publication. A definition
is attempted once on its original ready connection; reconnect never repeats it.
Expired unattempted frames are withdrawn, including if PHP delayed timer dispatch.
If a write may have reached NATS but confirmation is lost, inspect the definition
before choosing an application retry. A reliable server rejection is distinct
from a timeout or intermediary error.

A tested way to stop a recurring schedule is to purge only its definition subject:

```php
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;

$transport = new JetStreamControlPlaneTransport($client);
$stopped = $transport->requestJson('STREAM.PURGE.ORDERS', JsonObject::fromArray([
    'filter' => 'orders.schedule.sample',
]));
// Check $stopped->get('success'), then verify target deliveries have stopped.
```

Purge removes the definition, not target history. Coordinate with in-flight firings
and publishers that could replace the definition. Definitions may also be deleted
by their stream sequence through `STREAM.MSG.DELETE`; no delete/recreate of the
stream is needed. User disconnect does not stop a server schedule.

Run `make integration-jetstream-schedules` for the three-version matrix. Its
`--output` traces separate definition PubAck, target records, source changes and
stop confirmations. The protocol references are
[ADR-51](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md)
and the pinned [2.14 scheduler](https://github.com/nats-io/nats-server/blob/v2.14.0/server/scheduler.go).
