<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

enum AtomicBatchCommitMode: string
{
    case MESSAGE = 'message';
    case EOB     = 'eob';
}
