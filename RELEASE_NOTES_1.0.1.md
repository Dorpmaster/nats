# Release Notes — v1.0.1

## Overview

This patch release corrects the release description metadata after the initial
stable rollout.

It does not introduce a transport or public API change. The goal of `1.0.1`
was to align release-facing documentation with the already shipped `1.0.0`
stable line.

---

## Changed

### Release description sync

- corrected stable release description
- aligned release-facing metadata after the first stable publish

---

## Compatibility

This change is backward compatible.

Public API has not changed.

---

## Upgrade

No code changes are required.

```bash
composer update dorpmaster/nats
```

---

## Commit

`a711079`
`Corrected release description`
