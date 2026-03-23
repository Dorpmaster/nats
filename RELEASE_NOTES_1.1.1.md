# Release Notes — v1.1.1

## Overview

This is a patch release focused on client lifecycle consistency and state transition observability.

The release refines the internal lifecycle orchestration of the client so that:
- state commit
- lifecycle side effects
- state change events

are now applied in a consistent and safer order.

This improves correctness for integrations that observe connection state changes.

---

## Changed

### State transition orchestration

Client state transitions were refactored so that `transitionTo()` is now limited to:
- transition validation
- state commit

Internal lifecycle side effects are no longer performed directly inside `transitionTo()`.

They are now applied in a separate phase before state change events are dispatched.

---

### Post-effects event dispatch

State change notifications are now dispatched only after internal lifecycle effects have been applied.

This means event listeners no longer observe partially-applied client lifecycle state.

Affected internal lifecycle areas include:
- reconnect lifecycle
- ping lifecycle
- write buffer lifecycle
- inbound dispatch scheduler lifecycle
- drain-related restrictions

---

### CONNECTED event semantics

The `CONNECTED` state notification now has post-handshake semantics.

It is dispatched only after readiness is fully established, including:
- handshake completion
- connected-mode lifecycle effects
- write buffer readiness
- ping service activation
- reconnect context cleanup

As a result, observers of `connectionStatusChanged(CONNECTED)` now see a fully ready connected client instead of a partially-initialized one.

---

## Fixed

- Event listeners can no longer observe a new state before client lifecycle effects are fully applied.
- Reentrant lifecycle observation during `CONNECTED` / `RECONNECTING` / `DRAINING` transitions is now consistent.
- `CONNECTED` notifications are no longer emitted too early during initial connection lifecycle.

---

## Tests

New and updated tests cover:
- `CONNECTED` event observability after readiness
- `RECONNECTING` event observability after reconnect initialization
- `DRAINING` event observability after drain restrictions are applied
- separation of state commit from lifecycle effects

---

## Compatibility

This release is backward compatible.

Public API is unchanged.

The primary behavioral change is improved ordering and semantics of internal lifecycle effects and state change events.

Integrations that listen to connection state changes now receive stronger post-transition guarantees.

---

## Upgrade

No code changes are required.

```bash
composer update dorpmaster/nats
```

---

## Commits

- `17944df` refactor: separate state transition commit from lifecycle effects
- `508464a` docs: add connect and reconnect lifecycle timelines
