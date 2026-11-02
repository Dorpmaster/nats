<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Amp\Cancellation;

final readonly class AtomicBatchOptions
{
    public function __construct(
        public AtomicBatchCommitMode $commitMode = AtomicBatchCommitMode::MESSAGE,
        public Cancellation|null $cancellation = null,
    ) {
    }
}
