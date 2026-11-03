<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\Cancellation;
use Closure;

/** Optional lossless protocol inbox; application subscriptions keep their dispatch policies. */
interface ConnectionBoundProtocolClientInterface extends ConnectionBoundPublishClientInterface
{
    /** Callbacks run in wire order, must not block, and must bound their own receive buffer.
     * No application slow-consumer policy may silently discard a protocol message.
     * @param Closure(\Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface|\Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface):void $onMessage
     * @param Closure(\Throwable):void $onFailure A failed callback/connection fails the channel.
     */
    public function openProtocolChannelOnConnection(string $inbox, Closure $onMessage, Closure $onFailure, int $connectionId, Cancellation|null $cancellation = null): PublishChannelInterface;
}
