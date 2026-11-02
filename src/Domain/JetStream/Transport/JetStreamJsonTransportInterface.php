<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;

/** Optional lossless JSON capability; existing transports need not implement it. */
interface JetStreamJsonTransportInterface extends JetStreamControlPlaneTransportInterface
{
    public function requestJson(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs = null): JsonObject;
}
