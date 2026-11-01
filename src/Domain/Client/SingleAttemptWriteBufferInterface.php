<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

/** Optional guarantee that OutboundFrame::request lifetime and single-attempt policy are honored. */
interface SingleAttemptWriteBufferInterface extends WriteBufferInterface
{
}
