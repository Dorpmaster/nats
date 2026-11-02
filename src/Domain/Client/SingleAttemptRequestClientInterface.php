<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;

/** Optional request capability: never replay an attempted write; withdraw unsent frames on completion. */
interface SingleAttemptRequestClientInterface
{
    public function requestOnce(PubMessageInterface|HPubMessageInterface $message, float $timeout = 30): MsgMessageInterface|HMsgMessageInterface;
}
