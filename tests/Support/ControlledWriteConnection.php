<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Closure;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

/** Hooks surround the real socket write; tests use a peer-side barrier before injecting failure. */
final class ControlledWriteConnection implements ConnectionInterface
{
    public int $session = -1;

    /** @param Closure(NatsProtocolMessageInterface, int):void|null $before
     * @param Closure(NatsProtocolMessageInterface, int):void|null $after
     */
    public function __construct(private ConnectionInterface $inner, private Closure|null $before = null, private Closure|null $after = null)
    {
    }

    public function open(Cancellation|null $cancellation = null): void
    {
        $this->inner->open($cancellation);
        $this->session++;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function isClosed(): bool
    {
        return $this->inner->isClosed();
    }

    public function receive(Cancellation|null $cancellation = null): NatsProtocolMessageInterface|null
    {
        return $this->inner->receive($cancellation);
    }

    public function send(NatsProtocolMessageInterface $message): void
    {
        $this->before?->__invoke($message, $this->session);
        $this->inner->send($message);
        $this->after?->__invoke($message, $this->session);
    }
}
