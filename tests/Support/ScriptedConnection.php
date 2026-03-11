<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

final class ScriptedConnection implements ConnectionInterface
{
    /** @var list<string> */
    private array $sent  = [];
    private bool $closed = true;

    /**
     * @param \Closure(self):NatsProtocolMessageInterface|null $onReceive
     * @param \Closure(self):void|null $onOpen
     * @param \Closure(NatsProtocolMessageInterface, self):void|null $onSend
     */
    public function __construct(
        private readonly \Closure $onReceive,
        private readonly \Closure|null $onOpen = null,
        private readonly \Closure|null $onSend = null,
    ) {
    }

    public function open(Cancellation|null $cancellation = null): void
    {
        ($this->onOpen)?->__invoke($this);
        $this->closed = false;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function receive(Cancellation|null $cancellation = null): NatsProtocolMessageInterface|null
    {
        return ($this->onReceive)($this);
    }

    public function send(NatsProtocolMessageInterface $message): void
    {
        $this->sent[] = (string) $message;
        ($this->onSend)?->__invoke($message, $this);
    }

    /** @return list<string> */
    public function sentWire(): array
    {
        return $this->sent;
    }

    public function markClosed(): void
    {
        $this->closed = true;
    }

    public function markOpen(): void
    {
        $this->closed = false;
    }

    public function closeAndReturnNull(): null
    {
        $this->closed = true;

        return null;
    }
}
