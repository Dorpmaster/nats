# Queue Subscriptions Phase 1

## 2.1 Current public subscribe API

Current public subscribe API:

- `Dorpmaster\Nats\Domain\Client\ClientInterface::subscribe(string $subject, \Closure $closure): string` in `src/Domain/Client/ClientInterface.php:30`
- `Dorpmaster\Nats\Client\Client::subscribe(string $subject, Closure $closure): string` in `src/Client/Client.php:386`

Return type:

- `string`
- The returned string is the generated subscription id (`$sid`) created by `SubscriptionIdHelperInterface::generateId()` in `src/Client/Client.php:388`

Handler passing:

- The handler is passed as `Closure $closure`
- The handler is stored by `SubscriptionStorageInterface::add(string $sid, Closure $closure): void` in `src/Domain/Client/SubscriptionStorageInterface.php:11`

Method parameters:

- `$subject: string`
- `$closure: Closure`

Confirmed gap:

- The public API has no queue-group parameter in either `ClientInterface::subscribe()` or `Client::subscribe()`
- `Client::subscribe()` constructs `new SubMessage($subject, $sid)` in `src/Client/Client.php:389`
- Because the public API accepts only `subject` and `closure`, the caller has no API path to provide a queue group to subscription creation

## 2.2 SUB wire generation path

Current SUB wire generation path:

1. `Client::subscribe()` creates `SubMessage` in `src/Client/Client.php:389`
2. `Client::enqueueOutbound()` passes the message into `enqueueFrame()` in `src/Client/Client.php:403` and `src/Client/Client.php:908`
3. `Client::enqueueFrame()` builds an outbound frame through `OutboundFrameBuilder::build()` in `src/Client/Client.php:1076` and `src/Protocol/OutboundFrameBuilder.php:9`
4. `OutboundFrameBuilder::build()` converts the protocol object to wire data with `(string) $message` in `src/Protocol/OutboundFrameBuilder.php:13`
5. `SubMessage::__toString()` renders the final `SUB` frame in `src/Protocol/SubMessage.php:36`

Current SUB frame format used by `Client::subscribe()`:

- `SUB <subject> <sid>`

Code facts:

- `Client::subscribe()` passes only two arguments to `SubMessage`: `new SubMessage($subject, $sid)` in `src/Client/Client.php:389`
- `SubMessage::__toString()` emits `SUB <subject> <sid>` when `queueGroup === null` in `src/Protocol/SubMessage.php:38-45`
- `SubMessage::__toString()` also supports `SUB <subject> <queue> <sid>` when `queueGroup !== null` in `src/Protocol/SubMessage.php:48-55`

Confirmed gap:

- Queue-group wire support exists inside `Dorpmaster\Nats\Protocol\SubMessage`
- Queue-group wire support is not used by `Client::subscribe()` because the client never passes the third constructor argument
- The effective runtime format on the client subscribe path is `SUB <subject> <sid>`

## 2.3 Subscription storage model

Subscription state is stored in two places:

1. Handler storage:
   - `Dorpmaster\Nats\Client\SubscriptionStorage`
   - Backed by `private array $storage = [];` in `src/Client/SubscriptionStorage.php:12`
   - `add(string $sid, Closure $closure): void` stores `$this->storage[$sid] = $closure` in `src/Client/SubscriptionStorage.php:14-17`
   - `all(): array` returns `array<string, Closure>` in `src/Client/SubscriptionStorage.php:31-35`
2. Subject replay map in `Client`:
   - `private array $subscriptionsBySid = [];` declared as `array<string, string>` in `src/Client/Client.php:71-72`
   - `Client::subscribe()` saves `$this->subscriptionsBySid[$sid] = $subject` in `src/Client/Client.php:396`

Fields currently stored:

- In `SubscriptionStorage`: `sid -> Closure`
- In `Client::$subscriptionsBySid`: `sid -> subject`

Confirmed gap:

- There is no `queueGroup` field in `SubscriptionStorage`
- There is no `queueGroup` field in `SubscriptionStorageInterface`
- There is no `queueGroup` field in `Client::$subscriptionsBySid`
- `MessageDispatcher::processMsg()` retrieves only the handler by `sid` through `$this->storage->get($message->getSid())` in `src/Client/MessageDispatcher.php:94`

Current model constraint:

- Storage is keyed by `sid` in both the handler store and the replay map
- The current identification key is already `sid`
- Queue-group data is absent from the stored value set and must be added as extra subscription metadata if the SID-keyed model is kept

## 2.4 Reconnect replay path

Reconnect replay path:

1. Successful reconnect sets `$this->replaySubscriptionsOnReady = true` in `src/Client/Client.php:662-663`
2. `applyConnectedTransitionEffects()` resets handshake state and pauses the write buffer in `src/Client/Client.php:843-854`
3. When the initial reconnect `PONG` is observed, `completeHandshake()` runs in `src/Client/Client.php:939`
4. `completeHandshake()` calls `restoreSubscriptionsImmediately()` when `replaySubscriptionsOnReady` is set in `src/Client/Client.php:947-950`
5. `restoreSubscriptionsImmediately()` iterates over `subscriptionsBySid` and sends `new SubMessage($subject, $sid)` in `src/Client/Client.php:979-983`

Data used for replay:

- `sid`
- `subject`

Confirmed gap:

- Replay does not read any queue-group value because `subscriptionsBySid` stores only `subject`
- Replay reconstructs `SubMessage` with only `subject` and `sid` in `src/Client/Client.php:982`
- Queue group does not participate in reconnect replay
- Replay depends only on the current subscription fields held in `Client::$subscriptionsBySid`

## 2.5 Unsubscribe path

Unsubscribe path:

1. `Client::unsubscribe(string $sid): void` is defined in `src/Client/Client.php:424`
2. It constructs `new UnSubMessage($sid)` in `src/Client/Client.php:426`
3. It removes the handler from storage with `$this->storage->remove($sid)` in `src/Client/Client.php:427`
4. It removes the replay entry with `unset($this->subscriptionsBySid[$sid])` in `src/Client/Client.php:428`
5. It either sends immediately during draining, returns silently in `CLOSED` and `RECONNECTING`, or enqueues the `UNSUB` frame in `src/Client/Client.php:430-445`

Subscription identification:

- Unsubscribe is identified only by `sid`
- `UnSubMessage` carries `sid` and optional `maxMessages`, with no queue-group field, in `src/Protocol/UnSubMessage.php:14-18`

Confirmed gap:

- Queue group does not affect unsubscribe
- The unsubscribe path does not read subject
- The unsubscribe path does not read queue group

Risk boundary for Phase 2:

- The current unsubscribe path is SID-only
- If queue-group metadata is added, unsubscribe must keep the SID-based delete path aligned across both handler storage and replay metadata storage

## 2.6 Handshake barrier / buffered subscribe

Buffered subscribe path before READY:

1. `Client::subscribe()` always creates the `SubMessage` first and stores handler metadata before sending in `src/Client/Client.php:388-397`
2. `Client::subscribe()` then calls `enqueueOutbound($message)` in `src/Client/Client.php:403`
3. `enqueueOutbound()` calls `enqueueFrame()` in `src/Client/Client.php:908-912`
4. `enqueueFrame()` converts the `SubMessage` into an `OutboundFrame` with `OutboundFrameBuilder::build()` and pushes it into `WriteBufferInterface::enqueue()` in `src/Client/Client.php:1076-1087`
5. On connect or reconnect, `applyConnectedTransitionEffects()` starts the write buffer and immediately pauses it in `src/Client/Client.php:853-854`
6. `completeHandshake()` resumes the write buffer only after readiness is complete in `src/Client/Client.php:951`

Current buffered representation:

- The deferred outbound `SUB` is stored as a serialized `OutboundFrame`
- That frame comes from the exact `SubMessage` object created in `Client::subscribe()`
- Because `Client::subscribe()` constructs `new SubMessage($subject, $sid)`, the buffered `SUB` frame has no queue-group segment

Reconnect-specific note:

- After reconnect, subscriptions are replayed from `subscriptionsBySid` in `restoreSubscriptionsImmediately()` before the paused write buffer is resumed in `src/Client/Client.php:947-951` and `src/Client/Client.php:979-983`

Confirmed gap:

- For initial pre-READY subscribe buffering, queue-group support requires `Client::subscribe()` to construct `SubMessage` with queue-group data before the frame is enqueued
- For reconnect replay, queue-group support requires replay metadata to persist queue-group data because replay rebuilds `SubMessage` from stored values instead of reusing the original buffered frame

## 2.7 Protocol reference

Protocol forms:

- Regular subscription: `SUB <subject> <sid>`
- Queue subscription: `SUB <subject> <queue-group> <sid>`

Current code location that forms the `SUB` wire frame:

- `Dorpmaster\Nats\Protocol\SubMessage::__toString()` in `src/Protocol/SubMessage.php:36-55`

Current client-side call sites that use it:

- `Client::subscribe()` creates `new SubMessage($subject, $sid)` in `src/Client/Client.php:389`
- `Client::restoreSubscriptionsImmediately()` creates `new SubMessage($subject, $sid)` in `src/Client/Client.php:982`

Confirmed insertion point for queue-group segment:

- The queue-group segment must be passed into the third constructor argument of `SubMessage` at the client call sites that currently instantiate `SubMessage`

## 2.8 Gap analysis

Queue group is not supported in the current client subscription lifecycle because:

- [x] absent in public API
  - `src/Domain/Client/ClientInterface.php:30`
  - `src/Client/Client.php:386`
- [x] absent in wire generation on the client subscribe path
  - `src/Client/Client.php:389`
  - `src/Protocol/SubMessage.php:38-45`
- [x] absent in storage
  - `src/Client/SubscriptionStorage.php:12-17`
  - `src/Domain/Client/SubscriptionStorageInterface.php:11-18`
  - `src/Client/Client.php:71-72`
  - `src/Client/Client.php:396`
- [x] absent in replay
  - `src/Client/Client.php:662-663`
  - `src/Client/Client.php:947-950`
  - `src/Client/Client.php:979-983`
- [x] absent in buffered path
  - `src/Client/Client.php:389`
  - `src/Client/Client.php:403`
  - `src/Client/Client.php:908-912`
  - `src/Client/Client.php:1076-1087`

Protocol support already present:

- `SubMessage` and `SubMessageInterface` already expose `queueGroup`
  - `src/Protocol/SubMessage.php:18-22`
  - `src/Protocol/SubMessage.php:48-55`
  - `src/Protocol/SubMessage.php:63-66`
  - `src/Protocol/Contracts/SubMessageInterface.php:9-13`

This means the gap is in client lifecycle integration, not in the protocol object itself.

## 2.9 Files to change in Phase 2

- `src/Domain/Client/ClientInterface.php` — add queue-group parameter to the public subscribe API
- `src/Client/Client.php` — accept queue-group in `subscribe()`, pass it into `SubMessage`, store queue-group in subscription metadata, and use it in reconnect replay
- `src/Domain/Client/SubscriptionStorageInterface.php` — current contract stores only `sid` and `Closure`; it must carry the subscription metadata needed by the client lifecycle if queue-group is stored there
- `src/Client/SubscriptionStorage.php` — current implementation stores only `array<string, Closure>`; it must store the metadata required by the updated storage contract if queue-group is stored there

Files confirmed not to require wire-format changes:

- `src/Protocol/SubMessage.php` already supports `queueGroup`
- `src/Protocol/Contracts/SubMessageInterface.php` already exposes `getQueueGroup()`

## 2.10 Proposed API

`subscribe(string $subject, \Closure $closure, ?string $queueGroup = null): string`
