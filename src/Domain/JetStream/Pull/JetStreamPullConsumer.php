<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamClientAcknowledger;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcker;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAckerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessage;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\Contracts\HMsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\MsgMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

        $inbox             = 'INBOX.' . $this->transport->getSubscriptionIdHelper()->generateId();
        $messages          = [];
        $deferredCompleted = new DeferredFuture();
        $fetchError        = null;
        $result            = null;
        $this->logger->debug('js.pull.fetch', [
            'stream' => $this->stream,
            'consumer' => $this->consumer,
            'batch' => $batch,
            'expires_ms' => $expiresMs,
            'no_wait' => $noWait,
            'max_bytes' => $maxBytes,
            'idle_hb_ms' => $idleHeartbeatMs,
        ]);

        $sid = $this->transport->getClient()->subscribe($inbox, function (NatsProtocolMessageInterface $message) use (&$messages, $batch, $deferredCompleted, &$fetchError): null {
            try {
                $jetStreamMessage = $this->toJetStreamMessage($message);
                if ($jetStreamMessage === null) {
                    return null;
                }

                $messages[] = $jetStreamMessage;

                if (count($messages) >= $batch && !$deferredCompleted->isComplete()) {
                    $deferredCompleted->complete(true);
                }
            } catch (JetStreamApiException $exception) {
                $fetchError = $exception;

                if (!$deferredCompleted->isComplete()) {
                    $deferredCompleted->complete(false);
                }
            }

            return null;
        });

        try {
            $requestPayload = [
                'batch' => $batch,
                'expires' => $expiresMs * 1_000_000,
                'no_wait' => $noWait,
            ];

            if ($maxBytes !== null) {
                $requestPayload['max_bytes'] = $maxBytes;
            }

            if ($idleHeartbeatMs !== null) {
                $requestPayload['idle_heartbeat'] = $idleHeartbeatMs * 1_000_000;
            }

            $this->transport->publishRequest(
                sprintf('CONSUMER.MSG.NEXT.%s.%s', $this->stream, $this->consumer),
                $requestPayload,
                $inbox,
            );

            if ($noWait) {
                if ($fetchError instanceof JetStreamApiException) {
                    throw $fetchError;
                }

                $result = new JetStreamFetchResult($messages, $this->messageAcker);
            } else {
                $cancellation = new TimeoutCancellation(($expiresMs + self::FETCH_TIMEOUT_OVERHEAD_MS) / 1000);
                $cid          = $cancellation->subscribe(static function () use ($deferredCompleted): void {
                    if (!$deferredCompleted->isComplete()) {
                        $deferredCompleted->complete(false);
                    }
                });

                try {
                    $deferredCompleted->getFuture()->await();
                } finally {
                    $cancellation->unsubscribe($cid);
                }

                if ($fetchError instanceof JetStreamApiException) {
                    throw $fetchError;
                }

                $result = new JetStreamFetchResult($messages, $this->messageAcker);
            }
        } finally {
            $this->transport->getClient()->unsubscribe($sid);
        }

        if ($result === null) {
            throw new \LogicException('JetStream fetch result was not produced');
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
            $this->throwIfJsonErrorPayload($payload);
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

    private function throwIfJsonErrorPayload(string $payload): void
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return;
        }

        $code        = (int) ($decoded['error']['code'] ?? 500);
        $description = (string) ($decoded['error']['description'] ?? 'JetStream pull fetch error');

        if ($code === 404) {
            $this->logger->debug('js.pull.fetch.empty', [
                'code' => $code,
            ]);
            return;
        }

        throw new JetStreamApiException($code, $description);
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
