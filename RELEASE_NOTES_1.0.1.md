# Release Notes — v1.0.1

## Overview

This is a bugfix release that fixes a protocol-level handshake ordering issue
which could cause `Authorization Violation` errors on strict NATS clusters.

The fix ensures that application-level commands are not sent before the
connection reaches the READY state.

---

## Fixed

### Handshake ordering bug

In previous versions the client could send application-level commands
(SUB / PUB / HPUB / request path) before the initial NATS handshake was
fully completed.

Expected handshake order:

INFO
CONNECT
PING
PONG
READY

Some production NATS clusters enforce this ordering strictly.

If commands were sent earlier the server responded with:

-ERR Authorization Violation

which caused the client to enter RECONNECTING state.

---

### Solution

A handshake readiness barrier has been introduced.

Before READY:

- subscribe()
- publish()
- request()

commands are buffered.

They are flushed only after READY.

The guaranteed sequence is now:

INFO
CONNECT
PING
PONG
READY
flush buffered SUB/PUB/HPUB

---

### Parser fix

ProtocolParser now supports inbound PONG messages which are required
for readiness detection.

---

### Reconnect behaviour

Reconnect now also respects the handshake barrier.

After reconnect the sequence is:

INFO
CONNECT
PING/PONG
READY
replay subscriptions
flush buffered publish

---

## Tests

New deterministic tests were added:

- handshake barrier tests
- reconnect buffering tests
- parser PONG tests

Covered scenarios:

- subscribe before READY
- publish before READY
- request buffering
- reconnect buffering
- subscription replay order

---

## Compatibility

This change is fully backward compatible.

Public API has not changed.

---

## Upgrade

No code changes are required.

composer update dorpmaster/nats

---

## Commit

054513f
fix: buffer outbound commands until NATS connection is ready
