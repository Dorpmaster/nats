<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Support;

use Amp\ByteStream\WritableStream;
use Closure;

use function Amp\delay;

final class SlowArchiveSink implements WritableStream
{
    public int $writes = 0;
    public function __construct(private WritableStream $inner, private float $delay)
    {
    }
    public function write(string $bytes): void
    {
        delay($this->delay);
        $this->inner->write($bytes);
        $this->writes++;
    }
    public function end(): void
    {
        $this->inner->end();
    }
    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }
    public function close(): void
    {
        $this->inner->close();
    }
    public function isClosed(): bool
    {
        return $this->inner->isClosed();
    }
    public function onClose(Closure $onClose): void
    {
        $this->inner->onClose($onClose);
    }
}
