<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use RuntimeException;
use Throwable;

/** The write was attempted; the peer may have received some or all bytes. */
final class RequestWriteException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Request write failed; delivery outcome is unknown', (int) $previous->getCode(), $previous);
    }
}
