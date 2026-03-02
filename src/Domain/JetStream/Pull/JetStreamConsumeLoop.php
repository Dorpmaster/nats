<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Dorpmaster\Nats\Client\EventLoopDelayStrategy;
use Dorpmaster\Nats\Domain\Client\ClientNotConnectedException;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

final class JetStreamConsumeLoop
{
    private const int NO_WAIT_POLL_DELAY_MS = 50;

    public function __construct(
        private readonly DelayStrategyInterface|null $delayStrategy = null,
        private readonly LoggerInterface|null $logger = null,
        private readonly string|null $stream = null,
        private readonly string|null $consumer = null,
    ) {
    }

    /** @param \Closure(): JetStreamFetchResult $fetch */
    public function start(JetStreamConsumeHandle $handle, \Closure $fetch): void
    {
        $this->getLogger()->info('js.consume.start', [
            'stream' => $this->stream,
            'consumer' => $this->consumer,
            'state' => $handle->getState()->value,
        ]);
        EventLoop::queue(function () use ($handle, $fetch): void {
            try {
                while ($handle->isRunning() || $handle->isDraining()) {
                    if ($handle->isDraining()) {
                        if ($handle->getQueuedMessages() === 0 && $handle->getInFlightMessages() === 0) {
                            $handle->stop();
                            $this->getLogger()->info('js.consume.drain.success', [
                                'stream' => $this->stream,
                                'consumer' => $this->consumer,
                                'state' => $handle->getState()->value,
                            ]);
                            break;
                        }

                        $this->getDelayStrategy()->delay(self::NO_WAIT_POLL_DELAY_MS);
                        continue;
                    }

                    try {
                        $result = $fetch();
                    } catch (JetStreamTimeoutException | ConnectionException | ClientNotConnectedException | JetStreamApiException $exception) {
                        if (!$handle->isRunning()) {
                            break;
                        }

                        $this->getLogger()->warning('js.consume.fetch.retry', [
                            'error' => $exception::class,
                            'state' => $handle->getState()->value,
                        ]);
                        $this->getDelayStrategy()->delay(self::NO_WAIT_POLL_DELAY_MS);
                        continue;
                    }

                    foreach ($result->messages() as $message) {
                        $handle->offer($message);
                    }

                    if ($result->getReceivedCount() === 0) {
                        $this->getDelayStrategy()->delay(self::NO_WAIT_POLL_DELAY_MS);
                    }
                }
            } catch (JetStreamApiException $exception) {
                $handle->fail($exception);
            } catch (\Throwable $exception) {
                $handle->fail($exception);
            } finally {
                $handle->completeProducer();
                $this->getLogger()->info('js.consume.stop', [
                    'stream' => $this->stream,
                    'consumer' => $this->consumer,
                    'state' => $handle->getState()->value,
                ]);
            }
        });
    }

    private function getDelayStrategy(): DelayStrategyInterface
    {
        return $this->delayStrategy ?? new EventLoopDelayStrategy();
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
