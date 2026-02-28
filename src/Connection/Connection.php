<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Dorpmaster\Nats\Domain\Connection\ConnectionConfigurationInterface;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Domain\Connection\ConnectionInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

final class Connection implements ConnectionInterface
{
    private Socket|null $socket               = null;
    private ConcurrentIterator|null $iterator = null;

    public function __construct(
        private readonly SocketConnector $connector,
        private readonly ConnectionConfigurationInterface $configuration,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function open(Cancellation|null $cancellation = null): void
    {
        if (!$this->isClosed()) {
            $this->logger?->warning('Connection is already opened');

            return;
        }

        $url                = $this->createConnectUri();
        $context            = $this->createConnectContext();
        $tlsConfiguration   = $this->configuration->getTlsConfiguration();
        $prefetchedMessages = [];

        try {
            $this->logger?->debug('Starting to open TCP connection', [
                'url' => $url,
                'context' => $context,
                'tls_enabled' => $tlsConfiguration->isEnabled(),
            ]);

            $this->socket ??= $this->connector->connect($url, $context, $cancellation);

            if ($tlsConfiguration->isEnabled()) {
                $prefetchedMessages = $this->readPrefaceMessagesBeforeTlsHandshake($this->socket, $cancellation);

                $this->logger?->debug('Starting TLS handshake', [
                    'verify_peer' => $tlsConfiguration->isVerifyPeer(),
                    'server_name' => $tlsConfiguration->getServerName() ?? $this->configuration->getHost(),
                ]);
                $this->socket->setupTls($cancellation);
            }
        } catch (Throwable $exception) {
            $error = $tlsConfiguration->isEnabled()
                ? 'TLS handshake failed: ' . $exception->getMessage()
                : $exception->getMessage();

            $this->logger?->error($error, [
                'url' => $url,
                'exception' => $exception,
                'tls_enabled' => $tlsConfiguration->isEnabled(),
                'verify_peer' => $tlsConfiguration->isVerifyPeer(),
                'server_name' => $tlsConfiguration->getServerName() ?? $this->configuration->getHost(),
            ]);

            throw new ConnectionException($error, $exception->getCode(), $exception);
        }

        $this->logger?->debug('Creating a new queue for incoming data');

        $queue          = new Queue($this->configuration->getQueueBufferSize());
        $this->iterator = $queue->iterate();

        $socket = $this->socket;
        $logger = $this->logger;

        $this->logger?->debug('Enabling a microtask that processes the incoming stream');
        EventLoop::queue(static function () use ($socket, $queue, $logger, $cancellation, $prefetchedMessages): void {
            try {
                $parser = new ProtocolParser($queue->push(...));

                if ($prefetchedMessages !== []) {
                    $logger?->debug('Pushing prefetched protocol messages before read loop', [
                        'count' => count($prefetchedMessages),
                    ]);
                    foreach ($prefetchedMessages as $prefetchedMessage) {
                        $queue->push($prefetchedMessage);
                    }
                }

                $logger?->debug('Reading the socket');

                while (null !== $chunk = $socket?->read($cancellation)) {
                    $logger?->debug('A new chunk has received', ['chunk' => $chunk]);

                    $parser->push($chunk);

                    $logger?->debug('Chunk has processed with the parser');
                }

                $logger?->debug('Cancelling the parser');
                $parser->cancel();

                $logger?->debug('Completing the queue');
                $queue->complete();
            } catch (CancelledException) {
                $logger?->info('Received a termination signal. Completing the queue.');
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
        if ($this->isClosed()) {
            return;
        }

        try {
            $this->socket?->write((string) $message);
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

    private function createConnectUri(): string
    {
        return sprintf(
            'tcp://%s:%d',
            $this->configuration->getHost(),
            $this->configuration->getPort()
        );
    }

    private function createConnectContext(): ConnectContext
    {
        $context = new ConnectContext();
        $tls     = $this->configuration->getTlsConfiguration();
        if (!$tls->isEnabled()) {
            return $context;
        }

        $peerName   = $tls->getServerName() ?? $this->configuration->getHost();
        $tlsContext = new ClientTlsContext($peerName);
        $tlsContext = $tls->isVerifyPeer()
            ? $tlsContext->withPeerVerification()
            : $tlsContext->withoutPeerVerification();

        if ($tls->getCaFile() !== null) {
            $tlsContext = $tlsContext->withCaFile($tls->getCaFile());
        }

        if ($tls->getCaPath() !== null) {
            $tlsContext = $tlsContext->withCaPath($tls->getCaPath());
        }

        if ($tls->isAllowSelfSigned()) {
            $tlsContext = $tlsContext->withSecurityLevel(0);
        }

        if ($tls->getClientCertFile() !== null) {
            $tlsContext = $tlsContext->withCertificate(new Certificate(
                $tls->getClientCertFile(),
                $tls->getClientKeyFile(),
                $tls->getClientKeyPassphrase(),
            ));
        }

        if ($tls->getMinVersion() !== null) {
            $version = match ($tls->getMinVersion()) {
                'TLSv1.0' => ClientTlsContext::TLSv1_0,
                'TLSv1.1' => ClientTlsContext::TLSv1_1,
                'TLSv1.2' => ClientTlsContext::TLSv1_2,
                'TLSv1.3' => ClientTlsContext::TLSv1_3,
                default => throw new ConnectionException(
                    sprintf('Unsupported TLS minimum version "%s"', $tls->getMinVersion()),
                ),
            };
            $tlsContext = $tlsContext->withMinimumVersion($version);
        }

        if ($tls->getAlpnProtocols() !== null) {
            $tlsContext = $tlsContext->withApplicationLayerProtocols($tls->getAlpnProtocols());
        }

        return $context->withTlsContext($tlsContext);
    }

    /**
     * @return list<NatsProtocolMessageInterface>
     */
    private function readPrefaceMessagesBeforeTlsHandshake(
        Socket $socket,
        Cancellation|null $cancellation,
    ): array {
        $buffer   = '';
        $messages = [];
        $parser   = new ProtocolParser(static function (NatsProtocolMessageInterface $message) use (&$messages): void {
            $messages[] = $message;
        });

        while ($messages === []) {
            $chunk = $socket->read($cancellation);
            if ($chunk === null) {
                throw new ConnectionException('Expected server INFO before TLS handshake, got EOF');
            }

            $buffer .= $chunk;
            if (strlen($buffer) > 16_384) {
                throw new ConnectionException('Server INFO preface exceeded 16KB before TLS handshake');
            }

            $parser->push($chunk);
        }

        if (!$messages[0] instanceof InfoMessageInterface) {
            throw new ConnectionException(sprintf(
                'Expected INFO message before TLS handshake, got "%s"',
                $messages[0]->getType()->value,
            ));
        }

        return $messages;
    }
}
