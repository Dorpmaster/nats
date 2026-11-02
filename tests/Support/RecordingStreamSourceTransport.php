<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilitiesProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;

/** Explicit test evidence only; these records are never passed to the production logger. */
final class RecordingStreamSourceTransport implements JetStreamSingleAttemptTransportInterface, JetStreamCapabilitiesProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function __construct(private JetStreamControlPlaneTransport $inner, private \Closure|null $responseFilter = null)
    {
    }

    public function getCapabilities(): JetStreamCapabilities
    {
        return $this->inner->getCapabilities();
    }

    public function getClient(): ClientInterface
    {
        return $this->inner->getClient();
    }

    public function getSubscriptionIdHelper(): SubscriptionIdHelperInterface
    {
        return $this->inner->getSubscriptionIdHelper();
    }

    public function request(string $apiSubjectSuffix, array $payload, int|null $timeoutMs = null): array
    {
        return $this->requestJson($apiSubjectSuffix, JsonObject::fromArray($payload), $timeoutMs)->toArray();
    }

    public function publishRequest(string $apiSubjectSuffix, array $payload, string $replyTo, array $headers = []): void
    {
        $this->inner->publishRequest($apiSubjectSuffix, $payload, $replyTo, $headers);
    }

    public function requestJson(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs = null): JsonObject
    {
        return $this->record($apiSubjectSuffix, $payload, $timeoutMs, false);
    }

    public function requestJsonOnce(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs = null): JsonObject
    {
        return $this->record($apiSubjectSuffix, $payload, $timeoutMs, true);
    }

    private function record(string $apiSubjectSuffix, JsonObject $payload, int|null $timeoutMs, bool $once): JsonObject
    {
        $index           = count($this->records);
        $this->records[] = ['subject' => $this->getCapabilities()->apiPrefix . '.' . $apiSubjectSuffix, 'body' => $payload->toJson(), 'timeout_ms' => $timeoutMs, 'once' => $once];
        try {
            $response                          = $once ? $this->inner->requestJsonOnce($apiSubjectSuffix, $payload, $timeoutMs) : $this->inner->requestJson($apiSubjectSuffix, $payload, $timeoutMs);
            $this->records[$index]['response'] = $response->toJson();
            $delivered                         = $this->responseFilter === null ? $response : ($this->responseFilter)($apiSubjectSuffix, $response);
            if ($delivered->toJson() !== $response->toJson()) {
                $this->records[$index]['delivered_response'] = $delivered->toJson();
            }
            return $delivered;
        } catch (JetStreamApiException $error) {
            $this->records[$index]['error'] = ['code' => $error->getApiCode(), 'err_code' => $error->getErrorCode(), 'description' => $error->getDescription()];
            throw $error;
        }
    }
}
