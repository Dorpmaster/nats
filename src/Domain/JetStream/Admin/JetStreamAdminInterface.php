<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;

interface JetStreamAdminInterface
{
    public function createStream(StreamConfig $config): StreamInfo;

    public function getStreamInfo(string $stream): StreamInfo;

    public function deleteStream(string $stream): void;

    public function createOrUpdateConsumer(string $stream, ConsumerConfig $config): ConsumerInfo;

    public function getConsumerInfo(string $stream, string $consumer): ConsumerInfo;

    public function deleteConsumer(string $stream, string $consumer): void;
}
