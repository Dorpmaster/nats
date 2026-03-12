# Release Notes — v1.0.3

## Overview

`1.0.3` is a patch release focused on transport hardening.

It keeps the async inbound execution model introduced earlier, but makes it
production-ready by adding bounded concurrency, bounded pending queue growth,
and a controlled overflow failure path for application-level inbound dispatch.

Control messages remain inline and are not blocked by application dispatch
saturation.

---

## Added

- bounded inbound dispatch scheduler
- configuration options:
  - `maxInboundDispatchConcurrency`
  - `maxPendingInboundDispatch`

---

## Changed

- inbound `MSG/HMSG` dispatch now goes through a bounded scheduler
- transport execution model now includes concurrency limit and bounded pending queue

---

## Fixed

- potential runaway growth of async inbound tasks after the move to async dispatch
- transport stability under burst inbound traffic
- controlled failure path when the inbound dispatch queue overflows

---

## Internal

- added `InboundDispatchScheduler`
- added `InboundDispatchOverflowException`
- updated execution model tests

---

## Compatibility

This change is backward compatible.

Public API changes are additive configuration only.

---

## Upgrade

No code changes are required unless you want to tune the new transport limits.

Defaults:

- `maxInboundDispatchConcurrency = 64`
- `maxPendingInboundDispatch = 1024`

```bash
composer update dorpmaster/nats
```
