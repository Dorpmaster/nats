<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Dorpmaster\Nats\Client\SubscriptionIdHelper;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;

final readonly class JetStreamControlPlaneTransport implements JetStreamControlPlaneTransportInterface
{
    private const string API_PREFIX   = '$JS.API.';
    private const string REPLY_PREFIX = 'JSREPLY';
    private SubscriptionIdHelperInterface $subscriptionIdHelper;
    private LoggerInterface $logger;

    public function __construct(
        private ClientInterface $client,
        SubscriptionIdHelperInterface|null $subscriptionIdHelper = null,
        LoggerInterface|null $logger = null,
    ) {
        $this->subscriptionIdHelper = $subscriptionIdHelper ?? new SubscriptionIdHelper();
        $this->logger               = $logger ?? new NullLogger();
    }

    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    public function getSubscriptionIdHelper(): SubscriptionIdHelperInterface
    {
        return $this->subscriptionIdHelper;
    }

    public function request(string $apiSubjectSuffix, array $payload, int|null $timeoutMs = null): array
    {
        $subject = self::API_PREFIX . $apiSubjectSuffix;
        $body    = $payload === [] ? new stdClass() : $payload;
        $this->logger->debug('js.control.request', [
            'subject' => $subject,
            'timeout_ms' => $timeoutMs,
            'payload_keys' => array_keys($payload),
        ]);

        try {
            $requestPayload = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->warning('js.control.invalid_json', [
                'subject' => $subject,
            ]);
            throw new JetStreamApiException(0, 'Failed to encode JetStream API request payload as JSON', $exception);
        }

        $replyTo = self::REPLY_PREFIX . $this->subscriptionIdHelper->generateId();
        $message = new JetStreamControlPlaneRequestMessage($subject, $requestPayload, $replyTo);
        $result  = $timeoutMs === null
            ? $this->client->request($message)
            : $this->client->request($message, $timeoutMs / 1000);

        if (!method_exists($result, 'getPayload')) {
            $this->logger->error('js.control.invalid_response', [
                'subject' => $subject,
            ]);
            throw new JetStreamApiException(0, 'JetStream API response message does not provide payload');
        }

        $responsePayload = $result->getPayload();

        try {
            $decoded = json_decode($responsePayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->warning('js.control.invalid_json', [
                'subject' => $subject,
            ]);
            throw new JetStreamApiException(0, 'Failed to decode JetStream API response JSON', $exception);
        }

        if (!is_array($decoded)) {
            $this->logger->warning('js.control.invalid_json', [
                'subject' => $subject,
            ]);
            throw new JetStreamApiException(0, 'JetStream API response must be a JSON object');
        }

        if (isset($decoded['error']) && is_array($decoded['error'])) {
            $code        = (int) ($decoded['error']['code'] ?? 0);
            $description = (string) ($decoded['error']['description'] ?? 'JetStream API error');
            $this->logger->warning('js.control.error', [
                'subject' => $subject,
                'code' => $code,
                'description' => $description,
            ]);

            throw new JetStreamApiException($code, $description);
        }

        $this->logger->debug('js.control.response', [
            'subject' => $subject,
            'ok' => true,
        ]);

        return $decoded;
    }

    public function publishRequest(
        string $apiSubjectSuffix,
        array $payload,
        string $replyTo,
        array $headers = [],
    ): void {
        $subject = self::API_PREFIX . $apiSubjectSuffix;
        $body    = $payload === [] ? new stdClass() : $payload;
        $this->logger->debug('js.control.publish_request', [
            'subject' => $subject,
            'reply_to' => $replyTo,
            'payload_keys' => array_keys($payload),
        ]);

        try {
            $requestPayload = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logger->warning('js.control.invalid_json', [
                'subject' => $subject,
            ]);
            throw new JetStreamApiException(0, 'Failed to encode JetStream API request payload as JSON', $exception);
        }

        if ($headers === []) {
            $message = new JetStreamRawPubMessage($subject, $requestPayload, $replyTo);
        } else {
            $message = new JetStreamControlPlaneHeadersRequestMessage(
                $subject,
                $requestPayload,
                new HeaderBag($headers),
                $replyTo,
            );
        }

        $this->client->publish($message);
    }
}
