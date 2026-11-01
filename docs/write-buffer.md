# Write Buffer

Outbound sending is implemented by `WriteBufferService`.

## Responsibilities

- queue outbound frames
- enforce message/byte limits
- apply overflow policy (`ERROR`, `DROP_NEW`)
- run writer loop for active connection
- keep failed frame for first-send after reconnect
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
- failed frame is retried first;
- pending counters are not double-counted during failed write replay.

## Diagnostics

The service exposes runtime diagnostics:

- `isRunning()`
- `getPendingMessages()`
- `getPendingBytes()`
- `hasFailedFrame()`
