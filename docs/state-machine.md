# Connection / Client State Machine

## States

Client lifecycle state is represented by [`ClientState` enum](../src/Domain/Client/ClientState.php).

- `NEW`
- `CONNECTING`
- `CONNECTED`
- `RECONNECTING`
- `DRAINING`
- `CLOSED`

## Allowed Transitions

- `NEW -> CONNECTING|CLOSED`
- `CONNECTING -> CONNECTED|RECONNECTING|DRAINING|CLOSED`
- `CONNECTED -> RECONNECTING|DRAINING|CLOSED`
- `RECONNECTING -> CONNECTED|DRAINING|CLOSED`
- `DRAINING -> CLOSED`
- `CLOSED -> CONNECTING`

## Main Transitions

- `NEW -> CONNECTING -> CONNECTED` on successful `connect()`.
- `CONNECTED -> RECONNECTING -> CONNECTED` on EOF/read/parser failures when reconnect is enabled.
- `RECONNECTING -> CLOSED` when max reconnect attempts are exhausted.
- `CONNECTED -> DRAINING -> CLOSED` on `drain()` / `disconnect()`.
- `CLOSED -> CONNECTING` on reconnect after explicit close.

## Idempotency

- `connect()` is idempotent while already connected.
- `disconnect()`/`drain()` are idempotent while already closed.
- `Connection::open()` is idempotent on opened socket.
- `Connection::send()` on closed socket is a no-op.

## send()/receive() Contract by State

- `CONNECTED`: normal read/write flow.
- `RECONNECTING`: receive loop attempts to restore connection; outgoing requests are not silently retried.
- `DRAINING`: new publish/request are rejected; in-flight dispatch is finished, subscriptions are unsubscribed.
- `CLOSED`: receive loop stopped.
