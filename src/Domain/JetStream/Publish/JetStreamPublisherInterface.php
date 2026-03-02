<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Dorpmaster\Nats\Domain\JetStream\Model\PubAck;

interface JetStreamPublisherInterface
{
    public function publish(
        string $subject,
        string $payload,
        PublishOptions|null $options = null,
        int|null $timeoutMs = null,
    ): PubAck;
}
