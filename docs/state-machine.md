# Connection / Client State Machine

## States

- `DISCONNECTED`
- `CONNECTING`
- `CONNECTED`
- `RECONNECTING`
- `DISCONNECTING`

## Main Transitions

- `DISCONNECTED -> CONNECTING -> CONNECTED` on successful `connect()`.
- `CONNECTED -> RECONNECTING -> CONNECTED` on EOF/read/parser failures when reconnect is enabled.
- `RECONNECTING -> DISCONNECTED` when max reconnect attempts are exhausted.
- `CONNECTED -> DISCONNECTING -> DISCONNECTED` on `disconnect()`.

## Idempotency

- `connect()` is idempotent while already connected.
- `disconnect()` is idempotent while already disconnected.
- `Connection::open()` is idempotent on opened socket.
- `Connection::send()` on closed socket is a no-op.

## send()/receive() Contract by State

- `CONNECTED`: normal read/write flow.
- `RECONNECTING`: receive loop attempts to restore connection; outgoing requests are not auto-retried.
- `DISCONNECTED`: receive loop stopped; writes are ignored by connection-level no-op behavior.
