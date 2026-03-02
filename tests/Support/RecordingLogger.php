<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use PHPUnit\Framework\Assert;

final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function all(): array
    {
        return $this->records;
    }

    /** @param \Closure(array<string, mixed>): bool|null $contextPredicate */
    public function assertHas(string $level, string $message, \Closure|null $contextPredicate = null): void
    {
        foreach ($this->records as $record) {
            if ($record['level'] !== $level || $record['message'] !== $message) {
                continue;
            }

            if ($contextPredicate === null || $contextPredicate($record['context']) === true) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail(sprintf('Expected log record not found: [%s] %s', $level, $message));
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function debug(\Stringable|string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
