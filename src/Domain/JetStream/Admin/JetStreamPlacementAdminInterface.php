<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\JetStream\Model\{ConsumerInfo,PlacementAck,StreamInfo};

/** Optional API level 5 operations. No existing Admin implementation must implement this interface. */
interface JetStreamPlacementAdminInterface extends JetStreamAdminInterface
{
    public function cancelStreamMove(string $stream, int|null $timeoutMs = null, Cancellation|null $cancellation = null): StreamInfo;
    public function evacuateStreamPeer(string $stream, string $peer, int|null $timeoutMs = null, Cancellation|null $cancellation = null): PlacementAck;
    public function evacuateConsumerPeer(string $stream, string $consumer, string $peer, int|null $timeoutMs = null, Cancellation|null $cancellation = null): PlacementAck;
    /** System account only; exactly one of peer (server name) or peerId. */
    public function evacuateServer(string|null $peer = null, string|null $peerId = null, int|null $timeoutMs = null, Cancellation|null $cancellation = null): PlacementAck;
    public function waitForStreamEvacuation(string $stream, string $peerName, int $expectedReplicas, int|null $timeoutMs = null, Cancellation|null $cancellation = null): StreamInfo;
    public function waitForConsumerEvacuation(string $stream, string $consumer, string $peerName, int $expectedReplicas, int|null $timeoutMs = null, Cancellation|null $cancellation = null): ConsumerInfo;
    public function waitForStreamRollback(StreamInfo $transition, StreamInfo $original, int|null $timeoutMs = null, Cancellation|null $cancellation = null): StreamInfo;
}
