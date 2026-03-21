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

## Transition Semantics

Each transition is applied in ordered phases:

1. validate transition and commit the new `ClientState`
2. apply internal lifecycle effects for that state
3. dispatch `connectionStatusChanged`

This means the state-change event is post-effects, not pre-effects.

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
  - transitions to `DRAINING`, stops accepting new inbound application callback tasks, waits bounded time for active + pending dispatch drain, then transitions to `CLOSED`

## Protocol Readiness Barrier

`CONNECTED` state means transport is open and handshake orchestration is active.
The `CONNECTED` state-change event is emitted only after readiness completes.

- before readiness, the client must observe `INFO`, send `CONNECT`, send `PING`, and receive `PONG`;
- `subscribe()/publish()/request()` invoked before this point are buffered, not written immediately;
- after reconnect, the same barrier is applied again before buffered frames are flushed.

## Reconnect Flow

`CONNECTED -> RECONNECTING -> CONNECTED` happens on transport failures if reconnect is enabled.

If attempts are exhausted, state moves to `CLOSED`.

On `RECONNECTING`, the state-change event is emitted only after reconnect
backoff context, write-buffer mode, and inbound scheduler restrictions have
already been applied.

## Inbound Dispatch Execution Model

- `CONNECTED` and `RECONNECTING` may both have active inbound application callback tasks;
- parsed `MSG/HMSG` messages are scheduled onto bounded async dispatch tasks instead of running inline on the reader loop;
- `INFO`, `PING`, `PONG`, and `ERR` remain inline and are not blocked by application dispatch saturation;
- active application callback count is bounded by configuration;
- pending application dispatch queue is bounded by configuration;
- active callback task completion order is not part of the public contract;
- callback failures are logged and isolated from the reader loop state machine;
- pending queue overflow triggers a controlled failure path instead of silently dropping inbound messages;
- `DRAINING` prevents scheduling new inbound callback tasks but allows already accepted work to finish within the configured drain timeout.

## Event Observability Contract

At the time `connectionStatusChanged` is dispatched:

- `CONNECTED` listeners see a ready handshake, resumed write path, and final ping lifecycle for connected mode;
- `RECONNECTING` listeners see reconnect backoff context already initialized and write path already switched into reconnect mode;
- `DRAINING` listeners see drain restrictions already active for new application writes and inbound scheduling;
- `CLOSED` listeners see lifecycle services already stopped as far as guaranteed by the client contract.
