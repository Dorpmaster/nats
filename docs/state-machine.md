# State Machine

`Client` lifecycle is represented by `ClientState` enum:

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

Illegal transitions are rejected.

## Method Contracts by State

- `connect()`
  - `NEW|CLOSED`: starts connect flow
  - `CONNECTED`: no-op
  - `CONNECTING|RECONNECTING|DRAINING`: waits for expected state with timeout
- `publish()/request()`
  - `CONNECTED`: allowed
  - `CONNECTING|RECONNECTING`: allowed only when `bufferWhileReconnecting=true`, otherwise `ClientNotConnectedException`
  - `DRAINING|CLOSED`: rejected
- `drain()` / `disconnect()`
  - idempotent
  - transitions to `DRAINING`, then `CLOSED`

## Protocol Readiness Barrier

`CONNECTED` means transport is open and lifecycle orchestration is active. It does not mean application frames are already writable on the socket.

- before readiness, the client must observe `INFO`, send `CONNECT`, send `PING`, and receive `PONG`;
- `subscribe()/publish()/request()` invoked before this point are buffered, not written immediately;
- after reconnect, the same barrier is applied again before buffered frames are flushed.

## Reconnect Flow

`CONNECTED -> RECONNECTING -> CONNECTED` happens on transport failures if reconnect is enabled.

If attempts are exhausted, state moves to `CLOSED`.
