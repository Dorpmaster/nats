# Write Buffer

Outbound sending is implemented by `WriteBufferService`.

## Responsibilities

- queue outbound frames
- enforce message/byte limits
- apply overflow policy (`ERROR`, `DROP_NEW`)
- run writer loop for active connection
- keep ordinary failed frames for first-send after reconnect
- support deterministic `drain()`

## Lifecycle

- `start(connection)`: bind writer loop to active connection.
- `pause()`: keep accepting queued frames, but stop writing them to the socket.
- `resume()`: resume writer loop and flush queued frames in FIFO order.
- `enqueue(frame)`: append outbound frame if limits allow.
- `stop()`: cancel writer loop (idempotent).
- `drain(timeoutMs)`: reject new enqueue, flush queue and in-flight write, then stop.

Inbound application dispatch uses a separate bounded scheduler. `WriteBufferService` is not responsible for limiting inbound callback concurrency or pending inbound queue growth.

## Frame Snapshot

`OutboundFrameBuilder` serializes the message once immediately before enqueueing.
The resulting `OutboundFrame::data` and `bytes` are the queue's wire snapshot:
`pendingBytes` accounts for the complete frame, including its command and delimiters.
The writer passes that same frame to `ConnectionInterface::send()`; its string
representation returns the saved bytes, including on retry after a failed write.
The connection interface and its method signatures are unchanged.

Before this snapshot, HPUB retains the caller's mutable header bag, including
changes through `getHeaders()`. Each serialization reads the header block once
and calculates both declared lengths from those bytes. Size getters reflect the
current bag. Mutations after enqueueing affect future snapshots, while the queued
frame and its accounting retain their original bytes.

## Reconnect Behavior

- when connection changes, writer loop is re-bound to new connection;
- client uses `pause()/resume()` to hold application frames until the NATS handshake is ready;
- ordinary failed frames are retried first;
- pending counters are not double-counted during failed write replay.

## Diagnostics

The service exposes runtime diagnostics:

- `isRunning()`
- `getPendingMessages()`
- `getPendingBytes()`
- `hasFailedFrame()`

## Single-attempt request frames

`SingleAttemptWriteBufferInterface` is an optional guarantee; the original
`WriteBufferInterface` is unchanged. A marked `OutboundFrame::request` shares its
`RequestLifetime` with the caller. The writer checks cancellation immediately
before starting a send and atomically consumes its one attempt. If the send
throws, the marked frame is released and its caller receives `RequestWriteException`
with the original cause, before reconnect handling runs. It is never saved as
`failedFrame` after an attempted send. An unattempted frame temporarily held as
`failedFrame` because the connection was closed can still be withdrawn on expiry.

The internal FIFO supports physical removal, so expired paused frames release
both byte/message accounting and queue capacity without waiting for resume.
Repeated completion/removal is idempotent. An active write remains accounted
until it settles; cancellation cannot recall bytes already handed to the transport.
Ordinary frames keep their existing snapshot, FIFO order, overflow policy and
failed-frame retry behavior. Writer lifecycle generations prevent a cancelled old
loop from suppressing the writer for a newly attached connection.
