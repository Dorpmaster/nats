<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Internal;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\JetStream\Model\{ClusterInfo,StreamInfo,ConsumerInfo,JsonObject};
use InvalidArgumentException;

/** @internal Polls only INFO; command acceptance is never treated as completion. */
final readonly class PlacementObserver
{
    public function __construct(private PlacementOperations $operations)
    {
    }

    public function evacuate(string $stream, string|null $consumer, string $peerName, int $replicas, int|null $timeoutMs, Cancellation|null $cancellation): StreamInfo|ConsumerInfo
    {
        ResourceName::validate($stream, 'stream');
        if ($consumer !== null) {
            ResourceName::validate($consumer, 'consumer');
        }
        PlacementOperations::peer($peerName, 'peerName');
        if ($replicas <= 0) {
            throw new InvalidArgumentException('Invalid expectedReplicas');
        }
        $deadline   = new PlacementDeadline($timeoutMs, $cancellation);
        $connection = $this->operations->connection();
        do {
            $info       = $this->operations->info($stream, $consumer, $deadline, connection: $connection);
            $names      = self::settledPeers($info->getCluster(), $replicas);
            $config     = $info->getConfiguration();
            $configured = (new MetadataFields($config ?? new JsonObject('{}'), 'config'))->decimal('num_replicas');
            if ($names !== null && !in_array($peerName, $names, true) && ($consumer !== null || $configured === (string)$replicas)) {
                return $info;
            }
            \Amp\delay(min(.05, $deadline->remaining() / 1000), cancellation:$cancellation);
        } while (true);
    }
    public function rollback(StreamInfo $transition, StreamInfo $original, int|null $timeoutMs, Cancellation|null $cancellation): StreamInfo
    {
        ResourceName::validate($transition->getName(), 'stream');
        $origin           = $transition->getCluster()?->desired?->origin;
        $replicas         = $origin?->replicas === null ? null : Decimal::toInt($origin->replicas);
        $originalConfig   = $original->getConfiguration();
        $originalReplicas = $originalConfig === null ? null : (new MetadataFields($originalConfig, 'config'))->decimal('num_replicas');
        $peers            = $replicas === null ? null : self::settledPeers($original->getCluster(), $replicas);
        if ($transition->getName() !== $original->getName() || $origin === null || $replicas === null || $peers === null || $originalReplicas !== (string)$replicas) {
            throw new InvalidArgumentException('Invalid rollback baseline: require transition origin and original stable peer group');
        }
        PlacementSnapshot::sameInstance($original, $transition);
        $placement = PlacementSnapshot::placement($origin->placement);
        $retention = $origin->retention ?? (new MetadataFields($originalConfig, 'config'))->text('retention');
        if (PlacementSnapshot::configurationPlacement($originalConfig) !== $placement || $retention === null || $retention !== $originalConfig->get('retention')) {
            throw new InvalidArgumentException('Invalid rollback baseline: origin configuration mismatch');
        }
        $deadline   = new PlacementDeadline($timeoutMs, $cancellation);
        $connection = $this->operations->connection();
        do {
            $info = $this->operations->info($original->getName(), null, $deadline, connection: $connection);
            PlacementSnapshot::sameInstance($original, $info);
            $config            = $info->getConfiguration();
            $observedPlacement = PlacementSnapshot::configurationPlacement($config);
            $actual            = self::settledPeers($info->getCluster(), $replicas);
            if ($actual === $peers && $config?->get('num_replicas') === $replicas && $config?->get('retention') === $retention && $observedPlacement === $placement) {
                return $info;
            }
            \Amp\delay(min(.05, $deadline->remaining() / 1000), cancellation:$cancellation);
        } while (true);
    }
    /** @return list<string>|null null means convergence is not established. */
    private static function settledPeers(ClusterInfo|null $cluster, int $replicas): array|null
    {
        if ($cluster === null || $cluster->desired !== null || $cluster->leader === null || $cluster->leader === '') {
            return null;
        }
        $names = [$cluster->leader];
        foreach ($cluster->replicas ?? [] as $peer) {
            if ($peer->name === null || $peer->name === '' || $peer->current !== true || $peer->offline === true || $peer->pending === true || ($peer->lag !== null && $peer->lag !== '0')) {
                return null;
            }
            $names[] = $peer->name;
        }
        if (count($names) !== $replicas || count(array_unique($names, SORT_STRING)) !== $replicas) {
            return null;
        }
        sort($names, SORT_STRING);
        return $names;
    }
}
