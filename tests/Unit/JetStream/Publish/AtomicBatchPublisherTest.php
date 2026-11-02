<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchCommitMode;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamAtomicPublisherInterface;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisherInterface;
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtomicBatchPublisherTest extends TestCase
{
    private function client(int|null $level = 5, bool $current = true, int|null $connection = 1): ClientInterface&ConnectionBoundRequestClientInterface&ServerInformationProviderInterface
    {
        $client = $this->createMockForIntersectionOfInterfaces([ClientInterface::class, ConnectionBoundRequestClientInterface::class, ServerInformationProviderInterface::class]);
        $client->method('getServerInformation')->willReturn(new ServerInformation('server', '2.15.2', true, $level, $connection, static fn (): bool => $current));
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('requestOnce');
        $client->expects(self::never())->method('publish');
        return $client;
    }

    private function publisher(ClientInterface $client, \Psr\Log\LoggerInterface|null $logger = null): JetStreamPublisher
    {
        $target = $client instanceof ConnectionBoundRequestClientInterface && $client instanceof ServerInformationProviderInterface
            ? new \Dorpmaster\Nats\Tests\Support\AtomicBatchTargetStubClient($client) : $client;
        return new JetStreamPublisher($target, $logger);
    }

    public static function modes(): iterable
    {
        foreach (AtomicBatchCommitMode::cases() as $mode) {
            foreach ([1, 3] as $count) {
                yield [$mode, $count];
            }
        }
    }

    #[DataProvider('modes')]
    public function testProtocolSnapshotsAndExactAck(AtomicBatchCommitMode $mode, int $count): void
    {
        $client   = $this->client();
        $bag      = new HeaderBag(['X' => ['NATS/1.0', 'https://example.test/a:b']]);
        $messages = array_fill(0, $count, ['subject' => 'orders@EU.created', 'payload' => "A\r\n\0B", 'headers' => $bag]);
        $calls    = 0;
        $id       = null;
        $inboxes  = [];
        $frames   = $count + ($mode === AtomicBatchCommitMode::EOB ? 1 : 0);
        $client->expects(self::exactly($frames))->method('requestOnceOnConnection')->willReturnCallback(function ($frame, int $connection, float $timeout) use (&$calls, &$id, &$inboxes, $frames, $count, $mode, $bag): MsgMessage {
            $calls++;
            self::assertSame(1, $connection);
            self::assertGreaterThan(0, $timeout);
            self::assertLessThanOrEqual(0.5, $timeout);
            $headers = $frame->getHeaders();
            $id    ??= $headers->get('Nats-Batch-Id');
            self::assertSame($id, $headers->get('Nats-Batch-Id'));
            self::assertLessThanOrEqual(64, strlen($id));
            self::assertSame((string) $calls, $headers->get('Nats-Batch-Sequence'));
            self::assertSame('ORDERS', $headers->get('Nats-Expected-Stream'));
            self::assertSame($mode === AtomicBatchCommitMode::EOB ? '4' : '2', $headers->get('Nats-Required-Api-Level'));
            self::assertNotContains($frame->getReplyTo(), $inboxes);
            $inboxes[]        = $frame->getReplyTo();
            $wire             = (string) $frame;
            [$command, $body] = explode("\r\n", $wire, 2);
            $tokens           = explode(' ', $command);
            $headerSize       = (int) $tokens[3];
            $total            = (int) $tokens[4];
            self::assertSame($total + 2, strlen($body));
            self::assertSame("\r\n\r\n", substr($body, $headerSize - 4, 4));
            self::assertSame($frame->getPayload(), substr($body, $headerSize, $total - $headerSize));
            self::assertSame($headerSize, $frame->getHeadersSize());
            self::assertSame($total, $frame->getTotalSize());
            if ($calls <= $count) {
                self::assertSame(['NATS/1.0', 'https://example.test/a:b'], $headers->get('X'));
                self::assertSame("A\r\n\0B", $frame->getPayload());
            } else {
                self::assertSame('', $frame->getPayload());
            }
            $bag->set('X', 'changed-after-snapshot');
            $headers->set('X', 'changed-through-frame-getter');
            self::assertSame($wire, (string) $frame);
            if ($calls < $frames) {
                self::assertNull($frame->getHeaders()->get('Nats-Batch-Commit'));
                return new MsgMessage('ack', '1', '');
            }
            self::assertSame($mode === AtomicBatchCommitMode::EOB ? 'eob' : '1', $frame->getHeaders()->get('Nats-Batch-Commit'));
            return new MsgMessage('ack', '1', '{"stream":"ORDERS","batch":"' . $id . '","count":' . $count . ',"seq":18446744073709551615}');
        });
        $publisher = $this->publisher($client);
        $ack       = $publisher->publishAtomicBatch('ORDERS', $messages, new AtomicBatchOptions($mode), 500);
        self::assertSame('18446744073709551615', $ack->getSeq());
        self::assertSame($id, $ack->getBatchId());
        self::assertSame($count, $ack->getCount());
        self::assertSame('ORDERS', $ack->getStream());
        self::assertInstanceOf(JetStreamAtomicPublisherInterface::class, $publisher);
        self::assertFalse((new \ReflectionClass(JetStreamPublisherInterface::class))->hasMethod('publishAtomicBatch'));
    }

    public static function badMessages(): iterable
    {
        yield [[]];
        yield [[2 => ['subject' => 'orders', 'payload' => 'x']]];
        foreach (['', 'a b', "a\t", "a\r", "a\n", "a\r\n", "a\0", 'a.*', 'a.>', 'a..b', '.a', 'a.'] as $subject) {
            yield [[['subject' => $subject, 'payload' => 'x']]];
        }
        foreach ([null, true, 1.5, 3, [], new \stdClass()] as $value) {
            yield [[['subject' => 'orders', 'payload' => 'valid'], ['subject' => 'orders', 'payload' => $value]]];
            yield [[['subject' => $value, 'payload' => 'valid']]];
        }
        foreach (['Nats-Batch-Id', 'nats-batch-sequence', 'NATS-BATCH-COMMIT', 'Nats-Batch-Future', 'Nats-Expected-Stream', 'nats-required-api-level'] as $name) {
            yield [[['subject' => 'orders', 'payload' => 'valid'], ['subject' => 'orders', 'payload' => '', 'headers' => [$name => 'bad']]]];
        }
        yield [[['subject' => 'orders', 'payload' => '', 'headers' => false]]];
        foreach (["X\n", "X\r", 'X Y', 'X:'] as $name) {
            yield [[['subject' => 'orders', 'payload' => 'valid'], ['subject' => 'orders', 'payload' => '', 'headers' => [$name => 'a']]]];
        }
    }

    #[DataProvider('badMessages')]
    public function testValidatesWholeBatchBeforeClientEffects(array $messages): void
    {
        $client = $this->createMockForIntersectionOfInterfaces([ClientInterface::class, ConnectionBoundRequestClientInterface::class, ServerInformationProviderInterface::class]);
        $client->expects(self::never())->method('getServerInformation');
        $client->expects(self::never())->method('requestOnceOnConnection');
        $client->expects(self::never())->method('subscribe');
        $this->expectException(\InvalidArgumentException::class);
        ($this->publisher($client))->publishAtomicBatch('ORDERS', $messages);
    }

    public static function unsafeCapabilities(): iterable
    {
        yield [1, true, 1, AtomicBatchCommitMode::MESSAGE];
        yield [3, true, 1, AtomicBatchCommitMode::EOB];
        yield [null, true, 1, AtomicBatchCommitMode::MESSAGE];
        yield [5, false, 1, AtomicBatchCommitMode::MESSAGE];
        yield [5, true, null, AtomicBatchCommitMode::MESSAGE];
    }

    #[DataProvider('unsafeCapabilities')]
    public function testUnsupportedOrUnknownModeHasZeroFrames(int|null $level, bool $current, int|null $connection, AtomicBatchCommitMode $mode): void
    {
        $client = $this->client($level, $current, $connection);
        $client->expects(self::never())->method('requestOnceOnConnection');
        $this->expectException(JetStreamApiException::class);
        ($this->publisher($client))->publishAtomicBatch('ORDERS', [['subject' => 'orders', 'payload' => '']], new AtomicBatchOptions($mode));
    }

    public static function badFinalAcks(): iterable
    {
        foreach (['', '[]', '{', '{"stream":"OTHER","batch":"ID","count":1,"seq":1}', '{"stream":"ORDERS","batch":"OTHER","count":1,"seq":1}', '{"stream":"ORDERS","batch":"ID","count":2,"seq":1}'] as $json) {
            yield [$json];
        }
        foreach (['0', '-1', '"2"', '1.5', '1e2', '18446744073709551616', 'null'] as $seq) {
            yield ['{"stream":"ORDERS","batch":"ID","count":1,"seq":' . $seq . '}'];
        }
        yield ['{"stream":"ORDERS","batch":"ID","count":"1","seq":1}'];
        yield ['{"stream":"ORDERS","batch":42,"count":1,"seq":1}'];
        foreach (['null', 'false', '[]', '{}', '{"code":400}', '{"code":400,"description":42}', '{"code":"400","description":"bad"}', '{"code":0,"description":"bad"}', '{"code":200,"description":"bad"}', '{"code":400,"description":"bad","err_code":"10071"}', '{"code":400,"description":"bad","err_code":9223372036854775808}'] as $error) {
            yield ['{"error":' . $error . '}'];
        }
    }

    #[DataProvider('badFinalAcks')]
    public function testMalformedCommitAckIsUncertain(string $json): void
    {
        $client = $this->client();
        $client->expects(self::once())->method('requestOnceOnConnection')->willReturnCallback(static fn ($frame): MsgMessage => new MsgMessage('ack', '1', str_replace('ID', $frame->getHeaders()->get('Nats-Batch-Id'), $json)));
        try {
            ($this->publisher($client))->publishAtomicBatch('ORDERS', [['subject' => 'orders', 'payload' => '']]);
            self::fail('Bad final acknowledgement must not confirm commit');
        } catch (AtomicBatchException $error) {
            self::assertTrue($error->isOutcomeUnknown());
            self::assertStringContainsString('commit outcome is unknown', $error->getMessage());
            self::assertStringContainsString($error->getBatchId(), $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
    }

    public static function failures(): iterable
    {
        yield 'commit attempted' => [true, true];
        yield 'commit never attempted' => [true, false];
        yield 'staging attempted' => [false, true];
    }

    #[DataProvider('failures')]
    public function testWriteOutcomeAndCauseArePreserved(bool $commit, bool $attempted): void
    {
        $client = $this->client();
        $cause  = new ConnectionBoundRequestException($attempted, new \RuntimeException('synthetic-secret'));
        $client->expects(self::once())->method('requestOnceOnConnection')->willThrowException($cause);
        $logs = new RecordingLogger();
        try {
            ($this->publisher($client, $logs))->publishAtomicBatch('ORDERS', array_fill(0, $commit ? 1 : 2, ['subject' => 'orders', 'payload' => 'synthetic-body']));
            self::fail('Expected error');
        } catch (AtomicBatchException $error) {
            self::assertSame($commit && $attempted, $error->isOutcomeUnknown());
            self::assertSame($cause, $error->getPrevious());
            self::assertStringNotContainsString('synthetic-secret', serialize($logs));
            self::assertStringNotContainsString('synthetic-body', serialize($logs));
        }
    }

    public function testServerRejectionIsDefinitiveAndKeepsCodes(): void
    {
        $client = $this->client();
        $client->expects(self::once())->method('requestOnceOnConnection')->willReturn(new MsgMessage('ack', '1', '{"error":{"code":400,"err_code":10071,"description":"wrong last sequence: synthetic"}}'));
        try {
            ($this->publisher($client))->publishAtomicBatch('ORDERS', [['subject' => 'orders', 'payload' => '']]);
            self::fail('Expected rejection');
        } catch (AtomicBatchException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertSame(400, $error->getApiCode());
            self::assertSame(10071, $error->getErrorCode());
            self::assertSame('wrong last sequence: synthetic', $error->getDescription());
        }
    }

    public static function cancellationReasons(): iterable
    {
        yield 'operation cancellation' => [null, 0];
        yield 'connection lost' => [new \RuntimeException('synthetic-transport-secret'), 0];
        yield 'deadline' => [new \Amp\TimeoutException(), 408];
    }

    #[DataProvider('cancellationReasons')]
    public function testLocalFailureCodesDistinguishTimeoutAndConnectionLoss(\Throwable|null $reason, int $code): void
    {
        $client = $this->client();
        $cause  = new ConnectionBoundRequestException(true, new CancelledException($reason));
        $client->expects(self::once())->method('requestOnceOnConnection')->willThrowException($cause);
        try {
            ($this->publisher($client))->publishAtomicBatch('ORDERS', [['subject' => 'orders', 'payload' => 'x']]);
            self::fail('Expected failure');
        } catch (AtomicBatchException $error) {
            self::assertSame($code, $error->getApiCode());
            self::assertSame($cause, $error->getPrevious());
            self::assertTrue($error->isOutcomeUnknown());
            self::assertStringContainsString('commit outcome is unknown', $error->getMessage());
        }
    }

    public function testOneDeadlineCoversAllFrames(): void
    {
        $client   = $this->client();
        $timeouts = [];
        $client->expects(self::exactly(2))->method('requestOnceOnConnection')->willReturnCallback(static function ($frame, $connection, float $timeout) use (&$timeouts): MsgMessage {
            $timeouts[] = $timeout;
            if (count($timeouts) === 1) {
                \Amp\delay(0.03);
                return new MsgMessage('ack', '1', '');
            }
            throw new ConnectionBoundRequestException(false, new CancelledException());
        });
        try {
            ($this->publisher($client))->publishAtomicBatch('ORDERS', array_fill(0, 2, ['subject' => 'orders', 'payload' => 'x']), timeoutMs: 200);
            self::fail('Expected timeout');
        } catch (AtomicBatchException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertCount(2, $timeouts);
            self::assertLessThanOrEqual(0.2, $timeouts[0]);
            self::assertLessThan($timeouts[0] - 0.02, $timeouts[1]);
        }
    }

    public function testLegacyClientCannotSilentlySubstituteReplayableRequests(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('publish');
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage('ConnectionBoundRequestClientInterface');
        ($this->publisher($client))->publishAtomicBatch('ORDERS', [['subject' => 'orders', 'payload' => 'x']]);
    }

    public static function invalidStreams(): iterable
    {
        foreach (['', 'A.B', 'A*', 'A>', 'A/', 'A\\', 'A B', "A\t", "A\r\n", "A\0"] as $stream) {
            yield [$stream];
        }
    }

    #[DataProvider('invalidStreams')]
    public function testInvalidStreamHasZeroEffects(string $stream): void
    {
        $client = $this->client();
        $client->expects(self::never())->method('requestOnceOnConnection');
        $this->expectException(\InvalidArgumentException::class);
        ($this->publisher($client))->publishAtomicBatch($stream, [['subject' => 'orders', 'payload' => 'x']]);
    }

    public static function timeouts(): iterable
    {
        foreach ([0, -1, 1.5, true, false, '7'] as $timeout) {
            yield [$timeout];
        }
    }

    #[DataProvider('timeouts')]
    public function testRejectsInvalidTimeoutBeforeAnyRequest(mixed $timeout): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        $this->expectException(\InvalidArgumentException::class);
        ($this->publisher($client))->publishAtomicBatch('ORDERS', [['subject' => 'orders', 'payload' => '']], timeoutMs: $timeout);
    }

    public function testCancelledOperationCannotPublish(): void
    {
        $source = new DeferredCancellation();
        $source->cancel();
        $client = $this->client();
        $client->expects(self::never())->method('requestOnceOnConnection');
        try {
            ($this->publisher($client))->publishAtomicBatch('ORDERS', [['subject' => 'orders', 'payload' => '']], new AtomicBatchOptions(cancellation: $source->getCancellation()));
            self::fail('Expected cancellation');
        } catch (AtomicBatchException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertInstanceOf(CancelledException::class, $error->getPrevious());
        }
    }
}
