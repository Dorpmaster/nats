<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Capability;

enum Feature: string
{
    case CONSUMER_RESET            = 'consumer_reset';
    case ATOMIC_PUBLISH            = 'atomic_publish';
    case ATOMIC_EOB                = 'atomic_eob';
    case FAST_BATCH_PUBLISH        = 'fast_batch_publish';
    case MESSAGE_SCHEDULE_AT       = 'message_schedule_at';
    case MESSAGE_SCHEDULE_EXTENDED = 'message_schedule_extended';
    case DURABLE_SOURCING          = 'durable_sourcing';
    case PLACEMENT_MANAGEMENT      = 'placement_management';
    case STREAM_BACKUP_V2          = 'stream_backup_v2';

    public function minimumApiLevel(): int
    {
        // PHPCompatibility 9.x does not recognize enum instance methods.
        // phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
        return match ($this) {
            self::PLACEMENT_MANAGEMENT, self::STREAM_BACKUP_V2 => 5,
            self::ATOMIC_PUBLISH, self::MESSAGE_SCHEDULE_AT => 2,
            self::CONSUMER_RESET, self::ATOMIC_EOB, self::FAST_BATCH_PUBLISH, self::MESSAGE_SCHEDULE_EXTENDED, self::DURABLE_SOURCING => 4,
        };
    }
}
