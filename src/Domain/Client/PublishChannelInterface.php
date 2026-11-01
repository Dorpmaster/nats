<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Client;

use Closure;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;

/** A private inbox and single-attempt writes belonging to one ready transport. */
interface PublishChannelInterface
{
    /** Returns after the socket write; onAttempt runs immediately before that write begins. */
    public function sendOnce(PubMessageInterface|HPubMessageInterface $message, Closure $onAttempt, bool $control = false): void;
    /** Withdraw all unattempted frames, including controls, while retaining the reply inbox. */
    public function stopSending(): void;
    public function close(): void;
}
