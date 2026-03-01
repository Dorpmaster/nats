<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\SubscriptionIdHelperInterface;

interface JetStreamControlPlaneTransportInterface
{
    public function getClient(): ClientInterface;

    public function getSubscriptionIdHelper(): SubscriptionIdHelperInterface;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function request(string $apiSubjectSuffix, array $payload, int|null $timeoutMs = null): array;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function publishRequest(
        string $apiSubjectSuffix,
        array $payload,
        string $replyTo,
        array $headers = [],
    ): void;
}
