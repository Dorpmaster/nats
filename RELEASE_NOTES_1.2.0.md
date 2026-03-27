# Release Notes — v1.2.0

## Overview

This minor release adds queue group support for Core NATS subscriptions.

The client can now create queue subscriptions using the existing subscribe API in a backward-compatible way.

This release also includes integration coverage to verify:
- queue-group load balancing
- coexistence with regular subscriptions
- reconnect replay correctness

---

## Added

### Queue group support for subscriptions

The subscribe API now supports queue groups via an additional optional parameter:

`public function subscribe(string $subject, \Closure $closure, ?string $queueGroup = null): string;`

Regular subscriptions remain unchanged.

Queue subscriptions are sent using the NATS wire format:

`SUB <subject> <queue-group> <sid>`

---

## Changed

### Subscription metadata and replay

Queue group metadata is now stored together with subscription state.

Reconnect replay restores queue subscriptions as queue subscriptions rather than replaying them as regular subscriptions.

### Buffered subscribe path

Queue subscriptions created before READY are buffered and sent correctly after the handshake barrier is completed.

---

## Integration coverage

Integration tests now verify:

- load balancing across subscribers in the same queue group
- regular broadcast subscriptions working alongside queue groups
- queue subscription replay after reconnect

---

## Compatibility

This release is backward compatible.

The existing subscribe contract remains compatible:
- callback type is still `\Closure`
- return type remains `string`
- `queueGroup` is optional and additive

---

## Upgrade

No code changes are required for existing users.

To use queue subscriptions:

```php
$client->subscribe('updates', function (Message $message): void {
    // ...
}, 'workers');
```

---

## Commits

- `62edbfc` docs: analyze queue subscription support gaps
- `55f4311` feat: add queue group support for subscriptions
- `3855fde` fix: keep subscribe queue-group API backward compatible
- `b34616c` test: add integration coverage for queue subscriptions
