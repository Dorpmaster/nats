# Backpressure / Slow Consumer v1

## Current scope

Backpressure v1 is implemented at message-dispatcher level with per-subscription pending message limits.
`ClientConfiguration` does not apply inbound backpressure settings.
Outbound write-buffer backpressure is configured in `ClientConfiguration`.

## Controls

Configure these values directly in `MessageDispatcher` constructor:

- `maxPendingMessagesPerSubscription` (default: `1000`)
- `maxPendingBytesPerSubscription` (default: `2000000`, `null` disables bytes limit)
- `slowConsumerPolicy`:
  - `ERROR` (default): throw `SlowConsumerException`
  - `DROP_NEW`: drop overflow messages

## Behavior

- pending counters are incremented before handler invocation,
- pending counters (messages + bytes) are decremented in `finally`, even when handler throws,
- on overflow:
  - `ERROR` stops normal processing with an explicit exception,
  - `DROP_NEW` drops only the new message and keeps dispatcher alive.

## Notes

- This is v1 minimal protection.
- Inbound byte limit is applied to `MSG` payload size and `HMSG` total size (`headers + payload`).

## Outbound write-buffer

Controls (`ClientConfiguration`):

- `maxWriteBufferMessages` (default: `10000`)
- `maxWriteBufferBytes` (default: `5000000`)
- `writeBufferPolicy`:
  - `ERROR` (default): throw `WriteBufferOverflowException`
  - `DROP_NEW`: drop only the new message
- `bufferWhileReconnecting` (default: `false`)

Architecture:

- `Client` validates lifecycle/state and delegates outbound frames to `WriteBufferService`,
- `WriteBufferService` owns queue, pending counters, overflow policy, writer-loop lifecycle and drain/flush.
- frame size is calculated once in `OutboundFrameBuilder` (`bytes === strlen(frame data)`).

State behavior for `publish()`/`request()`:

- `CONNECTED`: enqueue and write through writer-loop
- `CONNECTING` / `RECONNECTING`:
  - `bufferWhileReconnecting=false` -> `ClientNotConnectedException`
  - `bufferWhileReconnecting=true` -> enqueue (within limits)
- `DRAINING` / `CLOSED`: rejected (`ConnectionException`)

Drain semantics:

- `drain()` rejects new enqueue, flushes queued outbound messages, then closes connection.
- failed write frame is retained separately and is sent first after reconnect without double-counting pending counters.
