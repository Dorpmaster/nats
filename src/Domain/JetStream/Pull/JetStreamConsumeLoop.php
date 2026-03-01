<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Pull;

use Dorpmaster\Nats\Client\EventLoopDelayStrategy;
use Dorpmaster\Nats\Domain\Client\ClientNotConnectedException;
use Dorpmaster\Nats\Domain\Client\DelayStrategyInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Revolt\EventLoop;

final class JetStreamConsumeLoop
{
    private const int NO_WAIT_POLL_DELAY_MS = 50;

    public function __construct(
        private readonly DelayStrategyInterface|null $delayStrategy = null,
    ) {
    }

    /** @param \Closure(): JetStreamFetchResult $fetch */
    public function start(JetStreamConsumeHandle $handle, \Closure $fetch): void
    {
        EventLoop::queue(function () use ($handle, $fetch): void {
            try {
                while ($handle->isRunning() || $handle->isDraining()) {
                    if ($handle->isDraining()) {
                        if ($handle->getQueuedMessages() === 0 && $handle->getInFlightMessages() === 0) {
                            $handle->stop();
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
            }
        });
    }

    private function getDelayStrategy(): DelayStrategyInterface
    {
        return $this->delayStrategy ?? new EventLoopDelayStrategy();
    }
}
