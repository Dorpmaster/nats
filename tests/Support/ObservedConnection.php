<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\ServerSelectableConnectionInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;

/** Records command names and topology only, never CONNECT objects or payloads. */
final class ObservedConnection implements ServerSelectableConnectionInterface
{
    private int $session = -1;
    private bool $failed = false;
    /** @var array<int, list<string>> */
    public array $commands = [];
    /** @var array<int, list<string>> */
    public array $attempts = [];
    /** @var list<array{session: int, version: string, connect_urls: array|null}> */
    public array $infos = [];

    public function __construct(private ServerSelectableConnectionInterface $inner, private string|null $failFirstCommand = null)
    {
    }

    public function useServer(ServerAddress $server): void
    {
        $this->inner->useServer($server);
    }

    public function open(Cancellation|null $cancellation = null): void
    {
        $this->inner->open($cancellation);
        $this->session++;
        $this->commands[$this->session] = [];
        $this->attempts[$this->session] = [];
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
        $message = $this->inner->receive($cancellation);
        if ($message instanceof InfoMessageInterface) {
            $this->infos[] = ['session' => $this->session, 'version' => $message->getServerInfo()->version, 'connect_urls' => $message->getServerInfo()->connect_urls];
        }
        return $message;
    }

    public function send(NatsProtocolMessageInterface $message): void
    {
        $command                          = $message->getType()->value;
        $this->attempts[$this->session][] = $command;
        if (!$this->failed && $command === $this->failFirstCommand) {
            $this->failed = true;
            throw new ConnectionException('Synthetic handshake write failure');
        }
        $this->inner->send($message);
        $this->commands[$this->session][] = $command;
    }
}
