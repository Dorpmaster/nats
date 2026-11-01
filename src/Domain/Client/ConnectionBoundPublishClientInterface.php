<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\Cancellation;
use Closure;

/** Optional streaming inbox/write lifetime; existing request clients remain compatible. */
interface ConnectionBoundPublishClientInterface extends ConnectionBoundRequestClientInterface
{
    /** @param Closure(\Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface|\Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface):void $onMessage
     * @param Closure(\Throwable):void $onFailure
     */
    public function openPublishChannelOnConnection(string $inbox, Closure $onMessage, Closure $onFailure, int $connectionId, Cancellation|null $cancellation = null): PublishChannelInterface;
}
