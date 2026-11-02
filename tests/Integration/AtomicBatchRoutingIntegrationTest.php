<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\AtomicBatchException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchCommitMode;
use Dorpmaster\Nats\Domain\JetStream\Publish\AtomicBatchOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\AtomicBatchProbeClient;
use Dorpmaster\Nats\Tests\Support\JetStreamResetFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtomicBatchRoutingIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function targets(): iterable
    {
        yield 'remote stream only' => [false, false];
        yield 'local namesake with a different subject' => [true, false];
        yield 'stable subject mapping to old domain' => [true, true];
    }

    #[DataProvider('targets')]
    public function testLeafRouteCannotUseConnectedServersCapabilities(bool $localNamesake, bool $mapped): void
    {
        if (getenv('NATS_ATOMIC_ROUTES') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-atomic-routes.');
        }
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($localNamesake, $mapped): void {
            $originalHost = getenv('NATS_HOST');
            putenv('NATS_HOST=hub');
            $reader = new JetStreamResetFixture();
            putenv('NATS_HOST=leaf');
            $writer       = new JetStreamResetFixture();
            $localReader  = new JetStreamResetFixture();
            $stream       = 'N4_ROUTE_' . strtoupper(bin2hex(random_bytes(5)));
            $subject      = $mapped ? 'review.route' : 'n4.route.' . bin2hex(random_bytes(5));
            $wireSubject  = $mapped ? 'wire.route' : $subject;
            $old          = getenv('NATS_ATOMIC_TARGET_VERSION') === '2.10.26';
            $created      = false;
            $localCreated = false;
            try {
                $reader->admin->createStream(new StreamConfig($stream, [$wireSubject], 'memory', allowAtomicPublish: $old ? null : true));
                $created = true;
                if ($localNamesake) {
                    $writer->admin->createStream(new StreamConfig($stream, [$mapped ? $subject : $subject . '.local'], 'memory', allowAtomicPublish: true));
                    $localCreated = true;
                }
                $remote = new JetStreamAdmin(new JetStreamControlPlaneTransport($writer->client, apiPrefix: '$JS.OLD.API'));
                self::assertSame('OLD', $remote->getStreamInfo($stream)->getDomain());
                self::assertSame('2.15.2', $writer->client->getServerInformation()->version);
                self::assertSame(getenv('NATS_ATOMIC_TARGET_VERSION'), $reader->client->getServerInformation()->version);
                self::assertSame('0', $reader->admin->getStreamInfo($stream)->getState()->messages);
                $probe    = new AtomicBatchProbeClient($writer->client);
                $messages = [['subject' => $localNamesake && !$mapped ? $subject . '.local' : $subject, 'payload' => 'first'], ['subject' => $subject, 'payload' => 'second']];
                try {
                    (new JetStreamPublisher($probe))->publishAtomicBatch($stream, $messages, new AtomicBatchOptions($old ? AtomicBatchCommitMode::MESSAGE : AtomicBatchCommitMode::EOB));
                    self::fail('Unknown remote route must fail before any HPUB');
                } catch (AtomicBatchException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertNotNull($error->getPrevious());
                    $failure = $error->getDescription();
                }
                self::assertSame([], $probe->records);
                self::assertSame('0', $reader->admin->getStreamInfo($stream)->getState()->messages);
                if ($localCreated) {
                    self::assertSame('0', $localReader->admin->getStreamInfo($stream)->getState()->messages);
                }
                foreach ($localCreated ? [$reader, $localReader] : [$reader] as $independent) {
                    try {
                        $independent->transport->requestJson('STREAM.MSG.GET.' . $stream, new JsonObject('{"seq":1}'));
                        self::fail('No first message may be stored');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(404, $error->getApiCode());
                        self::assertSame(10037, $error->getErrorCode());
                    }
                }
                self::assertSame('0', $remote->getStreamInfo($stream)->getState()->messages, 'Next request on the same leaf connection succeeds');
                $directory = getenv('NATS_ATOMIC_TRACE_DIR');
                if ($directory) {
                    file_put_contents($directory . '/route-' . ($mapped ? 'mapped' : ($localNamesake ? 'namesake' : 'remote')) . '.json', json_encode(['connected' => '2.15.2', 'target' => getenv('NATS_ATOMIC_TARGET_VERSION'), 'checks' => $probe->checks, 'batch_frames' => $probe->records, 'stored' => 0, 'local_stored' => $localCreated ? $localReader->admin->getStreamInfo($stream)->getState()->messages : null, 'mapped' => $mapped, 'error' => $failure, 'next_request_success' => true], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                }
            } finally {
                try {
                    if ($created) {
                        $reader->admin->deleteStream($stream);
                    }
                    if ($localCreated) {
                        $writer->admin->deleteStream($stream);
                    }
                } finally {
                    $reader->close();
                    $writer->close();
                    $localReader->close();
                    putenv($originalHost === false ? 'NATS_HOST' : 'NATS_HOST=' . $originalHost);
                }
            }
        });
    }
}
