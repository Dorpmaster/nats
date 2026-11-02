<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use RuntimeException;
use Throwable;

final class ConnectionBoundRequestException extends RuntimeException
{
    public function __construct(private readonly bool $attempted, Throwable $previous)
    {
        parent::__construct('Connection-bound request failed', (int) $previous->getCode(), $previous);
    }

    public function wasAttempted(): bool
    {
        return $this->attempted;
    }
}
