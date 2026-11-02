<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Backup;

use Amp\ByteStream\{ReadableBuffer,ReadableStream,WritableBuffer,WritableStream};
use Amp\{DeferredCancellation,DeferredFuture};
use Dorpmaster\Nats\Domain\Client\{ClientInterface,ConnectionBoundProtocolClientInterface,ConnectionBoundRequestException,PublishChannelInterface,ServerInformation};
use Dorpmaster\Nats\Domain\JetStream\Admin\{JetStreamAdmin,JetStreamAdminInterface,JetStreamBackupAdminInterface};
use Dorpmaster\Nats\Domain\JetStream\Backup\{BackupOptions,RestoreOptions,StreamBackupMetadata};
use Dorpmaster\Nats\Domain\JetStream\Capability\{JetStreamCapabilities,JetStreamCapabilitiesProviderInterface};
use Dorpmaster\Nats\Domain\JetStream\Exception\{JetStreamArchiveException,JetStreamApiException};
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Protocol\{HMsgMessage,MessageStatus,MsgMessage};
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

use function Amp\delay;

final class ArchiveAdminTest extends TestCase
{
    private const string CONFIG      = '{"name":"S","subjects":["s"],"storage":"file","future":{"big":18446744073709551615,"empty":{}}}';
    private const string STATE       = '{"messages":1,"bytes":1,"first_seq":1,"last_seq":18446744073709551615,"consumer_count":0,"first_ts":"2026-11-02T01:02:03.123456789Z"}';
    private \Closure|null $deliver   = null;
    private \Closure|null $onFailure = null;
    private array $events            = [];
    private array $requests          = [];
    private int $closed              = 0;
    private string $accountReply     = '"api":{"level":5}';
    private \Closure|null $handler   = null;

    private function admin(int|null $level = 5, bool $current = true, int|null $maxPayload = 1048576, string $prefix = '$JS.API'): JetStreamAdmin
    {
        $client  = $this->createMockForIntersectionOfInterfaces([ClientInterface::class,ConnectionBoundProtocolClientInterface::class]);
        $server  = new ServerInformation('s', '2.15.2', true, $level, 7, static fn (): bool => $current, $maxPayload);
        $channel = $this->createStub(PublishChannelInterface::class);
        $channel->method('close')->willReturnCallback(function (): void {
            $this->closed++;
        });
        $channel->method('sendOnce')->willReturnCallback(function ($message, $attempt): void {
            $attempt();
            $this->events[] = ['ack', $message->getSubject(), $message->getPayload()];
        });
        $client->method('openProtocolChannelOnConnection')->willReturnCallback(function ($subject, $onMessage, $onFailure, $connection, $cancellation) use ($channel) {
            self::assertSame(7, $connection);
            $this->events[]  = ['sub', $subject];
            $this->deliver   = $onMessage;
            $this->onFailure = $onFailure;
            return $channel;
        });
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('requestOnce');
        $client->expects(self::never())->method('publish');
        $client->method('requestOnceOnConnection')->willReturnCallback(function ($message, $connection, $timeout, $scope) use ($prefix): object {
            self::assertSame(7, $connection);
            self::assertGreaterThan(0, $timeout);
            $scope->throwIfRequested();
            $this->requests[] = $message;
            $subject          = $message->getSubject();
            if ($subject === $prefix . '.INFO') {
                self::assertSame('5', $message->getHeaders()->get('Nats-Required-Api-Level'));
                return self::json('account_info_response', $this->accountReply);
            }
            return ($this->handler)($message, $scope);
        });
        $transport = $this->createMockForIntersectionOfInterfaces([JetStreamControlPlaneTransportInterface::class, JetStreamCapabilitiesProviderInterface::class]);
        $transport->method('getClient')->willReturn($client);
        $transport->method('getCapabilities')->willReturn(new JetStreamCapabilities($server, $prefix));
        $transport->expects(self::never())->method('request');
        $transport->expects(self::never())->method('publishRequest');
        return new JetStreamAdmin($transport);
    }
    private static function json(string $type, string $members): MsgMessage
    {
        return new MsgMessage('r', '1', '{"type":"io.nats.jetstream.api.v1.' . $type . '",' . $members . '}');
    }
    private static function metadata(int $bytes = 3): StreamBackupMetadata
    {
        return StreamBackupMetadata::completed('S', new JsonObject(self::CONFIG), new JsonObject(self::STATE), $bytes);
    }
    private static function statusMessage(int $code): HMsgMessage
    {
        return new HMsgMessage('d', '1', '', new HeaderBag(), status:new MessageStatus($code, 'synthetic status'));
    }
    private function snapshot(array $messages, string $config = self::CONFIG, string $state = self::STATE): void
    {
        $this->handler = function ($message) use ($messages, $config, $state): MsgMessage {
            self::assertSame('$JS.API.STREAM.SNAPSHOT.S', $message->getSubject());
            self::assertSame('5', $message->getHeaders()->get('Nats-Required-Api-Level'));
            self::assertSame('sub', $this->events[0][0]);
            EventLoop::queue(function () use ($messages): void {
                foreach ($messages as $message) {
                    ($this->deliver)($message);
                }
            });
            return self::json('stream_snapshot_response', '"config":' . $config . ',"state":' . $state);
        };
    }
    public static function terminators(): iterable
    {
        yield 'short archive plain MSG' => [false];
        yield 'full chunk HMSG 204' => [true];
    }
    #[DataProvider('terminators')]
    public function testBackupAsyncOrderedBytesAckAfterSuccessfulWriteAndPortableExactMetadata(bool $full): void
    {
        $admin = $this->admin();
        $data  = $full ? str_repeat("A\r\n\0", 256) : "A\r\n\0";
        $reply = $full ? '$JS.SNAPSHOT.ACK.S.token.1024.1' : null;
        $this->snapshot([new MsgMessage('d', '1', $data, $reply), $full ? self::statusMessage(204) : new MsgMessage('d', '1', '')]);
        $sink = $this->createMock(WritableStream::class);
        $sink->method('isWritable')->willReturn(true);
        $sink->expects(self::never())->method('close');
        $sink->expects(self::once())->method('write')->with($data)->willReturnCallback(function (): void {
            self::assertSame([], array_values(array_filter($this->events, static fn ($e) => $e[0] === 'ack')));
            delay(0); // Callback and writes may suspend; ACK still follows completed IO.
            $this->events[] = ['write_done'];
        });
        $metadata = $admin->backupStream('S', $sink, new BackupOptions(chunkSize:1024, windowSize:1024));
        self::assertSame(strlen($data), $metadata->archiveBytes);
        self::assertSame(self::CONFIG, $metadata->config->toJson());
        self::assertSame(self::STATE, $metadata->state->toJson());
        self::assertSame($metadata->toJson(), StreamBackupMetadata::fromJson($metadata->toJson())->toJson());
        self::assertSame($full ? ['sub','write_done','ack'] : ['sub','write_done'], array_column($this->events, 0));
        self::assertSame(1, $this->closed);
        self::assertInstanceOf(JetStreamBackupAdminInterface::class, $admin);
        self::assertFalse((new \ReflectionClass(JetStreamAdminInterface::class))->hasMethod('backupStream'));
    }
    public static function invalidSnapshots(): iterable
    {
        yield '408' => [[new MsgMessage('d', '1', 'part'), self::statusMessage(408)],408];
        yield '500' => [[new MsgMessage('d', '1', 'part'), self::statusMessage(500)],500];
        yield '204 without bytes' => [[self::statusMessage(204)],204];
        yield 'plain EOF after full chunk' => [[new MsgMessage('d', '1', str_repeat('x', 1024), '$JS.SNAPSHOT.ACK.S.t.1024.1'),new MsgMessage('d', '1', '')],0];
        foreach (['$JS.SNAPSHOT.ACK.OTHER.t.1024.1','$JS.SNAPSHOT.ACK.S.t.1024.2','$JS.SNAPSHOT.ACK.S.t.1023.1','arbitrary.subject',null] as $reply) {
            yield 'invalid ACK ' . ($reply ?? 'null') => [[new MsgMessage('d', '1', str_repeat('x', 1024), $reply)],0];
        }
        yield 'data after short tail' => [[new MsgMessage('d', '1', 'a'),new MsgMessage('d', '1', 'b')],0];
        yield 'oversized data' => [[new MsgMessage('d', '1', str_repeat('a', 1025))],0];
        yield 'overflow queue' => [[new MsgMessage('d', '1', str_repeat('a', 1024), '$JS.SNAPSHOT.ACK.S.t.1024.1'),new MsgMessage('d', '1', str_repeat('b', 1024), '$JS.SNAPSHOT.ACK.S.t.1024.2'),new MsgMessage('d', '1', str_repeat('c', 1024), '$JS.SNAPSHOT.ACK.S.t.1024.3')],0];
    }
    #[DataProvider('invalidSnapshots')]
    public function testInvalidOrUnfinishedSnapshotCannotReturnSuccess(array $messages, int $code): void
    {
        $admin = $this->admin();
        $this->snapshot($messages);
        try {
            $admin->backupStream('S', new WritableBuffer(), new BackupOptions(chunkSize:1024, windowSize:1024), 30);
            self::fail('No successful terminator');
        } catch (JetStreamArchiveException $error) {
            self::assertSame($code, $error->getApiCode());
            self::assertFalse($error->isOutcomeUnknown());
            self::assertSame('backup', $error->getOperation());
            self::assertSame(1, $this->closed);
        }
    }
    public function testSinkFailureIsOriginalCauseAndChunkIsNotAcknowledged(): void
    {
        $admin = $this->admin();
        $cause = new RuntimeException('synthetic sink failure');
        $this->snapshot([new MsgMessage('d', '1', str_repeat('x', 1024), '$JS.SNAPSHOT.ACK.S.t.1024.1')]);
        $sink = $this->createMock(WritableStream::class);
        $sink->method('isWritable')->willReturn(true);
        $sink->method('write')->willThrowException($cause);
        $sink->expects(self::once())->method('close');
        try {
            $admin->backupStream('S', $sink, new BackupOptions(chunkSize:1024, windowSize:1024));
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertSame($cause, $error->getPrevious());
            self::assertTrue($error->mayHavePartialOutput());
            self::assertSame(0, $error->getTransferredBytes());
            self::assertSame(['sub'], array_column($this->events, 0));
        }
    }
    public static function restoreFailures(): iterable
    {
        yield 'timeout status' => ['status408',true,408,null];
        yield 'gateway status' => ['status504',true,504,null];
        yield 'JSON timeout' => ['json408',true,408,null];
        yield 'genuine server failure' => ['error',false,500,10025];
        yield 'wrong type with error' => ['wrong',true,400,10058];
        yield 'wrong final stream' => ['identity',true,0,null];
        yield 'missing final' => ['lost',true,0,null];
        yield 'EOF was not attempted' => ['unsent',true,0,null];
    }
    #[DataProvider('restoreFailures')]
    public function testRestoreRequiresFinalIdentityAndPreservesCodesCauseAndUnknown(string $mode, bool $unknown, int $code, int|null $errCode): void
    {
        $admin         = $this->admin();
        $calls         = 0;
        $cause         = new RuntimeException('synthetic final failure');
        $this->handler = function ($frame) use (&$calls, $mode, $cause): object {
            $calls++;
            if ($calls === 1) {
                self::assertSame('$JS.API.STREAM.RESTORE.D', $frame->getSubject());
                $raw = new JsonObject($frame->getPayload());
                self::assertSame('D', $raw->getObject('config')->get('name'));
                self::assertSame('memory', $raw->getObject('config')->get('storage'));
                self::assertStringContainsString('18446744073709551615', $frame->getPayload());
                self::assertNull($raw->get('format'));
                self::assertNull($raw->get('version'));
                return self::json('stream_restore_response', '"deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"');
            }
            if ($frame->getPayload() !== '') {
                return new MsgMessage('r', '1', '');
            }
            return match ($mode) {
                'status408'=>self::statusMessage(408),'status504'=>self::statusMessage(504),
                'json408'=>new MsgMessage('r', '1', '{"error":{"code":408,"description":"timeout"}}'),
                'error'=>self::json('stream_create_response', '"error":{"code":500,"err_code":10025,"description":"bad archive"}'),
                'wrong'=>self::json('consumer_info_response', '"error":{"code":400,"err_code":10058,"description":"exists"}'),
                'identity'=>self::json('stream_create_response', '"config":{"name":"OTHER","storage":"memory"}'),
                default=>throw new ConnectionBoundRequestException($mode !== 'unsent', $cause),
            };
        };
        try {
            $admin->restoreStream('D', new ReadableBuffer("a\0b"), self::metadata(), 'memory');
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertSame($unknown, $error->isOutcomeUnknown());
            self::assertSame($code, $error->getApiCode());
            self::assertSame($errCode, $error->getErrorCode());
            self::assertSame('D', $error->getStream());
            self::assertSame(3, $error->getTransferredBytes());
            self::assertSame(1, $this->closed);
            self::assertSame(3, $calls);
            if (in_array($mode, ['lost','unsent'], true)) {
                self::assertSame($cause, $error->getPrevious()->getPrevious());
            }
        }
    }
    public function testRestoreExactBytesBoundedChunkingAndSingleFinalWithCallerStreamOpen(): void
    {
        $admin         = $this->admin();
        $data          = "a\0\r\nb";
        $chunks        = [];
        $this->handler = function ($frame) use (&$chunks): MsgMessage {
            if (str_contains($frame->getSubject(), '.STREAM.RESTORE.')) {
                return self::json('stream_restore_response', '"deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"');
            }
            $chunks[] = $frame->getPayload();
            return $frame->getPayload() === '' ? self::json('stream_create_response', '"config":{"name":"D","storage":"memory","subjects":["s"]}') : new MsgMessage('r', '1', '');
        };
        $input         = new ReadableBuffer($data);
        $info          = $admin->restoreStream('D', $input, self::metadata(strlen($data)), 'memory', new RestoreOptions(chunkSize:2, maxSourceChunkSize:10));
        self::assertSame('D', $info->getName());
        self::assertSame(["a\0","\r\n",'b',''], $chunks);
        self::assertSame(1, $this->closed);
    }
    public static function badRestoreStarts(): iterable
    {
        foreach (['evil','$JS.SNAPSHOT.RESTORE.OTHER.token','$JS.SNAPSHOT.RESTORE.D.a.b','$JS.SNAPSHOT.RESTORE.D.','$JS.SNAPSHOT.RESTORE.D.token' . "\n"] as $subject) {
            yield[$subject];
        }
    }
    #[DataProvider('badRestoreStarts')]
    public function testRestoreRejectsUntrustedUploadBeforeReadingOrUploading(string $subject): void
    {
        $admin         = $this->admin();
        $this->handler = static fn()=>self::json('stream_restore_response', '"deliver_subject":' . json_encode($subject, JSON_THROW_ON_ERROR));
        $source        = $this->createMock(ReadableStream::class);
        $source->method('isReadable')->willReturn(true);
        $source->expects(self::never())->method('read');
        $source->expects(self::never())->method('close');
        try {
            $admin->restoreStream('D', $source, self::metadata(), 'file');
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertTrue($error->isOutcomeUnknown());
            self::assertCount(2, $this->requests);
        }
    }
    public function testReadFailureKeepsOriginalCauseAndClosesOwnedOperation(): void
    {
        $admin         = $this->admin();
        $cause         = new RuntimeException('synthetic read failure');
        $this->handler = static fn()=>self::json('stream_restore_response', '"deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"');
        $source        = $this->createMock(ReadableStream::class);
        $source->method('isReadable')->willReturn(true);
        $source->method('read')->willThrowException($cause);
        $source->expects(self::once())->method('close');
        try {
            $admin->restoreStream('D', $source, self::metadata(), 'file');
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertSame($cause, $error->getPrevious());
            self::assertSame(0, $error->getTransferredBytes());
            self::assertTrue($error->isOutcomeUnknown());
            self::assertSame(1, $this->closed);
        }
    }
    public static function incompatible(): iterable
    {
        yield[4,true,1048576];
        yield[2,true,1048576];
        yield[5,false,1048576];
        yield[5,true,null];
    }
    #[DataProvider('incompatible')]
    public function testUnsupportedOrUnestablishedConnectionHasZeroStarts(int $level, bool $current, int|null $max): void
    {
        $admin = $this->admin($level, $current, $max);
        foreach (['backup','restore'] as $op) {
            try {
                if ($op === 'backup') {
                    $admin->backupStream('S', new WritableBuffer());
                } else {
                    $admin->restoreStream('D', new ReadableBuffer('abc'), self::metadata(), 'file');
                }
                self::fail();
            } catch (JetStreamArchiveException $error) {
                self::assertFalse($error->isOutcomeUnknown());
                self::assertSame([], $this->requests);
                self::assertSame([], $this->events);
            }
        }
    }
    public function testCancelledBeforeAdmissionHasZeroEffects(): void
    {
        $admin  = $this->admin();
        $cancel = new DeferredCancellation();
        $cause  = new RuntimeException('synthetic cancellation');
        $cancel->cancel($cause);
        try {
            $admin->backupStream('S', new WritableBuffer(), cancellation:$cancel->getCancellation());
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertSame([], $this->requests);
            self::assertSame([], $this->events);
            self::assertFalse($error->isOutcomeUnknown());
        }
    }
    public static function missingFinal(): iterable
    {
        yield['cancel'];
        yield['deadline'];
        yield['disconnect'];
    }
    #[DataProvider('missingFinal')]
    public function testMissingBackupFinalOrConnectionLossSettlesOnceAndReleasesInbox(string $mode): void
    {
        $admin         = $this->admin();
        $cancel        = new DeferredCancellation();
        $cause         = new RuntimeException('synthetic disconnect');
        $this->handler = function () use ($mode, $cancel, $cause): MsgMessage {
            EventLoop::queue(function () use ($mode, $cancel, $cause): void {
                ($this->deliver)(new MsgMessage('d', '1', 'abc'));
                if ($mode === 'cancel') {
                    EventLoop::queue(static fn()=>$cancel->cancel());
                }
                if ($mode === 'disconnect') {
                    EventLoop::queue(fn()=>($this->onFailure)($cause));
                }
            });
            return self::json('stream_snapshot_response', '"config":' . self::CONFIG . ',"state":' . self::STATE);
        };
        try {
            $admin->backupStream('S', new WritableBuffer(), timeoutMs:30, cancellation:$cancel->getCancellation());
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertSame(1, $this->closed);
            self::assertTrue($error->mayHavePartialOutput());
            if ($mode === 'disconnect') {
                self::assertSame($cause, $error->getPrevious());
            }
        }
        ($this->deliver)(new MsgMessage('d', '1', '')); // No second completion after cleanup.
        self::assertSame(1, $this->closed);
    }
    public static function invalidLocal(): iterable
    {
        foreach (['',"S\n",'A.B','a b'] as $stream) {
            yield[$stream,1000];
        }
        foreach ([0,-1,1.5,true,'20'] as $timeout) {
            yield['S',$timeout];
        }
    }
    #[DataProvider('invalidLocal')]
    public function testLocalArgumentsFailBeforeSubscriptions(string $stream, mixed $timeout): void
    {
        $admin = $this->admin();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $admin->backupStream($stream, new WritableBuffer(), timeoutMs:$timeout);
        } finally {
            self::assertSame([], $this->requests);
            self::assertSame([], $this->events);
        }
    }

    public static function unknownTarget(): iterable
    {
        foreach (['"api":{"level":4}','"api":{}','"api":null','"api":{"level":"5"}'] as $account) {
            yield[$account];
        }
    }
    #[DataProvider('unknownTarget')]
    public function testRemoteTargetMustProveLevelFiveBeforeEitherStart(string $account): void
    {
        $admin              = $this->admin(prefix:'$JS.EU.API');
        $this->accountReply = $account;
        foreach (['backup','restore'] as $mode) {
            try {
                if ($mode === 'backup') {
                    $admin->backupStream('S', new WritableBuffer());
                } else {
                    $admin->restoreStream('D', new ReadableBuffer('abc'), self::metadata(), 'file');
                }
                self::fail();
            } catch (JetStreamArchiveException $error) {
                self::assertFalse($error->isOutcomeUnknown());
                self::assertSame([], $this->events);
            }
        }
        self::assertCount(2, $this->requests);
        foreach ($this->requests as $message) {
            self::assertSame('$JS.EU.API.INFO', $message->getSubject());
        }
    }
    public static function ioLengths(): iterable
    {
        yield['ab',3,1048576];
        yield['abcd',3,1048576];
        yield[str_repeat('x', 11),11,10];
    }
    #[DataProvider('ioLengths')]
    public function testTruncationExcessLengthOrSourceChunkBoundCannotSucceed(string $data, int $declared, int $sourceLimit): void
    {
        $admin         = $this->admin();
        $this->handler = static fn($f)=>str_contains($f->getSubject(), '.STREAM.RESTORE.') ? self::json('stream_restore_response', '"deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"') : new MsgMessage('r', '1', '');
        try {
            $admin->restoreStream('D', new ReadableBuffer($data), self::metadata($declared), 'file', new RestoreOptions(chunkSize:2, maxSourceChunkSize:$sourceLimit));
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertTrue($error->isOutcomeUnknown());
            self::assertSame(1, $this->closed);
            $uploads = array_filter($this->requests, static fn($f)=>str_starts_with($f->getSubject(), '$JS.SNAPSHOT.RESTORE.') && $f->getPayload() === '');
            self::assertCount(0, $uploads);
        }
    }
    public static function optionsOutsideBounds(): iterable
    {
        yield['backup',0,1024];
        yield['backup',1048577,2097152];
        yield['backup',1024,1023];
        yield['backup',1024,33554433];
        yield['restore',0,1024];
        yield['restore',1048577,2097152];
        yield['restore',1024,1023];
        yield['restore',1024,33554433];
    }
    #[DataProvider('optionsOutsideBounds')]
    public function testBufferOptionsHaveExplicitLocalBounds(string $mode, int $chunk, int $window): void
    {
        $this->expectException(\InvalidArgumentException::class);
        if ($mode === 'backup') {
            new BackupOptions(chunkSize:$chunk, windowSize:$window);
        } else {
            new RestoreOptions(chunkSize:$chunk, maxSourceChunkSize:$window);
        }
    }
    public function testBlockedSinkIsClosedOnDeadlineAndDoesNotSendAck(): void
    {
        $admin = $this->admin();
        $this->snapshot([new MsgMessage('d', '1', str_repeat('x', 1024), '$JS.SNAPSHOT.ACK.S.t.1024.1')]);
        $wait = new DeferredFuture();
        $sink = $this->createMock(WritableStream::class);
        $sink->method('isWritable')->willReturn(true);
        $sink->method('write')->willReturnCallback(static fn()=>$wait->getFuture()->await());
        $sink->expects(self::once())->method('close')->willReturnCallback(static fn()=>$wait->complete());
        try {
            $admin->backupStream('S', $sink, new BackupOptions(chunkSize:1024, windowSize:1024), 20);
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertTrue($error->mayHavePartialOutput());
            self::assertSame(0, $error->getTransferredBytes());
            self::assertSame(['sub'], array_column($this->events, 0));
            self::assertSame(1, $this->closed);
        }
    }
    public static function invalidMetadata(): iterable
    {
        yield['type','"other"'];
        yield['archive_bytes','0'];
        yield['archive_bytes','9223372036854775808'];
        yield['config','[]'];
        yield['state','null'];
    }
    #[DataProvider('invalidMetadata')]
    public function testPersistedMetadataIsValidatedWithoutNumericOrShapeCoercion(string $field, string $raw): void
    {
        $json   = self::metadata()->toJson();
        $object = new JsonObject($json);
        $values = json_decode($object->toJson(), false);
        $pairs  = [];
        foreach (get_object_vars($values) as $key => $value) {
            $pairs[] = json_encode($key) . ':' . ($key === $field ? $raw : $object->getRawValue($key));
        }
        $this->expectException(\Throwable::class);
        StreamBackupMetadata::fromJson('{' . implode(',', $pairs) . '}');
    }

    public function testRestoreUploadRespectsAnnouncedMaxPayload(): void
    {
        $admin         = $this->admin(maxPayload:512);
        $sizes         = [];
        $this->handler = static function ($frame) use (&$sizes): MsgMessage {
            if (str_contains($frame->getSubject(), '.STREAM.RESTORE.')) {
                return self::json('stream_restore_response', '"deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"');
            }
            $sizes[] = strlen($frame->getPayload());
            return $frame->getPayload() === '' ? self::json('stream_create_response', '"config":{"name":"D","storage":"file","subjects":["s"]}') : new MsgMessage('r', '1', '');
        };
        $admin->restoreStream('D', new ReadableBuffer(str_repeat('x', 1000)), self::metadata(1000), 'file', new RestoreOptions(chunkSize:1024, maxSourceChunkSize:2048));
        self::assertSame([512,488,0], $sizes);
    }
    public static function wrongScalarTypes(): iterable
    {
        foreach ([1,1.5,true,false,null,[],new \stdClass()] as $value) {
            yield['name',$value];
            yield['storage',$value];
        }
    }
    #[DataProvider('wrongScalarTypes')]
    public function testRestoreRejectsOriginalScalarTypesBeforeClientEffects(string $field, mixed $value): void
    {
        $admin = $this->admin();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $admin->restoreStream($field === 'name' ? $value : 'D', new ReadableBuffer('abc'), self::metadata(), $field === 'storage' ? $value : 'file');
        } finally {
            self::assertSame([], $this->requests);
            self::assertSame([], $this->events);
        }
    }
    public static function malformedErrorCodes(): iterable
    {
        yield 'absent code' => ['"err_code":10003,"description":"failure"', 0];
        foreach (['"400"','200','null','399','600','4e2','400.0','9223372036854775808'] as $code) {
            yield $code => ['"code":' . $code . ',"err_code":10003,"description":"failure"', in_array($code, ['200','399','600'], true) ? (int) $code : 0];
        }
    }
    #[DataProvider('malformedErrorCodes')]
    public function testMalformedFinalErrorDoesNotConfirmRestoreFailure(string $error, int $code): void
    {
        $admin         = $this->admin();
        $this->handler = static function ($frame) use ($error): MsgMessage {
            if (str_contains($frame->getSubject(), '.STREAM.RESTORE.')) {
                return self::json('stream_restore_response', '"deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"');
            }
            return $frame->getPayload() === '' ? self::json('stream_create_response', '"error":{' . $error . '}') : new MsgMessage('r', '1', '');
        };
        try {
            $admin->restoreStream('D', new ReadableBuffer('abc'), self::metadata(), 'memory');
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertTrue($error->isOutcomeUnknown());
            self::assertSame($code, $error->getApiCode());
            self::assertSame(10003, $error->getErrorCode());
            self::assertSame('failure', $error->getDescription());
            self::assertSame(3, $error->getTransferredBytes());
        }
    }
    public static function badSubjects(): iterable
    {
        foreach (['null','{}','false','"orders.*"','[9223372036854775808]','["orders.*",18446744073709551615]'] as $value) {
            yield [$value];
        }
    }
    #[DataProvider('badSubjects')]
    public function testSnapshotAndPersistedSubjectsUseOriginalJsonTypesBeforeRestore(string $subjects): void
    {
        $config = '{"name":"S","subjects":' . $subjects . ',"storage":"file"}';
        $admin  = $this->admin();
        $this->snapshot([new MsgMessage('d', '1', 'abc'),new MsgMessage('d', '1', '')], $config);
        $sink = $this->createMock(WritableStream::class);
        $sink->method('isWritable')->willReturn(true);
        $sink->expects(self::never())->method('write');
        try {
            $admin->backupStream('S', $sink);
            self::fail();
        } catch (JetStreamArchiveException $error) {
            self::assertStringContainsString('subjects', $error->getMessage());
            self::assertSame(0, $error->getTransferredBytes());
        }
        $json = '{"type":"dorpmaster.nats.backup.v2","stream":"S","config":' . $config . ',"state":' . self::STATE . ',"archive_bytes":3}';
        try {
            $metadata = StreamBackupMetadata::fromJson($json);
            $admin->restoreStream('D', new ReadableBuffer('abc'), $metadata, 'file');
            self::fail();
        } catch (JetStreamApiException $error) {
            self::assertStringContainsString('subjects', $error->getMessage());
        }
        self::assertCount(0, array_filter($this->requests, static fn($m)=>str_contains($m->getSubject(), '.STREAM.RESTORE.')));
    }
    public static function validSubjects(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => ['[]'];
        yield 'numeric string' => ['["9223372036854775808"]'];
    }
    #[DataProvider('validSubjects')]
    public function testSnapshotAndRestorePreserveAbsentEmptyAndStringSubjects(string|null $subjects): void
    {
        $config = '{"name":"S","storage":"file"' . ($subjects === null ? '' : ',"subjects":' . $subjects) . '}';
        $admin  = $this->admin();
        $this->snapshot([new MsgMessage('d', '1', 'abc'),new MsgMessage('d', '1', '')], $config);
        $backup = $admin->backupStream('S', new WritableBuffer());
        self::assertSame($subjects, $backup->config->getRawValue('subjects'));
        $this->handler = static function ($frame) use ($subjects): MsgMessage {
            if (str_contains($frame->getSubject(), '.STREAM.RESTORE.')) {
                self::assertSame($subjects, (new JsonObject($frame->getPayload()))->getObject('config')->getRawValue('subjects'));
                return self::json('stream_restore_response', '"deliver_subject":"$JS.SNAPSHOT.RESTORE.D.token"');
            }
            return $frame->getPayload() === '' ? self::json('stream_create_response', '"config":{"name":"D","storage":"file"}') : new MsgMessage('r', '1', '');
        };
        self::assertSame('D', $admin->restoreStream('D', new ReadableBuffer('abc'), StreamBackupMetadata::fromJson($backup->toJson()), 'file')->getName());
    }
    public function testClosedInputHasNoSubscriptionsRequestsOrReads(): void
    {
        $admin  = $this->admin();
        $source = $this->createMock(ReadableStream::class);
        $source->method('isReadable')->willReturn(false);
        $source->expects(self::never())->method('read');
        $source->expects(self::never())->method('close');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('input');
        try {
            $admin->restoreStream('D', $source, self::metadata(), 'file');
        } finally {
            self::assertSame([], $this->requests);
            self::assertSame([], $this->events);
        }
    }
    public function test204AfterTailWithoutAnyFullChunkCannotConfirmBackup(): void
    {
        $admin = $this->admin();
        $this->snapshot([new MsgMessage('d', '1', 'tail'),self::statusMessage(204)]);
        try {
            $admin->backupStream('S', new WritableBuffer());
            self::fail('A lost full chunk must not become a completed archive');
        } catch (JetStreamArchiveException $error) {
            self::assertSame(204, $error->getApiCode());
            self::assertTrue($error->mayHavePartialOutput());
        }
    }

    public function testLegacyStreamingClientCannotSilentlyDowngradeArchiveDelivery(): void
    {
        $client = $this->createMockForIntersectionOfInterfaces([ClientInterface::class,\Dorpmaster\Nats\Domain\Client\ConnectionBoundPublishClientInterface::class]);
        $client->expects(self::never())->method('openPublishChannelOnConnection');
        $client->expects(self::never())->method('requestOnceOnConnection');
        $transport = $this->createMockForIntersectionOfInterfaces([JetStreamControlPlaneTransportInterface::class,JetStreamCapabilitiesProviderInterface::class]);
        $transport->method('getClient')->willReturn($client);
        $transport->expects(self::never())->method('getCapabilities');
        $admin = new JetStreamAdmin($transport);
        try {
            $admin->backupStream('S', new WritableBuffer());
            self::fail();
        } catch (JetStreamArchiveException $e) {
            self::assertFalse($e->isOutcomeUnknown());
            self::assertStringContainsString('ConnectionBoundProtocolClientInterface', $e->getMessage());
        }
    }
}
