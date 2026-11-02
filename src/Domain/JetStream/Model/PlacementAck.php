<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

/** Command acceptance, not proof that replication has converged. */
final readonly class PlacementAck
{
    /** @param array<string, string> $targets */
    public function __construct(public string $operation, public array $targets, public JsonObject $response)
    {
    }
}
