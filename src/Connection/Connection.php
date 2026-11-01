<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Connection;

use Amp\ByteStream\ResourceStream;
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
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Domain\Connection\ServerSelectableConnectionInterface;
use Dorpmaster\Nats\Protocol\Contracts\InfoMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Parser\ProtocolParser;
use Dorpmaster\Nats\Telemetry\ProtocolLogContext;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Throwable;

final class Connection implements ConnectionInterface, ServerSelectableConnectionInterface
{
    private Socket|null $socket               = null;
    private ConcurrentIterator|null $iterator = null;
    private ServerAddress|null $activeServer  = null;

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
        $tlsConfiguration   = $this->configuration->getTlsConfiguration();
        $prefetchedMessages = [];

        try {
            $context = $this->createConnectContext();
            $this->logger?->debug('Starting to open TCP connection', [
                'stage' => 'tcp_open',
                'tls_enabled' => $tlsConfiguration->isEnabled(),
            ]);

            $this->socket = $this->connector->connect($url, $context, $cancellation);

            if ($tlsConfiguration->isEnabled()) {
                $this->configureTlsStream($this->socket, $context);
                $prefetchedMessages = $this->readPrefaceMessagesBeforeTlsHandshake($this->socket, $cancellation);

                $this->logger?->debug('Starting TLS handshake', [
                    'verify_peer' => $tlsConfiguration->isVerifyPeer(),
                    'stage' => 'tls_handshake',
                ]);
                $this->socket->setupTls($cancellation);
            }
        } catch (Throwable $exception) {
            $failedSocket = $this->socket;
            $this->socket = null;
            try {
                $failedSocket?->close();
            } catch (Throwable $cleanupError) {
                $this->logger?->error('Failed to close unsuccessful transport', [
                    'stage' => 'connection_cleanup',
                    ...ProtocolLogContext::exception($cleanupError),
                ]);
            }

            $error = $tlsConfiguration->isEnabled()
                ? 'TLS handshake failed: ' . $exception->getMessage()
                : $exception->getMessage();

            $this->logger?->error('Connection open failed', [
                'stage' => $tlsConfiguration->isEnabled() ? 'tls_handshake' : 'tcp_open',
                ...ProtocolLogContext::exception($exception),
                'tls_enabled' => $tlsConfiguration->isEnabled(),
                'verify_peer' => $tlsConfiguration->isVerifyPeer(),
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
                    $logger?->debug('A new chunk has received', ['stage' => 'read', 'bytes' => strlen($chunk)]);

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
                    'stage' => 'read',
                    ...ProtocolLogContext::exception($e),
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
                'stage' => 'write',
                ...ProtocolLogContext::message($message),
                ...ProtocolLogContext::exception($exception),
            ]);

            throw new ConnectionException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    public function isClosed(): bool
    {
        return $this->socket?->isClosed() ?? true;
    }

    public function useServer(ServerAddress $server): void
    {
        $this->activeServer = $server;
    }

    private function createConnectUri(): string
    {
        $host = $this->activeServer?->getHost() ?? $this->configuration->getHost();
        $port = $this->activeServer?->getPort() ?? $this->configuration->getPort();

        return sprintf(
            'tcp://%s:%d',
            $host,
            $port
        );
    }

    private function createConnectContext(): ConnectContext
    {
        $connectTimeout = $this->configuration->getConnectTimeout();
        if ($connectTimeout <= 0) {
            $connectTimeout = 1.0;
        }

        $context = (new ConnectContext())->withConnectTimeout($connectTimeout);
        $tls     = $this->configuration->getTlsConfiguration();
        if (!$tls->isEnabled()) {
            return $context;
        }

        $host       = $this->activeServer?->getHost() ?? $this->configuration->getHost();
        $peerName   = $this->activeServer?->getServerName() ?? $tls->getServerName() ?? $host;
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

    private function configureTlsStream(Socket $socket, ConnectContext $context): void
    {
        $allowSelfSigned = $this->configuration->getTlsConfiguration()->isAllowSelfSigned();
        if (!$socket instanceof ResourceStream) {
            if ($allowSelfSigned) {
                throw new ConnectionException('allowSelfSigned requires a socket exposing a PHP stream resource');
            }

            return;
        }

        $resource = $socket->getResource();
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new ConnectionException('TLS configuration requires an open PHP stream resource');
        }

        // ClientTlsContext has no allow_self_signed API. ResourceSocket merges its
        // options before crypto, preserving this additional PHP stream option.
        $options                             = $context->getTlsContext()?->toStreamContextArray() ?? [];
        $options['ssl']['allow_self_signed'] = $allowSelfSigned;
        stream_context_set_options($resource, $options);
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
