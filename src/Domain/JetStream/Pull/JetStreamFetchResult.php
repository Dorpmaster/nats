<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAckerInterface;

final readonly class JetStreamFetchResult
{
    /** @param list<JetStreamMessageInterface> $messages */
    public function __construct(
        private array $messages,
        private JetStreamMessageAckerInterface $acker,
    ) {
    }

    /** @return \Traversable<int, JetStreamMessageInterface> */
    public function messages(): \Traversable
    {
        yield from $this->messages;
    }

    public function getReceivedCount(): int
    {
        return count($this->messages);
    }

    public function getAcker(): JetStreamMessageAckerInterface
    {
        return $this->acker;
    }
}
