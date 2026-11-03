<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Amp\Cancellation;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use Dorpmaster\Nats\Client\SubscriptionIdHelper;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SingleAttemptRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilitiesProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Internal\ApiError;
use Dorpmaster\Nats\Domain\JetStream\Internal\PlacementResponseException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\Internal\ValidatePublishTokens;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;

final readonly class JetStreamControlPlaneTransport implements JetStreamConnectionBoundTransportInterface, JetStreamCapabilitiesProviderInterface
{
    use ValidatePublishTokens;

    private const string REPLY_PREFIX = 'JSREPLY';
    private SubscriptionIdHelperInterface $subscriptionIdHelper;
    private LoggerInterface $logger;
    private string $apiPrefix;

    public function __construct(
        private ClientInterface $client,
        SubscriptionIdHelperInterface|null $subscriptionIdHelper = null,
        LoggerInterface|null $logger = null,
        string $apiPrefix = '$JS.API',
    ) {
        if (preg_match('/\A[^\s\p{Cc}.*>]+(?:\.[^\s\p{Cc}.*>]+)*\.?\z/u', $apiPrefix) !== 1) {
            throw new InvalidArgumentException('Invalid API prefix');
        }
        $this->apiPrefix            = str_ends_with($apiPrefix, '.') ? substr($apiPrefix, 0, -1) : $apiPrefix;
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

    public function getApiPrefix(): string
    {
        return $this->apiPrefix;
    }

    public function getCapabilities(): JetStreamCapabilities
    {
        return JetStreamCapabilities::forClient($this->client, $this->apiPrefix);
    }

    public function request(string $apiSubjectSuffix, array $payload, int|null $timeoutMs = null): array
    {
        $this->validatePublishTokens($apiSubjectSuffix);
        try {
            $body = JsonObject::fromArray($payload);
        } catch (JsonException $exception) {
            throw new JetStreamApiException(0, 'Failed to encode JetStream API request payload as JSON', $exception);
        }
        return $this->requestJson($apiSubjectSuffix, $body, $timeoutMs)->toArray();
    }

    public function requestJson(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs = null): JsonObject
    {
        $this->validatePublishTokens($apiSubjectSuffix);
        $this->rejectReplayablePlacement($apiSubjectSuffix);
        // Mutating delivery/configuration state must not become replayable through direct transport calls either.
        return $this->performJsonRequest($apiSubjectSuffix, $payload, $timeoutMs, str_starts_with($apiSubjectSuffix, 'STREAM.UPDATE.') || str_starts_with($apiSubjectSuffix, 'CONSUMER.RESET.'));
    }

    public function requestJsonOnce(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs = null): JsonObject
    {
        return $this->performJsonRequest($apiSubjectSuffix, $payload, $timeoutMs, true);
    }

    public function requestJsonOnConnection(string $suffix, JsonObject $payload, int $connectionId, int $timeoutMs, Cancellation|null $cancellation = null): JsonObject
    {
        return $this->performJsonRequest($suffix, $payload, $timeoutMs, true, $connectionId, $cancellation);
    }

    private function performJsonRequest(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs, bool $once, int|null $connectionId = null, Cancellation|null $cancellation = null): JsonObject
    {
        $this->validatePublishTokens($apiSubjectSuffix);
        if ($once && !$this->client instanceof SingleAttemptRequestClientInterface) {
            throw new JetStreamApiException(0, 'Single-attempt JetStream requests require SingleAttemptRequestClientInterface');
        }
        if ($connectionId !== null && !$this->client instanceof ConnectionBoundRequestClientInterface) {
            throw new JetStreamApiException(0, 'Tracked JetStream requests require ConnectionBoundRequestClientInterface');
        }
        $subject = $this->apiPrefix . '.' . $apiSubjectSuffix;
        $this->logger->debug('js.control.request', ['subject' => $subject, 'timeout_ms' => $timeoutMs]);
        $replyTo = self::REPLY_PREFIX . $this->subscriptionIdHelper->generateId();
        $message = new JetStreamControlPlaneRequestMessage($subject, $payload->toJson(), $replyTo);
        $result  = $connectionId !== null
            ? $this->client->requestOnceOnConnection($message, $connectionId, ($timeoutMs ?? 2000) / 1000, $cancellation)
            : ($once
            ? $this->client->requestOnce($message, $timeoutMs === null ? 30 : $timeoutMs / 1000)
            : ($timeoutMs === null ? $this->client->request($message) : $this->client->request($message, $timeoutMs / 1000)));
        if ($connectionId !== null && $result instanceof StatusMessageInterface && ($status = $result->getStatus()) !== null) {
            throw new JetStreamApiException($status->code, $status->description);
        }
        if (!method_exists($result, 'getPayload')) {
            throw new JetStreamApiException(0, 'JetStream API response message does not provide payload');
        }
        $responsePayload = $result->getPayload();
        if (!is_string($responsePayload)) {
            throw new JetStreamApiException(0, 'JetStream API response payload must be a string');
        }
        try {
            $decoded = new JsonObject($responsePayload);
        } catch (JsonException $exception) {
            $this->logger->warning('js.control.invalid_json', ['operation' => 'request', 'error_type' => $exception::class]);
            throw new JetStreamApiException(0, 'Failed to decode JetStream API response JSON; response must be a JSON object', $exception);
        }
        $error = ApiError::fromResponse(json_decode($decoded->toJson(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING), 0, 'JetStream API error');
        if ($error !== null) {
            $this->logger->warning('js.control.error', [
                'operation' => 'request',
                'code' => $error->getApiCode(),
                'err_code' => $error->getErrorCode(),
                'error_type' => $error::class,
            ]);
            throw $connectionId === null ? $error : new PlacementResponseException($decoded, $error);
        }
        $this->logger->debug('js.control.response', ['subject' => $subject, 'ok' => true]);
        return $decoded;
    }

    private function rejectReplayablePlacement(string $suffix): void
    {
        $command = '(?:STREAM\.(?:CANCEL_MOVE|PEER\.EVACUATE)\.|CONSUMER\.PEER\.EVACUATE\.|SERVER\.EVACUATE(?:\z|\.))';
        $subject = $this->apiPrefix . '.' . $suffix;
        if (preg_match('/\A' . $command . '/', $suffix) === 1 || preg_match('/\A\$JS\.(?:[^.]+\.)?API\.' . $command . '/', $subject) === 1) {
            throw new JetStreamApiException(0, 'Placement commands require a connection-bound single attempt; use JetStreamPlacementAdminInterface');
        }
    }

    public function publishRequest(
        string $apiSubjectSuffix,
        array $payload,
        string $replyTo,
        array $headers = [],
    ): void {
        $this->validatePublishTokens($apiSubjectSuffix);
        $this->rejectReplayablePlacement($apiSubjectSuffix);

        $subject = $this->apiPrefix . '.' . $apiSubjectSuffix;
        $body    = $payload === [] ? new stdClass() : $payload;
        $this->validatePublishTokens($subject, $replyTo);
        $this->logger->debug('js.control.publish_request', [
            'subject' => $subject,
            'reply_to' => $replyTo,
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
