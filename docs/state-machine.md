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

## Reconnect Flow

`CONNECTED -> RECONNECTING -> CONNECTED` happens on transport failures if reconnect is enabled.

If attempts are exhausted, state moves to `CLOSED`.
