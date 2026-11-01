<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Telemetry;

use Dorpmaster\Nats\Protocol\Contracts\NatsProtocolMessageInterface;
use Throwable;

final class ProtocolLogContext
{
    /** @return array{command: string} */
    public static function message(NatsProtocolMessageInterface $message): array
    {
        return ['command' => $message->getType()->value];
    }

    /** @return array{exception_type: string|null, exception_code: int|null} */
    public static function exception(Throwable|null $exception): array
    {
        return [
            'exception_type' => $exception === null ? null : $exception::class,
            'exception_code' => $exception === null ? null : (int) $exception->getCode(),
        ];
    }
}
