<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\TracingDriver;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\getStderr;

trait AsyncTestTools
{
    private DeferredFuture $deferredFuture;

    private string $timeoutId;

    protected LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deferredFuture = new DeferredFuture();

        EventLoop::setErrorHandler(function (Throwable $exception): void {
            if ($this->deferredFuture->isComplete()) {
                return;
            }

            $this->deferredFuture->error($exception);
        });

        $this->logger = self::createStub(LoggerInterface::class);
        if ($_SERVER['TEST_DEBUG_LOGGER'] ?? false) {
            $logHandler = new StreamHandler(getStderr());
            $logHandler->pushProcessor(new PsrLogMessageProcessor());
            $logHandler->setFormatter(new ConsoleFormatter());

            $this->logger = new Logger($this->name());
            $this->logger->pushHandler($logHandler);
        }
    }

    protected function runAsyncTest(\Closure $test): void
    {
        try {
            Future\await([
                $this->deferredFuture->getFuture(),
                async(function () use ($test): void {
                    try {
                        $result = $test();
                        if ($result instanceof Future) {
                            $result->await();
                        }

                        $this->forceTick();
                    } finally {
                        if (!$this->deferredFuture->isComplete()) {
                            $this->deferredFuture->complete();
                        }
                    }
                }),
            ]);
        } finally {
            if (isset($this->timeoutId)) {
                EventLoop::cancel($this->timeoutId);
            }

            \gc_collect_cycles();
        }
    }

    protected function setTimeout(float $seconds): void
    {
        if (isset($this->timeoutId)) {
            EventLoop::cancel($this->timeoutId);
        }

        $this->timeoutId = EventLoop::delay($seconds, function () use ($seconds): void {
            EventLoop::setErrorHandler(null);

            $additionalInfo = '';
            $driver = EventLoop::getDriver();
            if ($driver instanceof TracingDriver) {
                $additionalInfo .= "\r\n\r\n" . $driver->dump();
            } else {
                $additionalInfo .= "\r\n\r\nSet REVOLT_DEBUG_TRACE_WATCHERS=true as environment variable to trace watchers keeping the loop running.";
            }

            if ($this->deferredFuture->isComplete()) {
                return;
            }

            try {
                self::fail(sprintf(
                    'Expected test to complete before %0.3fs time limit%s',
                    $seconds,
                    $additionalInfo,
                ));
            } catch (AssertionFailedError $e) {
                $this->deferredFuture->error($e);
            }
        });

        EventLoop::unreference($this->timeoutId);
    }

    protected function forceTick(): void
    {
        $deferred = new DeferredFuture();
        EventLoop::defer(static fn () => $deferred->complete());
        $deferred->getFuture()->await();
    }
}
