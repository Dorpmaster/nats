<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamClientAcknowledger;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;

final readonly class JetStreamPullConsumerFactory
{
    private JetStreamMessageAcknowledgerInterface $acknowledger;
    private JetStreamControlPlaneTransportInterface $transport;

    public function __construct(
        JetStreamControlPlaneTransportInterface $transport,
        JetStreamMessageAcknowledgerInterface|null $acknowledger = null,
    ) {
        $this->transport    = $transport;
        $transportClient    = $this->transport->getClient();
        $this->acknowledger = $acknowledger ?? new JetStreamClientAcknowledger($transportClient);
    }

    public function create(string $stream, string $consumer): JetStreamPullConsumerInterface
    {
        return new JetStreamPullConsumer(
            transport: $this->transport,
            stream: $stream,
            consumer: $consumer,
            acknowledger: $this->acknowledger,
        );
    }
}
