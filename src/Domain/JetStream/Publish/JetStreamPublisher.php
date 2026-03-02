<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\Client\ClientNotConnectedException;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Model\PubAck;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\Contracts\PubMessageInterface;
use Dorpmaster\Nats\Protocol\HPubMessage;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\PubMessage;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function Amp\delay;

final readonly class JetStreamPublisher implements JetStreamPublisherInterface
{
    private const int DEFAULT_TIMEOUT_MS = 2000;

    public function __construct(
        private ClientInterface $client,
        private LoggerInterface|null $logger = null,
    ) {
    }

    public function publish(
        string $subject,
        string $payload,
        PublishOptions|null $options = null,
        int|null $timeoutMs = null,
    ): PubAck {
        $effectiveOptions   = $options ?? PublishOptions::create();
        $message            = $this->createRequestMessage($subject, $payload, $effectiveOptions);
        $effectiveTimeoutMs = $timeoutMs ?? self::DEFAULT_TIMEOUT_MS;
        $deadline           = microtime(true) + ($effectiveTimeoutMs / 1000);
        $headers            = $effectiveOptions->toHeaders();
        $this->getLogger()->debug('js.publish.start', [
            'subject' => $subject,
            'timeout_ms' => $effectiveTimeoutMs,
            'has_msg_id' => isset($headers['Nats-Msg-Id']),
            'bytes' => strlen($payload),
        ]);

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->getLogger()->warning('js.publish.timeout', [
                    'subject' => $subject,
                    'timeout_ms' => $effectiveTimeoutMs,
                ]);
                throw new JetStreamTimeoutException();
            }

            try {
                $response = $this->client->request($message, $remaining);

                break;
            } catch (ClientNotConnectedException) {
                if (microtime(true) >= $deadline) {
                    $this->getLogger()->warning('js.publish.timeout', [
                        'subject' => $subject,
                        'timeout_ms' => $effectiveTimeoutMs,
                    ]);
                    throw new JetStreamTimeoutException();
                }

                $this->getLogger()->warning('js.publish.retry', [
                    'reason' => ClientNotConnectedException::class,
                    'remaining_ms' => (int) max(0, round(($deadline - microtime(true)) * 1000)),
                ]);
                delay(0.05);
                continue;
            } catch (CancelledException $exception) {
                $this->getLogger()->warning('js.publish.timeout', [
                    'subject' => $subject,
                    'timeout_ms' => $effectiveTimeoutMs,
                ]);
                throw new JetStreamTimeoutException(previous: $exception);
            } catch (Throwable $exception) {
                $this->getLogger()->warning('js.publish.error', [
                    'code' => 500,
                    'description' => $exception->getMessage(),
                ]);
                throw new JetStreamApiException(500, $exception->getMessage(), $exception);
            }
        }

        if (!method_exists($response, 'getPayload')) {
            throw new JetStreamApiException(500, 'JetStream publish response message does not provide payload');
        }

        $payloadResponse = $response->getPayload();

        try {
            $decoded = json_decode($payloadResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JetStreamApiException(500, 'Failed to decode JetStream PubAck JSON', $exception);
        }

        if (!is_array($decoded)) {
            throw new JetStreamApiException(500, 'JetStream PubAck response must be a JSON object');
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $code        = (int) ($decoded['error']['code'] ?? 500);
            $description = (string) ($decoded['error']['description'] ?? 'JetStream publish error');
            $this->getLogger()->warning('js.publish.error', [
                'code' => $code,
                'description' => $description,
            ]);

            throw new JetStreamApiException($code, $description);
        }

        try {
            $ack = PubAck::fromArray($decoded);
            $this->getLogger()->debug('js.publish.ack', [
                'stream' => $ack->getStream(),
                'seq' => $ack->getSeq(),
                'duplicate' => $ack->isDuplicate(),
            ]);

            return $ack;
        } catch (Throwable $exception) {
            $this->getLogger()->warning('js.publish.error', [
                'code' => 500,
                'description' => $exception->getMessage(),
            ]);
            throw new JetStreamApiException(500, $exception->getMessage(), $exception);
        }
    }

    private function createRequestMessage(
        string $subject,
        string $payload,
        PublishOptions $options,
    ): PubMessageInterface|HPubMessageInterface {
        $headers = $options->toHeaders();
        if ($headers === []) {
            return new PubMessage($subject, $payload);
        }

        return new HPubMessage($subject, $payload, new HeaderBag($headers));
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
