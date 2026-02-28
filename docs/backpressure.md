# Backpressure

Backpressure is split by direction.

## Inbound (subscription handlers)

Inbound limits are configured in `MessageDispatcher`.

- `maxPendingMessagesPerSubscription` (default `1000`)
- `maxPendingBytesPerSubscription` (default `2000000`, `null` disables)
- `slowConsumerPolicy`
  - `ERROR` (default): throws `SlowConsumerException`
  - `DROP_NEW`: drops newly received message

Behavior:

- counters are incremented before handler execution;
- counters are decremented in `finally`, including error paths;
- overflow action depends on policy.

## Outbound (client write buffer)

Outbound limits are configured in `ClientConfiguration`.

- `maxWriteBufferMessages` (default `10000`)
- `maxWriteBufferBytes` (default `5000000`)
- `writeBufferPolicy`
  - `ERROR` (default): throws `WriteBufferOverflowException`
  - `DROP_NEW`: drops newly enqueued frame

## ERROR vs DROP_NEW

- `ERROR`: fail fast, caller gets explicit failure.
- `DROP_NEW`: keep system running under pressure, but drop data.

Choose `ERROR` for strict reliability and explicit overload signaling.
