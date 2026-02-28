<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\SubscriptionStorage;
use PHPUnit\Framework\TestCase;

final class SubscriptionStorageTest extends TestCase
{
    public function testAdd(): void
    {
        $storage = new SubscriptionStorage();

        $closureOne = static fn(): int => 1;
        $closureTwo = static fn(): int => 2;

        $storage->add('test', $closureOne);
        self::assertSame($closureOne, $storage->get('test'));

        $storage->add('test', $closureTwo);
        self::assertSame($closureTwo, $storage->get('test'));
    }

    public function testGet(): void
    {
        $storage = new SubscriptionStorage();

        $closure = static fn(): int => 1;

        $storage->add('test', $closure);
        self::assertSame($closure, $storage->get('test'));
    }

    public function testRemove(): void
    {
        $storage = new SubscriptionStorage();

        $closure = static fn(): int => 1;

        $storage->add('test', $closure);
        self::assertSame($closure, $storage->get('test'));

        $storage->remove('test');
        self::assertNull($storage->get('test'));
    }
}
