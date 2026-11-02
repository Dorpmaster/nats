<?php

// Intentionally omit strict_types: this regression must call from PHP's default coercive mode.

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamResettingAdminInterface;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerResetResult;

final class WeakConsumerResetCaller
{
    public static function reset(
        JetStreamResettingAdminInterface $admin,
        string $stream,
        string $consumer,
        mixed $streamSequence,
    ): ConsumerResetResult {
        return $admin->resetConsumer($stream, $consumer, $streamSequence);
    }
}
