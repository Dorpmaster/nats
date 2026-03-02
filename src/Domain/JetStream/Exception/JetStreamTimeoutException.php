<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

final class JetStreamTimeoutException extends JetStreamApiException
{
    public function __construct(string $description = 'JetStream publish request timed out', \Throwable|null $previous = null)
    {
        parent::__construct(408, $description, $previous);
    }
}
