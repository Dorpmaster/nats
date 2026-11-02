<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName;
use Dorpmaster\Nats\Domain\JetStream\Internal\FastBatchMessage;

/** A pre-existing consumer owned by the application, never deleted by Admin linkage operations. */
final readonly class StreamSourceConsumer
{
    public function __construct(public string $name, public string $deliverSubject)
    {
        ResourceName::validate($name, 'consumer.name');
        try {
            FastBatchMessage::subject($deliverSubject);
        } catch (\InvalidArgumentException $error) {
            throw new \InvalidArgumentException('Invalid consumer.deliver_subject: expected concrete subject', 0, $error);
        }
    }
    /** @return array{name:string,deliver_subject:string} */
    public function toRequestPayload(): array
    {
        return ['name' => $this->name, 'deliver_subject' => $this->deliverSubject];
    }
}
