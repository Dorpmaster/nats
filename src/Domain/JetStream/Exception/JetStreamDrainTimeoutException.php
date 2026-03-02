<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

final class JetStreamDrainTimeoutException extends JetStreamApiException
{
    public function __construct(
        string $description = 'JetStream consume drain timed out waiting for in-flight messages',
        \Throwable|null $previous = null,
    ) {
        parent::__construct(408, $description, $previous);
    }
}
