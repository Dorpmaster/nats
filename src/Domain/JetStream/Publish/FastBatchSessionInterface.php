<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Publish;

use Dorpmaster\Nats\Protocol\Contracts\HeaderBugInterface;
use Dorpmaster\Nats\Domain\JetStream\Model\FastBatchProgress;
use Dorpmaster\Nats\Domain\JetStream\Model\FastBatchResult;

interface FastBatchSessionInterface
{
    public function publish(string $subject, string $payload, HeaderBugInterface|array $headers = []): FastBatchProgress;
    public function commit(string $subject, string $payload, HeaderBugInterface|array $headers = []): FastBatchResult;
    public function finish(): FastBatchResult;
    public function cancel(): FastBatchResult;
    public function getProgress(): FastBatchProgress;
    public function getResult(): FastBatchResult|null;
    public function awaitAcknowledged(int|string|null $sequence = null): FastBatchProgress;
}
