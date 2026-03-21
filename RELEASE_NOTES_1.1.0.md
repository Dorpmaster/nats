# Release Notes — v1.1.0

## Overview

`1.1.0` consolidates reconnect seed-server configuration onto the single path
that is actually used by the transport runtime.

The release removes the unused `reconnectServers` public configuration API and
keeps reconnect / failover configuration centered on `servers:
list<ServerAddress>`.

---

## Breaking

- removed `ClientConfiguration::getReconnectServers()`
- removed the `reconnectServers` named argument from `ClientConfiguration`

If your code instantiated `ClientConfiguration` with `reconnectServers: ...`,
switch to `servers: [new ServerAddress(...)]` instead.

---

## Changed

- reconnect seed servers are configured only through `servers`
- example workers still support `NATS_RECONNECT_SERVERS`, but convert it directly
  into `servers`
- release docs and reconnect documentation now describe only the effective
  reconnect configuration path

---

## Internal

- removed dead reconnect configuration state that had no runtime usage
- aligned unit tests and examples with the effective reconnect/failover model

---

## Upgrade

Replace:

```php
new ClientConfiguration(
    reconnectServers: ['nats://127.0.0.1:4222'],
);
```

with:

```php
new ClientConfiguration(
    servers: [new ServerAddress('127.0.0.1', 4222)],
);
```

For example workers, `NATS_RECONNECT_SERVERS` remains supported as an input
environment variable and is mapped into `servers` internally.
