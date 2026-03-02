<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Message;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamRawPubMessage;

final readonly class JetStreamClientAcknowledger implements JetStreamMessageAcknowledgerInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    public function acknowledge(string $replyTo, string $payload): void
    {
        $this->client->publish(new JetStreamRawPubMessage($replyTo, $payload));
    }
}
