<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

/** Optional complete headers alongside the legacy first-value getHeaders() view. */
interface JetStreamMessageHeadersInterface
{
    /** @return array<string, list<string>> Header names and repeated values retain wire order. */
    public function getHeaderValues(): array;
}
