<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Capability;

interface JetStreamCapabilitiesProviderInterface
{
    public function getCapabilities(): JetStreamCapabilities;
}
