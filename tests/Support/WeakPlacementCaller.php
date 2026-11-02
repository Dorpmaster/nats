<?php

// Intentionally use PHP's default coercive calling mode to validate original scalar types.

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamPlacementAdminInterface;

final class WeakPlacementCaller
{
    public static function call(JetStreamPlacementAdminInterface $admin, string $field, mixed $value): mixed
    {
        return match ($field) {
            'stream' => $admin->cancelStreamMove($value),
            'consumer' => $admin->evacuateConsumerPeer('S', $value, 'n2'),
            'peer' => $admin->evacuateStreamPeer('S', $value),
            'server' => $admin->evacuateServer(peer: $value),
            'peerId' => $admin->evacuateServer(peerId: $value),
            'timeoutMs' => $admin->cancelStreamMove('S', timeoutMs: $value),
        };
    }
}
