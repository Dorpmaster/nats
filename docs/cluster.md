# Cluster Support (Iteration 1)

Cluster failover v1 is implemented with:

- seed servers from `ClientConfiguration::getServers()`
- discovery from `INFO.connect_urls`

Discovery updates the internal server pool with dedupe and round-robin order.

- reconnect tries current node first.
- node is marked dead only after confirmed `open()` failure.
- after failure, reconnect switches to next available node from server pool.
- dead node cooldown is controlled by `deadServerCooldownMs`.
- discovered nodes from `INFO.connect_urls` join the pool and participate in next selections.

## Running Cluster Integration

- `make cluster-up`
- `make integration-cluster`
- `make cluster-down`
