# Cluster Support (Iteration 1)

Cluster failover v1 (without docker cluster integration tests yet) is implemented with:

- seed servers from `ClientConfiguration::getServers()`
- discovery from `INFO.connect_urls`

Discovery updates the internal server pool with dedupe and round-robin order.

- reconnect uses server pool selection and can switch to the next available node.
- current failed node is marked dead for cooldown (`deadServerCooldownMs`).
- discovered nodes from `INFO.connect_urls` join the pool and participate in next selections.

Docker cluster integration suite is planned for the next iteration.
