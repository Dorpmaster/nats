<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

use Dorpmaster\Nats\Domain\JetStream\Pull\SlowConsumerPolicy;

final class JetStreamSlowConsumerException extends JetStreamApiException
{
    public function __construct(
        public readonly SlowConsumerPolicy $policy,
        public readonly int $inFlightMessages,
        public readonly int $inFlightBytes,
        public readonly int $maxInFlightMessages,
        public readonly int $maxInFlightBytes,
    ) {
        parent::__construct(
            429,
            sprintf(
                'JetStream slow consumer detected: policy=%s in_flight_messages=%d max_messages=%d in_flight_bytes=%d max_bytes=%d',
                $this->policy->name,
                $this->inFlightMessages,
                $this->maxInFlightMessages,
                $this->inFlightBytes,
                $this->maxInFlightBytes,
            ),
        );
    }
}
