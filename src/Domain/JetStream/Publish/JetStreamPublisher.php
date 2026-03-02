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
use Throwable;

use function Amp\delay;

final readonly class JetStreamPublisher implements JetStreamPublisherInterface
{
    private const int DEFAULT_TIMEOUT_MS = 2000;

    public function __construct(
        private ClientInterface $client,
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

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new JetStreamTimeoutException();
            }

            try {
                $response = $this->client->request($message, $remaining);

                break;
            } catch (ClientNotConnectedException) {
                if (microtime(true) >= $deadline) {
                    throw new JetStreamTimeoutException();
                }

                delay(0.05);
                continue;
            } catch (CancelledException $exception) {
                throw new JetStreamTimeoutException(previous: $exception);
            } catch (Throwable $exception) {
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

            throw new JetStreamApiException($code, $description);
        }

        try {
            return PubAck::fromArray($decoded);
        } catch (Throwable $exception) {
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
}
