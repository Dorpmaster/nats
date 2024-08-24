<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests;

use Exception;
use Throwable;

final class UnhandledException extends Exception
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(
            sprintf(
                "%s thrown to event loop error handler: %s",
                // replace NUL-byte in anonymous class name
                str_replace("\0", '@', get_class($previous)),
                $previous->getMessage()
            ),
            0,
            $previous
        );
    }
}
