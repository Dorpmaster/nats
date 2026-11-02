<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Amp\Cancellation;
use InvalidArgumentException;

final readonly class FastBatchOptions
{
    public function __construct(
        public int $initialFlow = 16,
        public int $maxInflightWindows = 2,
        public int $maxBufferedBytes = 2097152,
        public int $maxMessageBytes = 65536,
        public int $ackTimeoutMs = 250,
        public int $pingTimeoutMs = 1000,
        public Cancellation|null $cancellation = null,
    ) {
        if ($initialFlow < 1 || $initialFlow > 65535) {
            throw new InvalidArgumentException('Invalid initialFlow: expected 1..65535');
        }
        if ($maxInflightWindows < 1 || $maxInflightWindows > 3) {
            throw new InvalidArgumentException('Invalid maxInflightWindows: expected 1..3');
        }
        if ($maxMessageBytes < 256 || $maxBufferedBytes < 256 || $maxMessageBytes > intdiv($maxBufferedBytes, $initialFlow)) {
            throw new InvalidArgumentException('Invalid maxBufferedBytes: must fit initialFlow full maxMessageBytes frames');
        }
        if ($ackTimeoutMs <= 0 || $pingTimeoutMs <= 0) {
            throw new InvalidArgumentException('Invalid ACK/ping timeout: expected positive milliseconds');
        }
    }
}
