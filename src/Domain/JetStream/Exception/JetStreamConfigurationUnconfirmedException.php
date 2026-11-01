<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Exception;

use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;

/** The server responded, but the requested configuration was not confirmed. No rollback is attempted. */
final class JetStreamConfigurationUnconfirmedException extends JetStreamApiException
{
    /** @param list<string> $fields */
    public function __construct(private readonly StreamInfo $streamInfo, private readonly array $fields)
    {
        parent::__construct(0, 'JetStream server did not confirm requested stream configuration fields: ' . implode(', ', $fields));
    }

    public function getStreamInfo(): StreamInfo
    {
        return $this->streamInfo;
    }

    /** @return list<string> */
    public function getFields(): array
    {
        return $this->fields;
    }
}
