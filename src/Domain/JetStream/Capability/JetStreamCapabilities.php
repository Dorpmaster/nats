<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Capability;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;

final readonly class JetStreamCapabilities
{
    public function __construct(public ServerInformation $server, public string $apiPrefix = '$JS.API')
    {
    }

    public static function forClient(ClientInterface $client, string $apiPrefix = '$JS.API'): self
    {
        return new self(
            $client instanceof ServerInformationProviderInterface ? $client->getServerInformation() : new ServerInformation(),
            $apiPrefix,
        );
    }

    public function check(Feature $feature): FeatureSupport
    {
        if ($this->apiPrefix !== '$JS.API') {
            return new FeatureSupport(Support::UNKNOWN, 'remote_api_target_unknown');
        }
        if (!$this->server->isCurrentConnection()) {
            return new FeatureSupport(Support::UNKNOWN, 'no_current_server_information');
        }
        if ($this->server->apiLevel === null) {
            return new FeatureSupport(Support::UNKNOWN, 'api_level_not_announced');
        }
        return new FeatureSupport(
            $this->server->apiLevel >= $feature->minimumApiLevel() ? Support::SUPPORTED : Support::UNSUPPORTED,
            sprintf('announced_api_level_%d_requires_%d', $this->server->apiLevel, $feature->minimumApiLevel()),
        );
    }
}
