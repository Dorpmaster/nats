<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Transport;

interface JetStreamControlPlaneTransportInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function request(string $apiSubjectSuffix, array $payload, int|null $timeoutMs = null): array;
}
