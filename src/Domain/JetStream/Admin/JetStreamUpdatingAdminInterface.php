<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;

interface JetStreamUpdatingAdminInterface extends JetStreamAdminInterface
{
    public function updateStream(string $stream, StreamConfigPatch $patch, int|null $timeoutMs = null): StreamInfo;
}
