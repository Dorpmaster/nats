<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Capability;

final readonly class FeatureSupport
{
    public function __construct(public Support $support, public string $reason)
    {
    }
}
