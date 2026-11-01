<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Capability;

enum Feature: string
{
    case CONSUMER_RESET     = 'consumer_reset';
    case ATOMIC_PUBLISH     = 'atomic_publish';
    case ATOMIC_EOB         = 'atomic_eob';
    case FAST_BATCH_PUBLISH = 'fast_batch_publish';

    public function minimumApiLevel(): int
    {
        // PHPCompatibility 9.x does not recognize enum instance methods.
        // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
        return match ($this) {
            self::ATOMIC_PUBLISH => 2,
            self::CONSUMER_RESET, self::ATOMIC_EOB, self::FAST_BATCH_PUBLISH => 4,
        };
    }
}
