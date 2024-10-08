<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests;

use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\AssertionFailedError;
use Amp\DeferredFuture;
use Amp\Future;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\TracingDriver;
use Throwable;

use function Amp\async;
use function Amp\ByteStream\getStderr;

/**
 * Provides the ability to test asynchronous code.
 */
abstract class AsyncTestCase extends TestCase
{
    private DeferredFuture $deferredFuture;

    private string $timeoutId;

    private bool $setUpInvoked = false;

    protected LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->setUpInvoked   = true;
        $this->deferredFuture = new DeferredFuture();

        EventLoop::setErrorHandler(function (Throwable $exception): void {
            if ($this->deferredFuture->isComplete()) {
                return;
            }

            $this->deferredFuture->error(new UnhandledException($exception));
        });

        $this->logger = self::createMock(LoggerInterface::class);
        if ($_SERVER['TEST_DEBUG_LOGGER'] ?? false) {
            $logHandler = new StreamHandler(getStderr());
            $logHandler->pushProcessor(new PsrLogMessageProcessor());
            $logHandler->setFormatter(new ConsoleFormatter());

            $this->logger = new Logger($this->name());
            $this->logger->pushHandler($logHandler);
        }
    }

    final protected function runAsyncTest(\Closure $test): void
    {
        if (!$this->setUpInvoked) {
            self::fail(
                sprintf(
                    '%s::setUp() overrides %s::setUp() without calling the parent method',
                    // replace NUL-byte in anonymous class name
                    str_replace("\0", '@', static::class),
                    self::class
                )
            );
        }

        try {
            Future\await([
                $this->deferredFuture->getFuture(),
                async(function () use ($test): void {
                    try {
                        $result = $test();
                        if ($result instanceof Future) {
                            $result->await();
                        }

                        // Force an extra tick of the event loop to ensure any uncaught exceptions are
                        // forwarded to the event loop handler before the test ends.
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

            \gc_collect_cycles(); // Throw from as many destructors as possible.
        }
    }

    /**
     * Fails the test (and stops the event loop) after the given timeout.
     *
     * @param float $seconds Timeout in seconds.
     */
    final protected function setTimeout(float $seconds): void
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
                $this->fail(sprintf(
                    'Expected test to complete before %0.3fs time limit%s',
                    $seconds,
                    $additionalInfo
                ));
            } catch (AssertionFailedError $e) {
                $this->deferredFuture->error($e);
            }
        });

        EventLoop::unreference($this->timeoutId);
    }

    /**
     * Forces an extra tick of the event loop to ensure any callbacks are
     * processed to the event loop handler before start assertions.
     */
    final protected function forceTick(): void
    {
        $deferred = new DeferredFuture();
        EventLoop::defer(static fn () => $deferred->complete());
        $deferred->getFuture()->await();
    }
}
