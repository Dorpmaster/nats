<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;

interface JetStreamSingleAttemptTransportInterface extends JetStreamJsonTransportInterface
{
    public function requestJsonOnce(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs = null): JsonObject;
}
