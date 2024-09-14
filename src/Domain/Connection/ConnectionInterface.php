<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\Connection;

use Amp\Cancellation;
use Amp\Future;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

interface ConnectionInterface
{
    /**
     * @throws ConnectionException
     */
    public function open(Cancellation|NULL $cancellation = null): void;

    public function close(): void;

    public function isClosed(): bool;

    /**
     * @throws ConnectionException
     */
    public function receive(Cancellation|null $cancellation = null): string|null;

    /**
     * @throws ConnectionException
     */
    public function send(NatsProtocolMessageInterface $message): void;
}
