<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

interface JetStreamFastBatchPublisherInterface extends JetStreamPublisherInterface
{
    /** @param non-empty-list<string> $subjects Concrete allowed publish subjects. */
    public function openFastBatch(string $stream, array $subjects, FastBatchOptions|null $options = null, int|null $timeoutMs = null): FastBatchSessionInterface;
}
