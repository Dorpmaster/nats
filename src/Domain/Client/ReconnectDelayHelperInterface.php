<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

interface ReconnectDelayHelperInterface
{
    public function calculateDelayMs(
        int $attempt,
        int $initialMs,
        int $maxMs,
        float $multiplier,
        float $jitterFraction,
    ): int;
}
