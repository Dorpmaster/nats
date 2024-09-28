<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

final class Connection implements ConnectionInterface
{
    private Socket|null $socket = null;
    private ConcurrentIterator|null $iterator = null;

    public function __construct(
        private readonly SocketConnector                  $connector,
        private readonly ConnectionConfigurationInterface $configuration,
        private readonly LoggerInterface|null             $logger = null,
    )
    {
    }

    public function open(Cancellation|null $cancellation = null): void
    {
        if (!$this->isClosed()) {
            $this->logger?->warning('Connection is already opened');

            return;
        }

        $url = sprintf(
            '%s:%d',
            $this->configuration->getHost(),
            $this->configuration->getPort()
        );

        $context = new ConnectContext();

        try {
            $this->logger?->debug('Starting to open TCP connection', [
                'url' => $url,
                'context' => $context,
            ]);

            $this->socket ??= $this->connector->connect($url, $context, $cancellation);
        } catch (Throwable $exception) {
            $error = 'Failed to open TCP connection to the server';

            $this->logger?->error($error, [
                'url' => $url,
                'exception' => $exception,
            ]);

            throw new ConnectionException($exception->getMessage(), $exception->getCode(), $exception);
        }

        $this->logger?->debug('Creating a new queue for incoming data');

        $queue = new Queue($this->configuration->getQueueBufferSize());
        $this->iterator = $queue->iterate();

        $socket = $this->socket;
        $logger = $this->logger;

        $this->logger->debug('Enabling a microtask that processes the incoming stream');
        EventLoop::queue(static function () use ($socket, $queue, $logger): void {
            try {
                $parser = new ProtocolParser($queue->push(...));

                $logger?->debug('Reading the socket');

                while (null !== $chunk = $socket?->read()) {
                    $logger?->debug('A new chunk has received', ['chunk' => $chunk]);

                    $parser->push($chunk);

                    $logger?->debug('Chunk has processed with the parser');
                }

                $logger?->debug('Cancelling the parser');
                $parser->cancel();

                $logger?->debug('Completing the queue');
                $queue->complete();
            } catch (Throwable $e) {
                $logger?->error('An exception has occurred while reading the incoming stream', [
                    'exception' => $e,
                ]);
                $queue->error($e);
            } finally {
                $logger?->debug('Stopping read the socket');
                $socket?->close();
            }
        });

         $this->logger?->debug('Connection has successfully opened');
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            $this->logger?->debug('Closing the socket');

            $this->socket->close();
            $this->socket = null;

            $this->logger?->debug('The socket has closed');
        }
    }

    public function receive(Cancellation|null $cancellation = null): NatsProtocolMessageInterface|null
    {
        return ($this->iterator?->continue($cancellation) ?? false)
            ? $this->iterator->getValue()
            : null;
    }

    public function send(NatsProtocolMessageInterface $message): void
    {
        try {
            $this->socket?->write($message->getPayload());
        } catch (Throwable $exception) {
            $this->logger?->error('An exception has occurred while writing the socket', [
                'exception' => $exception,
            ]);

            throw new ConnectionException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    public function isClosed(): bool
    {
        return $this->socket?->isClosed() ?? true;
    }
}
