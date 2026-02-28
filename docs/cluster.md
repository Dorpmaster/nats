# Cluster Support (Iteration 1)

Cluster groundwork is implemented with two parts:

- seed servers from `ClientConfiguration::getServers()`
- discovery from `INFO.connect_urls`

Discovery updates the internal server pool with dedupe and round-robin order.

This iteration does not switch reconnect to discovered servers yet.
Failover orchestration is planned for the next iteration.
