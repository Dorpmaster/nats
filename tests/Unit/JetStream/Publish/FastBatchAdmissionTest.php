<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Tests\Support\FakeFastBatchClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FastBatchAdmissionTest extends TestCase
{
    public static function invalidInputs(): iterable
    {
        yield ['S', [], null];
        yield ['', ['orders.a'], null];
        yield ['A.B', ['orders.a'], null];
        yield ['S', ['orders.*'], null];
        yield ['S', ['orders.a', "orders.b\n"], null];
        yield ['S', ['orders.a', 123], null];
        yield ['S', [2 => 'orders.a'], null];
        foreach ([0, -1, true, false, 1.5, '500'] as $timeout) {
            yield ['S', ['orders.a'], $timeout];
        }
    }
    #[DataProvider('invalidInputs')]
    public function testInvalidOpeningHasZeroEffects(string $stream, array $subjects, mixed $timeout): void
    {
        $client = new FakeFastBatchClient();
        try {
            (new JetStreamPublisher($client))->openFastBatch($stream, $subjects, timeoutMs: $timeout);
            self::fail('Invalid opening');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $client->checks);
            self::assertSame([], $client->channels);
        }
    }
    public static function unavailable(): iterable
    {
        yield ['old'];
        yield ['unknown'];
        yield ['stale'];
        yield ['disabled'];
        yield ['route'];
        yield ['diagnostics'];
    }
    #[DataProvider('unavailable')]
    public function testCapabilityConfigurationAndRouteRefusalsHaveNoData(string $kind): void
    {
        $client = new FakeFastBatchClient();
        if ($kind === 'old') {
            $client->level = 3;
        }
        if ($kind === 'unknown') {
            $client->level = null;
        }
        if ($kind === 'stale') {
            $client->current = false;
        }
        if ($kind === 'disabled') {
            $client->enabled = false;
        }
        if ($kind === 'route') {
            $client->read = static fn ($m) => str_ends_with($m->getSubject(), '.NAMES') ? '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":0,"streams":[]}' : null;
        }
        if ($kind === 'diagnostics') {
            $client->read = static fn ($m) => str_starts_with($m->getSubject(), '$SYS.') ? '{"error":{"code":503,"description":"unavailable"}}' : null;
        }
        try {
            (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a']);
            self::fail('Must refuse before data');
        } catch (JetStreamApiException) {
            self::assertSame([], $client->channels);
        }
    }
    public function testLegacyClientCannotUseReplayableFallback(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('publish');
        $client->expects(self::never())->method('request');
        $this->expectException(JetStreamApiException::class);
        (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a']);
    }
    public static function badMessages(): iterable
    {
        yield ['not.declared', 'x', []];
        yield ['orders.a', 'x', ['Nats-Batch-Id' => 'fake']];
        yield ['orders.a', 'x', ['nats-required-api-level' => '0']];
        yield ['orders.a', 'x', ['Nats-Expected-Stream' => 'other']];
        yield ['orders.a', 'x', ["X\n" => 'bad']];
        yield ['orders.a', str_repeat('x', 65536), []];
    }
    #[DataProvider('badMessages')]
    public function testInvalidMessageDoesNotConsumeSequence(string $subject, string $payload, array $headers): void
    {
        $client = new FakeFastBatchClient();
        $s      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a']);
        try {
            $s->publish($subject, $payload, $headers);
            self::fail('Invalid data');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $client->channels[0]->records);
            self::assertSame('0', $s->getProgress()->getLastSentSequence());
        }
        self::assertTrue($s->commit('orders.a', 'valid')->isComplete());
    }
    public function testEmptySessionCannotSendEobAndCanStillCommitFirstMessage(): void
    {
        $client = new FakeFastBatchClient();
        $s      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a']);
        try {
            $s->finish();
            self::fail('Empty EOB invalid');
        } catch (\LogicException) {
            self::assertSame([], $client->channels[0]->records);
        }
        self::assertTrue($s->commit('orders.a', '')->isComplete());
    }
    public static function invalidPositions(): iterable
    {
        foreach ([true, false, 1.5, '1.5', '+1', '1e3', '1 ', '18446744073709551616', '1'] as $sequence) {
            yield [$sequence];
        }
    }
    #[DataProvider('invalidPositions')]
    public function testAwaitCannotConfirmInvalidOrUnsentPosition(mixed $sequence): void
    {
        $client = new FakeFastBatchClient();
        $s      = (new JetStreamPublisher($client))->openFastBatch('S', ['orders.a']);
        try {
            $s->awaitAcknowledged($sequence);
            self::fail('Invalid target');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $client->channels[0]->records);
        }
        $s->cancel();
    }
}
