<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

interface JetStreamPullConsumerInterface
{
    public function fetch(
        int $batch,
        int $expiresMs,
        bool $noWait = false,
        int|null $maxBytes = null,
        int|null $idleHeartbeatMs = null,
    ): JetStreamFetchResult;

    public function consume(PullConsumeOptions $options): JetStreamConsumeHandle;
}
