<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;

/** Optional tracked single attempt; old transport implementations remain compatible. */
interface JetStreamConnectionBoundTransportInterface extends JetStreamSingleAttemptTransportInterface
{
    public function requestJsonOnConnection(string $suffix, JsonObject $payload, int $connectionId, int $timeoutMs, Cancellation|null $cancellation = null): JsonObject;
}
