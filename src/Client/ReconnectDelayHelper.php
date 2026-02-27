<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Client;

use Dorpmaster\Nats\Domain\Client\ReconnectDelayHelperInterface;

final class ReconnectDelayHelper implements ReconnectDelayHelperInterface
{
    public function calculateDelayMs(
        int $attempt,
        int $initialMs,
        int $maxMs,
        float $multiplier,
        float $jitterFraction,
    ): int {
        return self::calculateReconnectDelayMs(
            $attempt,
            $initialMs,
            $maxMs,
            $multiplier,
            $jitterFraction,
        );
    }

    public static function calculateReconnectDelayMs(
        int $attempt,
        int $initialMs,
        int $maxMs,
        float $multiplier,
        float $jitterFraction,
    ): int {
        $factor = max(1.0, $multiplier);

        $baseDelay = min((float) $maxMs, (float) $initialMs * ($factor ** max(0, $attempt - 1)));
        $jitterMax = max(0.0, $jitterFraction) * $baseDelay;
        if ($jitterMax <= 0.0) {
            return (int) round($baseDelay);
        }

        $jitter = random_int(0, (int) round($jitterMax * 1000)) / 1000;

        return (int) round($baseDelay + $jitter);
    }
}
