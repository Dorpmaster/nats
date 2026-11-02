<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Amp\ByteStream\{ReadableBuffer,WritableBuffer,ReadableResourceStream,WritableResourceStream};
use Dorpmaster\Nats\Domain\JetStream\Backup\{BackupOptions,RestoreOptions,StreamBackupMetadata};
use Dorpmaster\Nats\Client\ClientConfiguration;
use Dorpmaster\Nats\Domain\Connection\ConnectionException;
use Dorpmaster\Nats\Protocol\Contracts\StatusMessageInterface;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamConfig};
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamArchiveException;
use Dorpmaster\Nats\Protocol\{HPubMessage,PubMessage};
use Dorpmaster\Nats\Protocol\Header\HeaderBag;
use Dorpmaster\Nats\Tests\Support\{AsyncTestTools,JetStreamPlacementFixture};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class JetStreamBackupIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function storages(): iterable
    {
        yield ['file','memory'];
        yield ['memory','file'];
    }
    #[DataProvider('storages')]
    public function testV2PortableArchive(string $sourceStorage, string $targetStorage): void
    {
        if (getenv('NATS_ARCHIVE_MODE') !== 'modern') {
            self::markTestSkipped('Run the V2 archive profile');
        }
        $this->setTimeout(40);
        $this->runAsyncTest(function () use ($sourceStorage, $targetStorage): void {
            $received = [];
            $f        = new JetStreamPlacementFixture(onReceive: static function ($message) use (&$received): void {
                if (method_exists($message, 'getPayload') && method_exists($message, 'getSubject')) {
                    $received[] = ['subject' => $message->getSubject(), 'bytes' => strlen($message->getPayload()), 'status' => $message instanceof StatusMessageInterface ? $message->getStatus()?->code : null, 'reply' => $message->getReplyTo()];
                }
            });
            $reader   = new JetStreamPlacementFixture();
            $target   = $f->stream . '_RESTORED';
            try {
                $f->admin->createStream(new StreamConfig($f->stream, [$f->subject . '.*'], $sourceStorage, 1));
                $payloads = ["first\r\n\0", random_bytes(5000), 'removed', random_bytes(5000), 'trailing-gap'];
                foreach ($payloads as $i => $body) {
                    $headers = new HeaderBag(['X-Audit' => ['NATS/1.0','https://example.test/a:b']]);
                    $f->client->request(new HPubMessage($f->subject . '.a', $body, $headers), 3);
                }
                foreach ([3,5] as $seq) {
                    $f->transport->requestJson('STREAM.MSG.DELETE.' . $f->stream, JsonObject::fromArray(['seq' => $seq]));
                }
                $sink   = new WritableBuffer();
                $backup = $f->admin->backupStream($f->stream, $sink, new BackupOptions(chunkSize:1024, windowSize:2048), 15000);
                self::assertTrue($sink->isWritable());
                $sink->end();
                $archive = $sink->buffer();
                self::assertSame(strlen($archive), $backup->archiveBytes);
                $metadata = StreamBackupMetadata::fromJson($backup->toJson());
                $f->admin->deleteStream($f->stream);
                $result = $f->admin->restoreStream($target, new ReadableBuffer($archive), $metadata, $targetStorage, timeoutMs:15000);
                self::assertSame($target, $result->getName());
                self::assertSame($targetStorage, $result->getConfiguration()->get('storage'));
                foreach ([1,2,4] as $seq) {
                    $message = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => $seq]))->getObject('message');
                    self::assertSame($payloads[$seq - 1], base64_decode($message->get('data'), true));
                    self::assertSame($f->subject . '.a', $message->get('subject'));
                    self::assertSame($seq, $message->get('seq'));
                    self::assertStringContainsString('X-Audit: NATS/1.0', base64_decode($message->get('hdrs'), true));
                    self::assertStringContainsString('X-Audit: https://example.test/a:b', base64_decode($message->get('hdrs'), true));
                }
                $next = json_decode($f->client->request(new PubMessage($f->subject . '.a', 'next'), 3)->getPayload(), true);
                self::assertSame(6, $next['seq']);
                self::assertSame('6', $reader->admin->getStreamInfo($target)->getState()->lastSequence);
                $this->trace('portable-' . $sourceStorage, ['received' => $received, 'metadata' => $backup->toJson(), 'archive_bytes' => strlen($archive), 'restored' => $result->getResponse()->toJson(), 'next_ack' => $next]);
            } finally {
                foreach ([$target,$f->stream] as $stream) {
                    try {
                        $f->admin->deleteStream($stream);
                    } catch (\Throwable) {
                    }
                }
                $reader->close();
                $f->close();
            }
        });
    }
    public function testOldApiHasZeroV2StartCommands(): void
    {
        if (getenv('NATS_ARCHIVE_MODE') !== 'old') {
            self::markTestSkipped('Run the old archive profile');
        }
        $this->setTimeout(10);
        $this->runAsyncTest(function (): void {
            $wire = [];
            $f    = new JetStreamPlacementFixture(onSend: static function ($message) use (&$wire): void {
                $wire[] = (string) $message;
            });
            try {
                $f->seed(1);
                $sink = new WritableBuffer();
                try {
                    $f->admin->backupStream($f->stream, $sink);
                    self::fail('Old server cannot start V2');
                } catch (JetStreamArchiveException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                }
                self::assertTrue($sink->isWritable());
                $metadata = StreamBackupMetadata::fromJson('{"type":"dorpmaster.nats.backup.v2","stream":"S","archive_bytes":3,"config":{"name":"S","subjects":["s"],"storage":"file"},"state":{"messages":1,"bytes":3,"first_seq":1,"last_seq":1,"consumer_count":0}}');
                try {
                    $f->admin->restoreStream($f->stream . '_RESTORED', new ReadableBuffer('abc'), $metadata, 'file');
                    self::fail('Old server cannot start V2 restore');
                } catch (JetStreamArchiveException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                }
                self::assertSame('1', $f->admin->getStreamInfo($f->stream)->getState()->messages);
                $this->trace('old-api', ['version' => $f->client->getServerInformation()->version, 'v2_start_commands' => 0, 'commands' => $wire]);
                self::assertCount(0, array_filter($wire, static fn (string $frame): bool => str_contains($frame, '.STREAM.SNAPSHOT.') || str_contains($frame, '.STREAM.RESTORE.')));
            } finally {
                $f->close();
            }
        });
    }

    private function modern(): void
    {
        if (getenv('NATS_ARCHIVE_MODE') !== 'modern') {
            self::markTestSkipped('Run the V2 archive profile');
        }
        $this->setTimeout(60);
    }
    public function testV2SmallArchivePlainTerminatorAndNoConsumers(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $received = [];
            $wire     = [];
            $f        = new JetStreamPlacementFixture(onSend:static function ($m) use (&$wire): void {
                if (str_contains((string)$m, '.STREAM.SNAPSHOT.')) {
                    $wire[] = (string)$m;
                }
            }, onReceive:static function ($m) use (&$received): void {
                if (method_exists($m, 'getSubject') && str_starts_with($m->getSubject(), '_INBOX.ARCHIVE.') && method_exists($m, 'getPayload')) {
                    $received[] = ['bytes' => strlen($m->getPayload()),'status' => $m instanceof StatusMessageInterface ? $m->getStatus()?->code : null];
                }
            });
            $reader   = new JetStreamPlacementFixture();
            $target   = $f->stream . '_RESTORED';
            try {
                $f->seed(1);
                $sink   = new WritableBuffer();
                $backup = $f->admin->backupStream($f->stream, $sink, new BackupOptions(includeConsumers:false, chunkSize:1048576, windowSize:1048576), 15000);
                $sink->end();
                $archive = $sink->buffer();
                self::assertLessThan(1048576, strlen($archive));
                self::assertContains(['bytes' => 0,'status' => null], $received);
                self::assertNotContains(['bytes' => 0,'status' => 204], $received);
                $f->admin->deleteStream($f->stream);
                $f->admin->restoreStream($target, new ReadableBuffer($archive), StreamBackupMetadata::fromJson($backup->toJson()), 'memory', timeoutMs:15000);
                self::assertSame(0, $reader->admin->getStreamInfo($target)->getResponse()->getObject('state')->get('consumer_count'));
                $message = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                self::assertSame(JetStreamPlacementFixture::payload(1), base64_decode($message->get('data'), true));
                $this->trace('small-no-consumers', ['metadata' => $backup->toJson(),'start' => $wire,'received' => $received,'message' => $message->toJson()]);
            } finally {
                try {
                    $f->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
            }
        });
    }
    public function testV2CorruptionTruncationAndExistingDestinationPreserveData(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $f            = new JetStreamPlacementFixture();
            $reader       = new JetStreamPlacementFixture();
            $target       = $f->stream . '_RESTORED';
            $observations = [];
            try {
                $f->seed(2);
                $sink   = new WritableBuffer();
                $backup = $f->admin->backupStream($f->stream, $sink, timeoutMs:15000);
                $sink->end();
                $archive = $sink->buffer();
                // Existing destination is never removed or overwritten by the driver.
                try {
                    $f->admin->restoreStream($f->stream, new ReadableBuffer($archive), $backup, 'file', timeoutMs:10000);
                    self::fail('Existing target');
                } catch (JetStreamArchiveException $e) {
                    self::assertFalse($e->isOutcomeUnknown());
                    self::assertSame(400, $e->getApiCode());
                    $observations['conflict'] = ['code' => $e->getApiCode(),'err_code' => $e->getErrorCode(),'bytes' => $e->getTransferredBytes()];
                }
                $old = $reader->transport->requestJson('STREAM.MSG.GET.' . $f->stream, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                self::assertSame(JetStreamPlacementFixture::payload(1), base64_decode($old->get('data'), true));
                // Disjoint destination subjects avoid the original stream's subject overlap.
                $f->admin->deleteStream($f->stream);
                foreach (['corrupt','truncated'] as $mode) {
                    $bytes = $mode === 'corrupt' ? str_repeat("\xff", strlen($archive)) : substr($archive, 0, -8);
                    // A forged byte count exercises server sentinel/format checks, not just the client's length guard.
                    $metadata = StreamBackupMetadata::fromJson((new JsonObject($backup->toJson()))->withFields(['archive_bytes' => strlen($bytes)])->toJson());
                    try {
                        $f->admin->restoreStream($target, new ReadableBuffer($bytes), $metadata, 'memory', timeoutMs:6000);
                        self::fail('Invalid archive');
                    } catch (JetStreamArchiveException $e) {
                        self::assertFalse($e->isOutcomeUnknown());
                        self::assertGreaterThan(0, $e->getErrorCode() ?? 0);
                        $observations[$mode] = ['code' => $e->getApiCode(),'err_code' => $e->getErrorCode(),'unknown' => $e->isOutcomeUnknown()];
                    }
                    try {
                        $reader->admin->getStreamInfo($target);
                        self::fail('Failed restore cannot create a ready stream');
                    } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $e) {
                        self::assertSame(404, $e->getApiCode());
                    }
                }
                $this->trace('invalid-archives', $observations + ['existing_data' => $old->toJson()]);
            } finally {
                try {
                    $f->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
            }
        });
    }
    public function testV2LostFinalResponseReportsUnknownButIndependentReaderConfirmsRestore(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $target = null;
            $lost   = false;
            $frames = [];
            $cause  = new ConnectionException('synthetic archive final loss');
            $f      = new JetStreamPlacementFixture(onSend:static function ($m, int $session) use (&$frames): void {
                if (preg_match('/\A(?:H?PUB) (\S+)/', (string)$m, $match) && str_contains($match[1], '$JS.')) {
                    $frames[] = ['session' => $session,'subject' => $match[1],'bytes' => strlen($m->getPayload())];
                }
            }, onReceive:static function ($m) use (&$target, &$lost, $cause): void {
                if (!$lost && $target !== null && method_exists($m, 'getPayload')) {
                    $json = json_decode($m->getPayload(), true);
                    if (($json['type'] ?? null) === 'io.nats.jetstream.api.v1.stream_create_response' && ($json['config']['name'] ?? null) === $target) {
                        $lost = true;
                        throw $cause;
                    }
                }
            }, configuration:new ClientConfiguration(reconnectEnabled:true, maxReconnectAttempts:10, reconnectBackoffInitialMs:10, reconnectBackoffMaxMs:20, reconnectJitterFraction:0.0));
            $reader = new JetStreamPlacementFixture();
            try {
                $f->seed(1);
                $sink   = new WritableBuffer();
                $backup = $f->admin->backupStream($f->stream, $sink, timeoutMs:15000);
                $sink->end();
                $archive = $sink->buffer();
                $target  = $f->stream . '_RESTORED';
                $f->admin->deleteStream($f->stream);
                try {
                    $f->admin->restoreStream($target, new ReadableBuffer($archive), $backup, 'memory', timeoutMs:15000);
                    self::fail('Lost confirmation');
                } catch (JetStreamArchiveException $e) {
                    self::assertTrue($e->isOutcomeUnknown());
                    self::assertSame($target, $e->getStream());
                    self::assertTrue($lost);
                }
                $read = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                self::assertSame(JetStreamPlacementFixture::payload(1), base64_decode($read->get('data'), true));
                $end = hrtime(true) + 5_000_000_000;
                while (!$f->client->getServerInformation()->isCurrentConnection()) {
                    self::assertLessThan($end, hrtime(true));
                    delay(.01);
                }
                self::assertSame($target, $f->admin->getStreamInfo($target)->getName());
                self::assertSame([], array_values(array_filter($frames, static fn($frame)=>$frame['session'] > 0 && (str_contains($frame['subject'], '.STREAM.RESTORE.') || str_contains($frame['subject'], '.SNAPSHOT.RESTORE.')))));
                $eof = array_values(array_filter($frames, static fn($frame)=>str_contains($frame['subject'], '.SNAPSHOT.RESTORE.') && $frame['bytes'] === 0));
                self::assertCount(1, $eof);
                $this->trace('lost-final', ['unknown' => true,'frames' => $frames,'independent_message' => $read->toJson(),'next_info' => 'ok']);
            } finally {
                if ($target !== null) {
                    try {
                                    $reader->admin->deleteStream($target);
                    } catch (\Throwable) {
                    }
                }$reader->close();
                $f->close();
            }
        });
    }
    public function testV2BoundedLargeArchiveAndSlowSink(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $f        = new JetStreamPlacementFixture();
            $reader   = new JetStreamPlacementFixture();
            $target   = $f->stream . '_RESTORED';
            $resource = tmpfile();
            $writable = new WritableResourceStream($resource);
            try {
                $f->admin->createStream(new StreamConfig($f->stream, [$f->subject . '.a'], 'file', 1));
                $hash = hash_init('sha256');
                for ($i = 0; $i < 64; $i++) {
                    $payload = random_bytes(131072);
                    hash_update($hash, $payload);
                    $f->client->request(new PubMessage($f->subject . '.a', $payload), 3);
                }unset($payload);
                $expectedHash = hash_final($hash);
                $memory       = memory_get_usage(true);
                memory_reset_peak_usage();
                $sink   = new \Dorpmaster\Nats\Tests\Support\SlowArchiveSink($writable, .002);
                $backup = $f->admin->backupStream($f->stream, $sink, new BackupOptions(includeConsumers:false, chunkSize:32768, windowSize:65536), 30000);
                $extra  = memory_get_peak_usage(true) - $memory;
                self::assertGreaterThan(8_000_000, $backup->archiveBytes);
                self::assertLessThan(8_000_000, $extra, 'Archive is streamed rather than buffered in full');
                self::assertSame($backup->archiveBytes, ftell($resource));
                rewind($resource);
                $f->admin->deleteStream($f->stream);
                $info = $f->admin->restoreStream($target, new ReadableResourceStream($resource, 131072), StreamBackupMetadata::fromJson($backup->toJson()), 'memory', new RestoreOptions(chunkSize:32768), 30000);
                self::assertSame('64', $info->getState()->messages);
                $actual = hash_init('sha256');
                for ($seq = 1; $seq <= 64; $seq++) {
                    $m = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => $seq]))->getObject('message');
                    hash_update($actual, base64_decode($m->get('data'), true));
                }
                self::assertSame($expectedHash, hash_final($actual));
                $this->trace('large-slow', ['archive_bytes' => $backup->archiveBytes,'window_bytes' => 65536,'chunk_bytes' => 32768,'additional_peak_bytes' => $extra,'messages_read' => 64,'payload_sha256' => $expectedHash,'writes' => $sink->writes]);
            } finally {
                try {
                    $f->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
                $writable->close();
            }
        });
    }

    public function testV2ClusterRecoversConsumerOutsideStreamLeader(): void
    {
        if (getenv('NATS_ARCHIVE_MODE') !== 'cluster') {
            self::markTestSkipped('Run the V2 archive cluster profile');
        }
        $this->setTimeout(60);
        $this->runAsyncTest(function (): void {
            $f      = new JetStreamPlacementFixture();
            $reader = new JetStreamPlacementFixture();
            $target = $f->stream . '_RESTORED';
            try {
                $f->seed(4, ['num_replicas' => 1], 3);
                $result   = $f->pull()->fetch(3, 2000);
                $messages = iterator_to_array($result->messages());
                self::assertCount(3, $messages);
                foreach ([0,1] as $index) {
                    $result->getAcker()->ack($messages[$index]);
                }
                $end = hrtime(true) + 10_000_000_000;
                do {
                    $consumer = $f->admin->getConsumerInfo($f->stream, 'C');
                    if ($consumer->getAckFloor()->streamSequence === '2') {
                        break;
                    }self::assertLessThan($end, hrtime(true));
                    delay(.01);
                } while (true);
                $consumerLeader = $consumer->getCluster()->leader;
                // Move only the R3 stream leader, leaving the R1 consumer on its original node.
                do {
                    $info = $f->admin->getStreamInfo($f->stream);
                    if ($info->getCluster()->leader !== $consumerLeader) {
                        break;
                    }
                    $f->transport->requestJson('STREAM.LEADER.STEPDOWN.' . $f->stream, new JsonObject('{}'));
                    delay(.2);
                    self::assertLessThan($end, hrtime(true));
                } while (true);
                self::assertNotSame($info->getCluster()->leader, $consumerLeader);
                $consumer = $f->admin->getConsumerInfo($f->stream, 'C');
                self::assertSame($consumerLeader, $consumer->getCluster()->leader);
                self::assertSame(1, $consumer->getConfiguration()->get('num_replicas'));
                $sink   = new WritableBuffer();
                $backup = $f->admin->backupStream($f->stream, $sink, new BackupOptions(chunkSize:1024, windowSize:2048), 20000);
                $sink->end();
                $archive = $sink->buffer();
                // Separate no_consumers snapshot while the off-leader durable still exists.
                $withoutSink = new WritableBuffer();
                $without     = $f->admin->backupStream($f->stream, $withoutSink, new BackupOptions(includeConsumers:false), 20000);
                $withoutSink->end();
                $withoutBytes = $withoutSink->buffer();
                $f->admin->deleteStream($f->stream);
                $restored         = $f->admin->restoreStream($target, new ReadableBuffer($archive), StreamBackupMetadata::fromJson($backup->toJson()), 'memory', timeoutMs:20000);
                $restoredConsumer = $reader->admin->getConsumerInfo($target, 'C');
                self::assertSame(1, $restoredConsumer->getConfiguration()->get('num_replicas'));
                self::assertSame('2', $restoredConsumer->getAckFloor()->streamSequence);
                self::assertSame($consumer->getConfiguration()->get('ack_policy'), $restoredConsumer->getConfiguration()->get('ack_policy'));
                $pull    = new JetStreamPullConsumer($reader->transport, $target, 'C');
                $next    = $pull->fetch(1, 3000);
                $message = iterator_to_array($next->messages())[0];
                self::assertSame('3', $message->getMetadata()->streamSequence);
                self::assertSame(JetStreamPlacementFixture::payload(3), $message->getPayload());
                $next->getAcker()->ack($message);
                $end = hrtime(true) + 10_000_000_000;
                do {
                    $acked = $reader->admin->getConsumerInfo($target, 'C');
                    if ($acked->getAckFloor()->streamSequence === '3') {
                        break;
                    }self::assertLessThan($end, hrtime(true));
                    delay(.01);
                } while (true);
                $f->admin->deleteStream($target);
                $f->admin->restoreStream($target, new ReadableBuffer($withoutBytes), $without, 'file', timeoutMs:20000);
                self::assertSame(0, $reader->admin->getStreamInfo($target)->getResponse()->getObject('state')->get('consumer_count'));
                $this->trace('cluster-off-leader-consumer', ['source_stream' => $info->getResponse()->toJson(),'source_consumer' => $consumer->getResponse()->toJson(),'backup' => $backup->toJson(),'restored' => $restored->getResponse()->toJson(),'restored_consumer' => $restoredConsumer->getResponse()->toJson(),'delivered_sequence' => $message->getMetadata()->streamSequence,'acknowledged_consumer' => $acked->getResponse()->toJson(),'without_consumers' => $without->toJson()]);
            } finally {
                try {
                    $f->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
            }
        });
    }

    public function testV2RestoreFromPersistedFilesInAnotherProcess(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $f           = new JetStreamPlacementFixture();
            $reader      = new JetStreamPlacementFixture();
            $target      = $f->stream . '_RESTORED';
            $archivePath = tempnam(sys_get_temp_dir(), 'n9-archive-');
            $metaPath    = tempnam(sys_get_temp_dir(), 'n9-meta-');
            try {
                $f->seed(1);
                $resource = fopen($archivePath, 'wb');
                $sink     = new WritableResourceStream($resource);
                $backup   = $f->admin->backupStream($f->stream, $sink, timeoutMs:15000);
                $sink->end();
                file_put_contents($metaPath, $backup->toJson());
                $f->admin->deleteStream($f->stream);
                $process = proc_open([PHP_BINARY,dirname(__DIR__) . '/Support/restore-archive.php',$target,'memory',$archivePath,$metaPath], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
                fclose($pipes[0]);
                $response = stream_get_contents($pipes[1]);
                $error    = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
                $info = new JsonObject($response);
                self::assertSame($target, $info->getObject('config')->get('name'));
                self::assertSame('memory', $info->getObject('config')->get('storage'));
                $read = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                self::assertSame(JetStreamPlacementFixture::payload(1), base64_decode($read->get('data'), true));
                $this->trace('separate-process', ['metadata_file_json' => $backup->toJson(),'child_final_response' => $response,'independent_read' => $read->toJson()]);
            } finally {
                unlink($archivePath);
                unlink($metaPath);
                try {
                    $f->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
            }
        });
    }

    public static function dispatcherPolicies(): iterable
    {
        yield [\Dorpmaster\Nats\Client\SlowConsumerPolicy::DROP_NEW];
        yield [\Dorpmaster\Nats\Client\SlowConsumerPolicy::ERROR];
    }
    #[DataProvider('dispatcherPolicies')]
    public function testV2PrivateArchiveCannotLoseChunksToApplicationDispatcher(\Dorpmaster\Nats\Client\SlowConsumerPolicy $policy): void
    {
        $this->modern();
        $this->runAsyncTest(function () use ($policy): void {
            $received             = [];
            $acks                 = [];
            $applicationDelivered = 0;
            $f                    = new JetStreamPlacementFixture(onSend:static function ($message) use (&$acks): void {
                if (str_starts_with((string)$message, 'PUB $JS.SNAPSHOT.ACK.')) {
                    $acks[] = strtok((string)$message, "\r\n");
                }
            }, onReceive:static function ($message) use (&$received): void {
                if (method_exists($message, 'getSubject') && str_starts_with($message->getSubject(), '_INBOX.ARCHIVE.')) {
                    $received[] = ['bytes' => strlen($message->getPayload()),'reply' => $message->getReplyTo(),'status' => $message instanceof StatusMessageInterface ? $message->getStatus()?->code : null];
                }
            }, pendingBytesLimit:8192, slowConsumerPolicy:$policy);
            $reader               = new JetStreamPlacementFixture();
            $target               = $f->stream . '_RESTORED';
            try {
                $f->admin->createStream(new StreamConfig($f->stream, [$f->subject . '.a'], 'file', 1));
                $payload = random_bytes(35000);
                $reader->client->request(new PubMessage($f->subject . '.a', $payload), 3);
                // Application SUB still observes DROP_NEW/ERROR; protocol channels own a separate bounded FIFO.
                $coreSubject = 'core.' . $f->subject;
                $sid         = $f->client->subscribe($coreSubject, static function () use (&$applicationDelivered): void {
                    $applicationDelivered++;
                });
                $f->client->publish(new PubMessage($coreSubject, str_repeat('x', 16384)));
                $f->transport->requestJson('INFO', new JsonObject('{}'));
                delay(0);
                self::assertSame(0, $applicationDelivered);
                $f->logs->assertHas($policy === \Dorpmaster\Nats\Client\SlowConsumerPolicy::DROP_NEW ? 'warning' : 'error', $policy === \Dorpmaster\Nats\Client\SlowConsumerPolicy::DROP_NEW ? 'Dropping message due to slow consumer (DROP_NEW policy)' : 'inbound.dispatch.callback_error');
                $f->client->unsubscribe($sid);
                $sink     = new WritableBuffer();
                $metadata = $f->admin->backupStream($f->stream, $sink, new BackupOptions(chunkSize:16384, windowSize:65536), 15000);
                $sink->end();
                $archive = $sink->buffer();
                self::assertGreaterThan(32768, strlen($archive));
                self::assertSame(strlen($archive), $metadata->archiveBytes);
                self::assertCount(2, $acks);
                $f->admin->deleteStream($f->stream);
                $f->admin->restoreStream($target, new ReadableBuffer($archive), StreamBackupMetadata::fromJson($metadata->toJson()), 'memory', timeoutMs:15000);
                $actual = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                self::assertSame($payload, base64_decode($actual->get('data'), true));
                $next = json_decode($f->client->request(new PubMessage($f->subject . '.a', 'next'), 3)->getPayload(), true);
                self::assertSame(2, $next['seq']);
                $this->trace('dispatcher-' . $policy->name, ['policy' => $policy->name,'application_limit' => 8192,'application_delivered' => $applicationDelivered,'received' => $received,'acks' => $acks,'archive_bytes' => strlen($archive),'restored_payload_sha256' => hash('sha256', $payload),'independent_read_matches' => true,'next_ack' => $next]);
            } finally {
                try {
                    $f->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
            }
        });
    }
    public function testV2InvalidSnapshotSubjectsAndPersistedMetadataCannotStartRestore(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $wire     = [];
            $injected = false;
            $f        = new JetStreamPlacementFixture(onSend:static function ($m) use (&$wire): void {
                $wire[] = (string)$m;
            }, onReceive:static function ($m) use (&$injected) {
                if (method_exists($m, 'getPayload') && str_contains($m->getPayload(), '"type":"io.nats.jetstream.api.v1.stream_snapshot_response"')) {
                    $raw      = new JsonObject($m->getPayload());
                    $config   = $raw->getObject('config')->withFields(['subjects' => null]);
                    $payload  = '{"type":"io.nats.jetstream.api.v1.stream_snapshot_response","config":' . $config->toJson() . ',"state":' . $raw->getObject('state')->toJson() . '}';
                    $injected = true;
                    return new \Dorpmaster\Nats\Protocol\MsgMessage($m->getSubject(), $m->getSid(), $payload, $m->getReplyTo());
                }
                return null;
            });
            $reader   = new JetStreamPlacementFixture();
            $target   = $f->stream . '_RESTORED';
            try {
                $f->seed(1);
                $sink = new WritableBuffer();
                $good = $reader->admin->backupStream($f->stream, $sink, timeoutMs:10000);
                $sink->end();
                $archive = $sink->buffer();
                $badSink = new WritableBuffer();
                try {
                    $f->admin->backupStream($f->stream, $badSink, timeoutMs:10000);
                    self::fail('Invalid routing');
                } catch (JetStreamArchiveException $e) {
                    self::assertStringContainsString('subjects', $e->getMessage());
                    self::assertSame(0, $e->getTransferredBytes());
                    self::assertTrue($badSink->isWritable());
                }
                self::assertTrue($injected);
                $badConfig = $good->config->withFields(['subjects' => null]);
                $badJson   = '{"type":"dorpmaster.nats.backup.v2","stream":' . json_encode($good->stream) . ',"config":' . $badConfig->toJson() . ',"state":' . $good->state->toJson() . ',"archive_bytes":' . $good->archiveBytes . '}';
                try {
                    $bad = StreamBackupMetadata::fromJson($badJson);
                    $f->admin->restoreStream($target, new ReadableBuffer($archive), $bad, 'memory');
                    self::fail('Bad metadata');
                } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException $e) {
                    self::assertStringContainsString('subjects', $e->getMessage());
                }
                self::assertSame(0, count(array_filter($wire, static fn($frame)=>str_starts_with($frame, 'HPUB $JS.API.STREAM.RESTORE.'))));
                $f->admin->deleteStream($f->stream);
                $reader->admin->restoreStream($target, new ReadableBuffer($archive), $good, 'memory', timeoutMs:10000);
                $old = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                self::assertSame(JetStreamPlacementFixture::payload(1), base64_decode($old->get('data'), true));
                $next = json_decode($f->client->request(new PubMessage($f->subject . '.a', 'new route message'), 3)->getPayload(), true);
                self::assertSame($target, $next['stream']);
                self::assertSame(2, $next['seq']);
                self::assertSame([$f->subject . '.*'], $reader->admin->getStreamInfo($target)->getSubjects());
                $this->trace('invalid-subjects', ['snapshot_rejected' => true,'persisted_rejected' => true,'restore_attempts_invalid' => 0,'old_message' => $old->toJson(),'new_ack' => $next,'subjects' => $reader->admin->getStreamInfo($target)->getSubjects()]);
            } finally {
                try {
                    $reader->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
            }
        });
    }
    public function testV2MalformedFinalErrorKeepsRestoredOutcomeUnknown(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $target      = null;
            $replacement = null;
            $original    = null;
            $frames      = [];
            $f           = new JetStreamPlacementFixture(onSend:static function ($m) use (&$frames): void {
                if (preg_match('/\A(?:H?PUB) (\S+)/', (string)$m, $match)) {
                    $frames[] = ['subject' => $match[1],'bytes' => strlen($m->getPayload())];
                }
            }, onReceive:static function ($m) use (&$target, &$replacement, &$original) {
                if ($replacement !== null && method_exists($m, 'getPayload')) {
                    $raw = json_decode($m->getPayload(), true);
                    if (($raw['type'] ?? null) === 'io.nats.jetstream.api.v1.stream_create_response' && ($raw['config']['name'] ?? null) === $target) {
                        $original = $m->getPayload();
                        return new \Dorpmaster\Nats\Protocol\MsgMessage($m->getSubject(), $m->getSid(), json_encode(['type' => $raw['type'],'error' => $replacement], JSON_THROW_ON_ERROR));
                    }
                }
                return null;
            });
            $reader      = new JetStreamPlacementFixture();
            $evidence    = [];
            try {
                $f->seed(1);
                $sink     = new WritableBuffer();
                $metadata = $f->admin->backupStream($f->stream, $sink, timeoutMs:10000);
                $sink->end();
                $archive = $sink->buffer();
                $f->admin->deleteStream($f->stream);
                foreach (['missing' => null,'string' => '400','success' => 200] as $mode => $code) {
                    $target      = $f->stream . '_' . strtoupper($mode);
                    $replacement = ['err_code' => 10003,'description' => 'synthetic final error'];
                    if ($mode !== 'missing') {
                        $replacement['code'] = $code;
                    }
                    $original = null;
                    $frames   = [];
                    try {
                        $f->admin->restoreStream($target, new ReadableBuffer($archive), $metadata, 'memory', timeoutMs:10000);
                        self::fail('Cannot confirm malformed error');
                    } catch (JetStreamArchiveException $e) {
                        self::assertTrue($e->isOutcomeUnknown());
                        self::assertSame($mode === 'success' ? 200 : 0, $e->getApiCode());
                        self::assertSame(10003, $e->getErrorCode());
                        self::assertSame('synthetic final error', $e->getDescription());
                        $failure = ['unknown' => $e->isOutcomeUnknown(),'code' => $e->getApiCode(),'err_code' => $e->getErrorCode()];
                    }
                    self::assertNotNull($original);
                    $actual = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                    self::assertSame(JetStreamPlacementFixture::payload(1), base64_decode($actual->get('data'), true));
                    self::assertCount(1, array_filter($frames, static fn($frame)=>$frame['subject'] === '$JS.API.STREAM.RESTORE.' . $target));
                    self::assertCount(1, array_filter($frames, static fn($frame)=>str_starts_with($frame['subject'], '$JS.SNAPSHOT.RESTORE.' . $target . '.') && $frame['bytes'] === 0));
                    $evidence[$mode] = ['original_final' => $original,'replacement' => $replacement,'exception' => $failure,'frames' => $frames,'independent_message' => $actual->toJson()];
                    $reader->admin->deleteStream($target);
                    $target = null;
                }
                $this->trace('malformed-final-errors', $evidence);
            } finally {
                if ($target !== null) {
                    try {
                                    $reader->admin->deleteStream($target);
                    } catch (\Throwable) {
                    }
                }$reader->close();
                $f->close();
            }
        });
    }
    public function testV2ClosedSourceIsRejectedBeforeAnySessionStarts(): void
    {
        $this->modern();
        $this->runAsyncTest(function (): void {
            $frames = [];
            $f      = new JetStreamPlacementFixture(onSend:static function ($m) use (&$frames): void {
                $frames[] = (string)$m;
            });
            $reader = new JetStreamPlacementFixture();
            $target = $f->stream . '_RESTORED';
            try {
                $f->seed(1);
                $sink     = new WritableBuffer();
                $metadata = $f->admin->backupStream($f->stream, $sink, timeoutMs:10000);
                $sink->end();
                $archive = $sink->buffer();
                $input   = new ReadableBuffer($archive);
                $input->close();
                self::assertFalse($input->isReadable());
                $frames = [];
                try {
                    $f->admin->restoreStream($target, $input, $metadata, 'memory');
                    self::fail('Closed source');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('input', $e->getMessage());
                }
                self::assertSame([], $frames);
                $f->admin->deleteStream($f->stream);
                $frames = [];
                $f->admin->restoreStream($target, new ReadableBuffer($archive), $metadata, 'memory', timeoutMs:10000);
                self::assertCount(1, array_filter($frames, static fn($frame)=>str_starts_with($frame, 'HPUB $JS.API.STREAM.RESTORE.')));
                $actual = $reader->transport->requestJson('STREAM.MSG.GET.' . $target, JsonObject::fromArray(['seq' => 1]))->getObject('message');
                self::assertSame(JetStreamPlacementFixture::payload(1), base64_decode($actual->get('data'), true));
                $this->trace('closed-input', ['local_rejection' => true,'closed_source_requests' => 0,'closed_source_subscriptions' => 0,'control_restore_starts' => 1,'control_read' => $actual->toJson()]);
            } finally {
                try {
                    $reader->admin->deleteStream($target);
                } catch (\Throwable) {
                }$reader->close();
                $f->close();
            }
        });
    }
    private function trace(string $name, array $data): void
    {
        if ($path = getenv('NATS_ARCHIVE_TRACE_DIR')) {
            file_put_contents($path . '/' . $name . '.json', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
}
