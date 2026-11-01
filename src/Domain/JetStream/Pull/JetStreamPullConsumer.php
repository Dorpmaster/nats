<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Amp\DeferredFuture;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ResourceName;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamClientAcknowledger;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcker;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAckerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\WireBodySizeMessageInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Throwable;

final class JetStreamPullConsumer implements JetStreamPullConsumerInterface
{
    private const int FETCH_TIMEOUT_OVERHEAD_MS = 200;

    private JetStreamMessageAcknowledgerInterface $acknowledger;
    private JetStreamMessageAckerInterface $messageAcker;
    private JetStreamConsumeLoop $consumeLoop;
    private LoggerInterface $logger;

    public function __construct(
        private readonly JetStreamControlPlaneTransportInterface $transport,
        private readonly string $stream,
        private readonly string $consumer,
        JetStreamMessageAcknowledgerInterface|null $acknowledger = null,
        JetStreamConsumeLoop|null $consumeLoop = null,
        LoggerInterface|null $logger = null,
    ) {
        ResourceName::validate($this->stream, 'stream');
        ResourceName::validate($this->consumer, 'consumer');

        $this->logger       = $logger ?? new NullLogger();
        $this->acknowledger = $acknowledger ?? new JetStreamClientAcknowledger($this->transport->getClient());
        $this->messageAcker = new LoggingJetStreamMessageAcker(
            new JetStreamMessageAcker($this->acknowledger),
            $this->logger,
        );
        $this->consumeLoop  = $consumeLoop ?? new JetStreamConsumeLoop(
            logger: $this->logger,
            stream: $this->stream,
            consumer: $this->consumer,
        );
    }

    public function fetch(
        int $batch,
        int $expiresMs,
        bool $noWait = false,
        int|null $maxBytes = null,
        int|null $idleHeartbeatMs = null,
    ): JetStreamFetchResult {
        if ($batch <= 0) {
            throw new \InvalidArgumentException('batch must be greater than zero');
        }

        if ($expiresMs <= 0) {
            throw new \InvalidArgumentException('expiresMs must be greater than zero');
        }

        $inbox         = 'INBOX.' . $this->transport->getSubscriptionIdHelper()->generateId();
        $client        = $this->transport->getClient();
        $messages      = [];
        $receivedBytes = 0;
        $completed     = new DeferredFuture();
        $done          = false;
        $fetchError    = null;
        $primaryError  = null;
        $finish        = static function (Throwable|null $error = null) use (&$done, &$fetchError, $completed): void {
            if ($done) {
                return;
            }

            $done       = true;
            $fetchError = $error;
            $completed->complete();
        };
        $this->logger->debug('js.pull.fetch', [
            'stream' => $this->stream,
            'consumer' => $this->consumer,
            'batch' => $batch,
            'expires_ms' => $expiresMs,
            'no_wait' => $noWait,
            'max_bytes' => $maxBytes,
            'idle_hb_ms' => $idleHeartbeatMs,
        ]);

        $sid = $client->subscribe($inbox, function (NatsProtocolMessageInterface $message) use (&$messages, &$receivedBytes, &$done, $batch, $maxBytes, $finish): null {
            if ($done) {
                return null;
            }

            try {
                if ($message instanceof StatusMessageInterface && ($status = $message->getStatus()) !== null) {
                    if ($status->code === 100) {
                        return null;
                    }

                    if ($this->isTerminalStatus($status->code, $status->description)) {
                        $finish();
                        return null;
                    }

                    throw new JetStreamApiException($status->code, $status->description);
                }

                if (
                    ($message instanceof MsgMessageInterface || $message instanceof HMsgMessageInterface)
                    && ($message->getReplyTo() === null || $message->getReplyTo() === '')
                ) {
                    $error = $this->jsonError($message->getPayload());
                    if ($error !== null) {
                        if ($error->getApiCode() !== 404) {
                            throw $error;
                        }

                        $this->logger->debug('js.pull.fetch.empty', ['code' => 404]);
                        $finish();
                        return null;
                    }
                }

                $jetStreamMessage = $this->toJetStreamMessage($message);
                if ($jetStreamMessage === null) {
                    return null;
                }

                $messages[] = $jetStreamMessage;
                // NATS counts the original subject, ACK reply, headers and payload against max_bytes.
                $bodySize       = $message instanceof WireBodySizeMessageInterface
                    ? $message->getWireBodySize()
                    : ($message instanceof HMsgMessageInterface ? $message->getTotalSize() : strlen($message->getPayload()));
                $receivedBytes += strlen($message->getSubject()) + strlen($message->getReplyTo() ?? '') + $bodySize;
                if (count($messages) >= $batch || ($maxBytes !== null && $receivedBytes >= $maxBytes)) {
                    $finish();
                }
            } catch (Throwable $error) {
                $finish($error);
            }

            return null;
        });

        $timer = null;
        try {
            $timer          = EventLoop::delay(
                ($expiresMs + ($noWait ? 0 : self::FETCH_TIMEOUT_OVERHEAD_MS)) / 1000,
                static function () use ($finish, $noWait): void {
                    $finish($noWait ? new JetStreamTimeoutException('JetStream pull response timed out') : null);
                },
            );
            $requestPayload = ['batch' => $batch];
            if (!$noWait) {
                $requestPayload['expires'] = $expiresMs * 1_000_000;
                if ($idleHeartbeatMs !== null) {
                    $requestPayload['idle_heartbeat'] = $idleHeartbeatMs * 1_000_000;
                }
            }

            $requestPayload['no_wait'] = $noWait;

            if ($maxBytes !== null) {
                $requestPayload['max_bytes'] = $maxBytes;
            }

            $this->transport->publishRequest(
                sprintf('CONSUMER.MSG.NEXT.%s.%s', $this->stream, $this->consumer),
                $requestPayload,
                $inbox,
            );
            $completed->getFuture()->await();
            if ($fetchError !== null) {
                throw $fetchError;
            }

            $result = new JetStreamFetchResult($messages, $this->messageAcker);
        } catch (Throwable $error) {
            $primaryError = $error;
            $finish($error);
            throw $error;
        } finally {
            $done = true;
            if ($timer !== null) {
                EventLoop::cancel($timer);
            }

            try {
                $client->unsubscribe($sid);
            } catch (Throwable $cleanupError) {
                if ($primaryError === null) {
                    throw $cleanupError;
                }

                $this->logger->debug('js.pull.fetch.cleanup_failed', ['error_type' => $cleanupError::class]);
            }
        }

        $this->logger->debug('js.pull.fetch.result', [
            'received' => $result->getReceivedCount(),
            'empty' => $result->getReceivedCount() === 0,
        ]);

        return $result;
    }

    private function toJetStreamMessage(NatsProtocolMessageInterface $message): JetStreamMessageInterface|null
    {
        if (!($message instanceof MsgMessageInterface || $message instanceof HMsgMessageInterface)) {
            return null;
        }

        if (!method_exists($message, 'getPayload')) {
            return null;
        }

        $payload = $message->getPayload();
        $replyTo = $message->getReplyTo();

        if ($replyTo === null || $replyTo === '') {
            return null;
        }

        $headers = [];
        if ($message instanceof HMsgMessageInterface) {
            foreach ($message->getHeaders() as $name => $values) {
                if (is_array($values)) {
                    $headers[$name] = (string) reset($values);
                } else {
                    $headers[$name] = (string) $values;
                }
            }
        }

        $deliveryCount = $this->extractDeliveryCount($headers);

        return new JetStreamMessage(
            subject: $message->getSubject(),
            payload: $payload,
            headers: $headers,
            replyTo: $replyTo,
            sizeBytes: $this->calculateMessageSizeBytes($payload, $headers),
            deliveryCount: $deliveryCount,
        );
    }

    public function consume(PullConsumeOptions $options): JetStreamConsumeHandle
    {
        $handle = new JetStreamConsumeHandle(
            $this->messageAcker,
            $options,
            logger: $this->logger,
            stream: $this->stream,
            consumer: $this->consumer,
        );

        $this->consumeLoop->start(
            $handle,
            function () use ($options): JetStreamFetchResult {
                return $this->fetch(
                    batch: $options->batch,
                    expiresMs: $options->expiresMs,
                    noWait: $options->noWait,
                    maxBytes: $options->maxBytes,
                    idleHeartbeatMs: $options->idleHeartbeatMs,
                );
            },
        );

        return $handle;
    }

    private function isTerminalStatus(int $code, string $description): bool
    {
        return $code === 404 || $code === 408
            || ($code === 409 && in_array(strtolower($description), [
                'batch completed',
                'message size exceeds maxbytes',
            ], true));
    }

    private function jsonError(string $payload): JetStreamApiException|null
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return null;
        }

        return new JetStreamApiException(
            (int) ($decoded['error']['code'] ?? 500),
            (string) ($decoded['error']['description'] ?? 'JetStream pull fetch error'),
        );
    }

    /** @param array<string, string> $headers */
    private function calculateMessageSizeBytes(string $payload, array $headers): int
    {
        $size = strlen($payload);
        foreach ($headers as $name => $value) {
            $size += strlen($name) + strlen($value);
        }

        return $size;
    }

    /** @param array<string, string> $headers */
    private function extractDeliveryCount(array $headers): int|null
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'nats-num-delivered') {
                continue;
            }

            if (!is_numeric($value)) {
                return null;
            }

            $count = (int) $value;
            if ($count < 1) {
                return null;
            }

            return $count;
        }

        return null;
    }
}
