<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Dorpmaster\Nats\Domain\JetStream\Model\AtomicBatchAck;
use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;

interface JetStreamAtomicPublisherInterface extends JetStreamPublisherInterface
{
    /** @param list<array{subject:string, payload:string, headers?:HeaderBugInterface|array<string,string|list<string>>}> $messages */
    public function publishAtomicBatch(
        string $stream,
        array $messages,
        AtomicBatchOptions|null $options = null,
        int|null $timeoutMs = null,
    ): AtomicBatchAck;
}
