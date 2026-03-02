<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamClientAcknowledger;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageAcknowledgerInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class JetStreamPullConsumerFactory
{
    private JetStreamMessageAcknowledgerInterface $acknowledger;
    private JetStreamControlPlaneTransportInterface $transport;
    private LoggerInterface $logger;

    public function __construct(
        JetStreamControlPlaneTransportInterface $transport,
        JetStreamMessageAcknowledgerInterface|null $acknowledger = null,
        LoggerInterface|null $logger = null,
    ) {
        $this->transport    = $transport;
        $transportClient    = $this->transport->getClient();
        $this->acknowledger = $acknowledger ?? new JetStreamClientAcknowledger($transportClient);
        $this->logger       = $logger ?? new NullLogger();
    }

    public function create(string $stream, string $consumer): JetStreamPullConsumerInterface
    {
        return new JetStreamPullConsumer(
            transport: $this->transport,
            stream: $stream,
            consumer: $consumer,
            acknowledger: $this->acknowledger,
            logger: $this->logger,
        );
    }
}
