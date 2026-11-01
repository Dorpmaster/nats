<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerResetResult;

/** Optional consumer reset capability; existing Admin implementations remain compatible. */
interface JetStreamResettingAdminInterface extends JetStreamAdminInterface
{
    public function resetConsumer(
        string $stream,
        string $consumer,
        int|string|null $streamSequence = null,
        int|null $timeoutMs = null,
    ): ConsumerResetResult;
}
