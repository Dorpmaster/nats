<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Amp\Cancellation;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;

/** Optional N1 request lifetime bound to this client's current transport generation. */
interface ConnectionBoundRequestClientInterface extends SingleAttemptRequestClientInterface
{
    /** @throws ConnectionBoundRequestException Includes whether the socket write began. */
    public function requestOnceOnConnection(
        PubMessageInterface|HPubMessageInterface $message,
        int $connectionId,
        float $timeout = 30,
        Cancellation|null $cancellation = null,
    ): MsgMessageInterface|HMsgMessageInterface;
}
