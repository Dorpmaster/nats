<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\ByteStream\WritableResourceStream;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\Assert;
use Psr\Log\AbstractLogger;
use Stringable;

final class CapturedProtocolLogs extends AbstractLogger
{
    /** @var resource */
    private $stream;
    private Logger $formattedLogger;
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    private array $records = [];

    public function __construct()
    {
        $this->stream = fopen('php://temp', 'w+');
        $handler      = new StreamHandler(new WritableResourceStream($this->stream), Level::Debug);
        $handler->pushProcessor(new PsrLogMessageProcessor());
        $handler->setFormatter(new ConsoleFormatter());
        $this->formattedLogger = new Logger('synthetic-nats-audit');
        $this->formattedLogger->pushHandler($handler);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        // Capture the untouched PSR-3 arguments before the production formatter sees them.
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
        $this->formattedLogger->log($level, $message, $context);
    }

    /** @return list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public function records(): array
    {
        return $this->records;
    }

    public function output(): string
    {
        rewind($this->stream);
        $output = stream_get_contents($this->stream);
        fseek($this->stream, 0, SEEK_END);
        return $output;
    }

    /** @param list<string> $markers */
    public function assertSafe(array $markers): void
    {
        $output = $this->output();
        foreach ($markers as $marker) {
            Assert::assertStringNotContainsString($marker, $output, 'Sensitive marker reached formatted logs');
        }
        Assert::assertStringNotContainsString('CONNECT {', $output);
        Assert::assertNotEmpty($this->records);
        foreach ($this->records as $record) {
            Assert::assertSame([], array_values(array_intersect(array_keys($record['context']), ['message', 'response', 'chunk', 'payload', 'server_info', 'connect_info', 'user', 'pass', 'auth_token', 'jwt', 'sig', 'nkey'])), 'Sensitive fields must not reach raw PSR-3 context');
            $this->assertSafeValue($record, $markers);
        }
    }

    /** @param list<string> $markers */
    private function assertSafeValue(mixed $value, array $markers): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertSafeValue($item, $markers);
            }
            return;
        }
        Assert::assertFalse(is_object($value), 'Original objects must not reach protocol log context');
        Assert::assertFalse(is_resource($value), 'Resources must not reach protocol log context');
        if (is_string($value)) {
            foreach ($markers as $marker) {
                Assert::assertStringNotContainsString($marker, $value, 'Sensitive marker reached raw PSR-3 arguments');
            }
            Assert::assertStringNotContainsString('CONNECT {', $value);
        }
    }
}
