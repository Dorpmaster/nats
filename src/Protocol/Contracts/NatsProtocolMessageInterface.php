<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Protocol\Contracts;

use Dorpmaster\Nats\Protocol\NatsMessageType;
use Stringable;

interface NatsProtocolMessageInterface extends Stringable
{
    public const string DELIMITER = "\r\n";

    public function getType(): NatsMessageType;

    public function getPayload(): string;
}
