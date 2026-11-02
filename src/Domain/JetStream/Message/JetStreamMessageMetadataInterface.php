<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

/** Optional capability; existing JetStreamMessageInterface implementations remain valid. */
interface JetStreamMessageMetadataInterface
{
    public function getMetadata(): MessageMetadata|null;
}
