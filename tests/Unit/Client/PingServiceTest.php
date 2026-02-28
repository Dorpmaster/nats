<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\PingService;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\FakeTimeProvider;
use Dorpmaster\Nats\Tests\Support\ManualDelayStrategy;
use Dorpmaster\Nats\Tests\Support\RecordingMetricsCollector;
use PHPUnit\Framework\TestCase;

final class PingServiceTest extends TestCase
{
    use AsyncTestTools;

    public function testRttMeasuredUsingFakeTimeProvider(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $delay   = new ManualDelayStrategy();
            $time    = new FakeTimeProvider(1000);
            $metrics = new RecordingMetricsCollector();
            $sent    = 0;

            $service = new PingService(
                delayStrategy: $delay,
                pingIntervalMs: 1_000,
                pingTimeoutMs: 500,
                metricsCollector: $metrics,
                timeProvider: $time,
            );

            // Act
            $service->start(
                static function () use (&$sent): void {
                    $sent++;
                },
                static function (): void {
                },
            );
            $this->forceTick();
            $time->advanceMs(25);
            $service->onPongReceived();
            $delay->releaseNext();
            $this->forceTick();
            $service->stop();

            // Assert
            self::assertSame(1, $sent);
            self::assertCount(1, $metrics->observations());
            self::assertSame('ping_rtt_ms', $metrics->observations()[0]['name']);
            self::assertSame(25.0, $metrics->observations()[0]['value']);
        });
    }

    public function testTimeoutTriggersCallbackAndMetric(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $delay    = new ManualDelayStrategy();
            $time     = new FakeTimeProvider();
            $metrics  = new RecordingMetricsCollector();
            $timeouts = 0;

            $service = new PingService(
                delayStrategy: $delay,
                pingIntervalMs: 1_000,
                pingTimeoutMs: 200,
                metricsCollector: $metrics,
                timeProvider: $time,
            );

            // Act
            $service->start(
                static function (): void {
                },
                static function () use (&$timeouts): void {
                    $timeouts++;
                },
            );
            $this->forceTick();
            $delay->releaseNext();
            $this->forceTick();
            $service->stop();

            // Assert
            self::assertSame(1, $timeouts);
            self::assertSame(1, $metrics->countIncrements('ping_timeouts'));
        });
    }

    public function testStartStopAreIdempotent(): void
    {
        $this->setTimeout(10);
        $this->runAsyncTest(function () {
            // Arrange
            $delay   = new ManualDelayStrategy();
            $service = new PingService(
                delayStrategy: $delay,
                pingIntervalMs: 1_000,
                pingTimeoutMs: 200,
            );

            // Act
            $service->start(static function (): void {
            }, static function (): void {
            });
            $service->start(static function (): void {
            }, static function (): void {
            });
            $this->forceTick();
            $service->stop();
            $service->stop();
            $delay->releaseAll();
            $this->forceTick();

            // Assert
            self::assertFalse($service->isRunning());
        });
    }
}
