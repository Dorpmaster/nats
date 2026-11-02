<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Capability\Support;
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Message\JetStreamMessageInterface;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Pull\JetStreamPullConsumer;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamRawPubMessage;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\JetStreamResetFixture;
use Dorpmaster\Nats\Tests\Support\WeakConsumerResetCaller;
use Dorpmaster\Nats\Tests\Support\RecordingJetStreamResetTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class JetStreamConsumerResetIntegrationTest extends TestCase
{
    use AsyncTestTools;

    public static function prefixes(): iterable
    {
        yield 'local known support' => ['$JS.API', false];
        yield 'domain unknown target' => ['$JS.EU.API', false];
        yield 'import unknown target' => ['IMPORT@EU+API', true];
    }

    public function testKnownUnsupportedIsLocalOn2126(): void
    {
        $this->requireMatrix();
        $this->setTimeout(15);
        $this->runAsyncTest(function (): void {
            $fixture = new JetStreamResetFixture();
            try {
                $fixture->seed(3);
                self::assertSame('2.12.6', $fixture->client->getServerInformation()->version);
                self::assertSame(3, $fixture->client->getServerInformation()->apiLevel);
                self::assertSame(Support::UNSUPPORTED, $fixture->admin->getCapabilities()->check(Feature::CONSUMER_RESET)->support);
                try {
                    $fixture->admin->resetConsumer($fixture->stream, 'C');
                    self::fail('Expected local unsupported');
                } catch (JetStreamApiException $error) {
                    self::assertSame(0, $error->getApiCode());
                    self::assertStringContainsString('unsupported', $error->getMessage());
                }
                self::assertSame([], $fixture->transport->records);
                self::assertSame('C', $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getName());
                $this->trace('unsupported', $fixture, []);
            } finally {
                $fixture->close();
            }
        });
    }

    #[DataProvider('prefixes')]
    public function testResetAckFloorAndPrefix(string $prefix, bool $imported): void
    {
        $this->requireMatrix();
        $this->setTimeout(20);
        $this->runAsyncTest(function () use ($prefix, $imported): void {
            $fixture = new JetStreamResetFixture();
            $remote  = null;
            try {
                $fixture->seed(4);
                $created  = $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getCreated()->getRfc3339();
                $result   = $fixture->pull()->fetch(3, 2000);
                $messages = iterator_to_array($result->messages());
                self::assertCount(3, $messages);
                foreach ([0, 1] as $index) {
                    $this->assertMessage($messages[$index], (string) ($index + 1), (string) ($index + 1));
                    $result->getAcker()->ack($messages[$index]);
                }
                $this->assertMessage($messages[2], '3', '3');
                $this->waitFor(static fn (): bool => $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getAckFloor()->streamSequence === '2');
                $client = $fixture->client;
                if ($imported) {
                    $remote    = new JetStreamResetFixture($prefix, true);
                    $client    = $remote->client;
                    $transport = $remote->transport;
                } else {
                    $transport = new RecordingJetStreamResetTransport(new JetStreamControlPlaneTransport($client, apiPrefix: $prefix));
                }
                $admin = new JetStreamAdmin($transport);
                self::assertSame($prefix === '$JS.API' ? Support::SUPPORTED : Support::UNKNOWN, $admin->getCapabilities()->check(Feature::CONSUMER_RESET)->support);
                if ($imported && getenv('NATS_EXPECTED_VERSION') === '2.14.0') {
                    // This server applies RESET but does not forward its reply through this service import.
                    try {
                        $admin->resetConsumer($fixture->stream, 'C');
                        self::fail('Expected the observed NATS 2.14.0 service-import reply limitation');
                    } catch (\Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException $error) {
                        self::assertNotNull($error->getPrevious());
                    }
                    self::assertCount(1, $transport->records);
                    $observed = $fixture->admin->getConsumerInfo($fixture->stream, 'C');
                    self::assertSame('0', $observed->getDelivered()->consumerSequence);
                    self::assertSame('2', $observed->getAckFloor()->streamSequence);
                    self::assertSame($created, $remote->admin->getConsumerInfo($fixture->stream, 'C')->getCreated()->getRfc3339());
                    $next = $this->one($fixture->pull());
                    $this->assertMessage($next, '3', '1');
                    $this->trace('import-uncertain-reply', $fixture, ['requests' => $transport->records, 'timeout_after_applied_reset' => true, 'server_state' => $observed->getResponse()->toJson(), 'after' => $this->metadata($next)]);
                    return;
                }
                $reset = $admin->resetConsumer($fixture->stream, 'C');
                self::assertSame('3', $reset->getResetSequence());
                self::assertSame('0', $reset->getConsumerInfo()->getDelivered()->consumerSequence);
                self::assertSame('2', $reset->getConsumerInfo()->getAckFloor()->streamSequence);
                self::assertSame($created, $reset->getConsumerInfo()->getCreated()->getRfc3339());
                self::assertSame('{}', $transport->records[0]['body']);
                self::assertSame($prefix . '.CONSUMER.RESET.' . $fixture->stream . '.C', $transport->records[0]['subject']);
                self::assertCount(1, $transport->records);
                $next = $this->one($fixture->pull());
                $this->assertMessage($next, '3', '1');
                $this->trace('floor-' . str_replace(['$', '.', '@', '+'], '_', $prefix), $fixture, ['before' => array_map($this->metadata(...), $messages), 'after' => $this->metadata($next), 'requests' => $transport->records]);
                self::assertSame('C', $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getName());
            } finally {
                $remote?->close();
                $fixture->close();
            }
        });
    }

    public static function callerValues(): iterable
    {
        foreach ([false, true] as $weak) {
            foreach (['1.5', 'true', 'false', '"1.5"', 'null', '0', '"0002"'] as $json) {
                yield ($weak ? 'weak ' : 'strict ') . $json => [$weak, $json];
            }
        }
    }

    #[DataProvider('callerValues')]
    public function testResetCallerTypesPreserveDeliveryOnLocalRejection(bool $weak, string $json): void
    {
        $this->requireMatrix();
        $this->setTimeout(15);
        $this->runAsyncTest(function () use ($weak, $json): void {
            $fixture = new JetStreamResetFixture();
            try {
                $fixture->seed(5);
                $result   = $fixture->pull()->fetch(3, 2000);
                $messages = iterator_to_array($result->messages());
                self::assertCount(3, $messages);
                foreach ($messages as $message) {
                    $result->getAcker()->ack($message);
                }
                $this->waitFor(static fn (): bool => $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getAckFloor()->streamSequence === '3');
                $before = $fixture->admin->getConsumerInfo($fixture->stream, 'C');
                $value  = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
                $error  = null;
                $reset  = null;
                try {
                    $reset = $weak ? WeakConsumerResetCaller::reset($fixture->admin, $fixture->stream, 'C', $value) : $fixture->admin->resetConsumer($fixture->stream, 'C', $value);
                } catch (\Throwable $caught) {
                    $error = $caught;
                }
                $after = $fixture->admin->getConsumerInfo($fixture->stream, 'C');
                $next  = $this->one($fixture->pull());
                $this->trace('caller-' . ($weak ? 'weak-' : 'strict-') . bin2hex($json), $fixture, ['input_json' => $json, 'input_type' => get_debug_type($value), 'error' => $error === null ? null : ['class' => $error::class, 'message' => $error->getMessage()], 'returned' => $reset?->getResetSequence(), 'ack_floor_before' => $before->getAckFloor()->streamSequence, 'ack_floor_after' => $after->getAckFloor()->streamSequence, 'next' => $this->metadata($next)]);
                if (in_array($json, ['1.5', 'true', 'false', '"1.5"'], true)) {
                    self::assertInstanceOf(\InvalidArgumentException::class, $error);
                    self::assertStringContainsString('streamSequence', $error->getMessage());
                    self::assertSame([], $fixture->transport->records);
                    self::assertSame('3', $after->getAckFloor()->streamSequence);
                    $this->assertMessage($next, '4', '4');
                } else {
                    self::assertNull($error);
                    $sequence = $json === '"0002"' ? '2' : '4';
                    self::assertSame($sequence, $reset->getResetSequence());
                    self::assertCount(1, $fixture->transport->records);
                    self::assertSame($json === '"0002"' ? '{"seq":2}' : '{}', $fixture->transport->records[0]['body']);
                    $this->assertMessage($next, $sequence, '1');
                }
            } finally {
                $fixture->close();
            }
        });
    }

    public function testResetResourceNamesPreserved(): void
    {
        $this->requireMatrix();
        $this->setTimeout(15);
        $this->runAsyncTest(function (): void {
            foreach (['ORDERS@EU', 'ORDERS+EU'] as $stream) {
                $fixture         = new JetStreamResetFixture();
                $fixture->stream = $stream;
                try {
                    $fixture->seed(0);
                    $fixture->createConsumer('WORKER@EU');
                    $result = $fixture->admin->resetConsumer($stream, 'WORKER@EU');
                    self::assertSame('1', $result->getResetSequence());
                    self::assertSame($stream, $result->getConsumerInfo()->getStreamName());
                    self::assertSame('WORKER@EU', $result->getConsumerInfo()->getName());
                    self::assertCount(1, $fixture->transport->records);
                    self::assertSame('$JS.API.CONSUMER.RESET.' . $stream . '.WORKER@EU', $fixture->transport->records[0]['subject']);
                    self::assertSame('WORKER@EU', $fixture->admin->getConsumerInfo($stream, 'WORKER@EU')->getName());
                    $this->trace('resource-' . str_replace(['@', '+'], ['_at_', '_plus_'], $stream), $fixture, []);
                } finally {
                    $fixture->close();
                }
            }
        });
    }

    public function testResetBackwardForwardDeletedFilteredAndUInt64(): void
    {
        $this->requireMatrix();
        $this->setTimeout(20);
        $this->runAsyncTest(function (): void {
            $fixture = new JetStreamResetFixture();
            try {
                $fixture->seed();
                self::assertSame('limits', $fixture->admin->getStreamInfo($fixture->stream)->getConfiguration()->get('retention'));
                $first = $this->one($fixture->pull());
                $this->ackAndConfirmState($fixture, $first);
                $deliveries = [];
                foreach ([2, 1, 5] as $position) {
                    self::assertSame((string) $position, $fixture->admin->resetConsumer($fixture->stream, 'C', $position)->getResetSequence());
                    $message = $this->one($fixture->pull());
                    $this->assertMessage($message, (string) $position, '1');
                    $deliveries[] = $this->metadata($message);
                }
                $fixture->createConsumer('F', ['filter_subject' => $fixture->subject . '.a']);
                self::assertSame('2', $fixture->admin->resetConsumer($fixture->stream, 'F', 2)->getResetSequence());
                $filtered = $this->one($fixture->pull('F'));
                $this->assertMessage($filtered, '3', '1');
                $fixture->transport->requestJson('STREAM.MSG.DELETE.' . $fixture->stream, new JsonObject('{"seq":3}'));
                self::assertSame('3', $fixture->admin->resetConsumer($fixture->stream, 'F', 3)->getResetSequence());
                $deleted = $this->one($fixture->pull('F'));
                $this->assertMessage($deleted, '5', '1');
                $large = $fixture->admin->resetConsumer($fixture->stream, 'C', '9223372036854775808');
                self::assertSame('9223372036854775808', $large->getResetSequence());
                self::assertSame('9223372036854775807', $large->getConsumerInfo()->getAckFloor()->streamSequence);
                self::assertSame(0, $fixture->pull()->fetch(1, 2000, true)->getReceivedCount());
                $fixture->admin->resetConsumer($fixture->stream, 'C', 1);
                $this->assertMessage($this->one($fixture->pull()), '1', '1');
                $this->trace('positions', $fixture, ['deliveries' => $deliveries, 'filtered' => $this->metadata($filtered), 'deleted' => $this->metadata($deleted)]);
            } finally {
                $fixture->close();
            }
        });
    }

    public function testResetPolicyRestrictionKeepsStateAndErrorCodes(): void
    {
        $this->requireMatrix();
        $this->setTimeout(20);
        $this->runAsyncTest(function (): void {
            $fixture = new JetStreamResetFixture();
            try {
                $fixture->seed(6, ['deliver_policy' => 'by_start_sequence', 'opt_start_seq' => 3]);
                $this->assertMessage($this->one($fixture->pull()), '3', '1');
                $before = $fixture->admin->getConsumerInfo($fixture->stream, 'C');
                try {
                    $fixture->admin->resetConsumer($fixture->stream, 'C', 2);
                    self::fail('Cannot reset below configured start');
                } catch (JetStreamApiException $error) {
                    self::assertSame(400, $error->getApiCode());
                    self::assertSame(10204, $error->getErrorCode());
                    self::assertSame('invalid reset: below start seq', $error->getDescription());
                }
                $after = $fixture->admin->getConsumerInfo($fixture->stream, 'C');
                self::assertSame($before->getCreated()->getRfc3339(), $after->getCreated()->getRfc3339());
                self::assertSame($before->getConfiguration()->toJson(), $after->getConfiguration()->toJson());
                self::assertSame($before->getDelivered()->streamSequence, $after->getDelivered()->streamSequence);
                self::assertSame($before->getDelivered()->consumerSequence, $after->getDelivered()->consumerSequence);
                self::assertSame($before->getAckFloor()->streamSequence, $after->getAckFloor()->streamSequence);
                self::assertSame($before->getAckPendingCount(), $after->getAckPendingCount());
                self::assertSame('3', $fixture->admin->resetConsumer($fixture->stream, 'C', 3)->getResetSequence());
                $this->assertMessage($this->one($fixture->pull()), '3', '1');
                $fixture->createConsumer('NEW', ['deliver_policy' => 'new']);
                try {
                    $fixture->admin->resetConsumer($fixture->stream, 'NEW', 1);
                    self::fail('Explicit reset incompatible with DeliverNew');
                } catch (JetStreamApiException $error) {
                    self::assertSame(10204, $error->getErrorCode());
                    self::assertSame('invalid reset: not allowed', $error->getDescription());
                }
                self::assertSame('7', $fixture->admin->resetConsumer($fixture->stream, 'NEW')->getResetSequence());
                $this->trace('policy', $fixture, ['before' => $before->getResponse()->toJson(), 'after_rejection' => $after->getResponse()->toJson()]);
            } finally {
                $fixture->close();
            }
        });
    }

    public function testResetAffectsWaitingSecondClientAndPreviouslyIssuedAck(): void
    {
        $this->requireMatrix();
        $this->setTimeout(20);
        $this->runAsyncTest(function (): void {
            $fixture = new JetStreamResetFixture();
            $second  = new JetStreamResetFixture();
            try {
                $fixture->seed(2);
                $pull   = new JetStreamPullConsumer($second->transport, $fixture->stream, 'C');
                $before = iterator_to_array($pull->fetch(2, 2000)->messages());
                self::assertCount(2, $before);
                $old     = $before[1];
                $waiting = async(static fn () => $pull->fetch(1, 3000));
                $this->waitFor(static fn (): bool => $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getWaitingCount() === '1');
                self::assertSame('1', $fixture->admin->resetConsumer($fixture->stream, 'C', 1)->getResetSequence());
                $first = iterator_to_array($waiting->await()->messages())[0];
                $this->assertMessage($first, '1', '1');
                $next = $this->one($pull);
                $this->assertMessage($next, '2', '2');
                self::assertSame($old->getReplyTo(), $next->getReplyTo(), 'Classic ACK may be identical after reset');
                // A previously issued ACK can acknowledge the new delivery of the same stored record.
                $this->ackAndConfirmState($second, $old);
                self::assertSame('1', $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getAckPendingCount());
                $this->ackAndConfirmState($second, $first);
                $this->waitFor(static fn (): bool => $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getAckPendingCount() === '0');
                self::assertSame('2', $fixture->admin->getConsumerInfo($fixture->stream, 'C')->getAckFloor()->streamSequence);
                $this->trace('shared-consumer', $fixture, ['old' => $this->metadata($old), 'waiting_pull' => $this->metadata($first), 'next' => $this->metadata($next), 'old_ack_affected_new_delivery' => true]);
            } finally {
                $second->close();
                $fixture->close();
            }
        });
    }

    public function testResetReplicatedStateSurvivesLeaderChange(): void
    {
        if (getenv('NATS_RESET_CLUSTER') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-reset-cluster.');
        }
        $this->setTimeout(60);
        $this->runAsyncTest(function (): void {
            $fixture = new JetStreamResetFixture();
            try {
                $fixture->seed(4, replicas: 3);
                $fixture->admin->resetConsumer($fixture->stream, 'C', 2, 5000);
                $before = $fixture->admin->getConsumerInfo($fixture->stream, 'C');
                $leader = $before->getCluster()->leader;
                self::assertNotEmpty($leader);
                self::assertSame(3, $before->getConfiguration()->get('num_replicas'));
                self::assertCount(2, $before->getCluster()->replicas);
                self::assertSame('2.15.2', $fixture->client->getServerInformation()->version);
                self::assertSame('1', $before->getAckFloor()->streamSequence);
                self::assertSame('0', $before->getAckFloor()->consumerSequence);
                $fixture->transport->requestJson('CONSUMER.LEADER.STEPDOWN.' . $fixture->stream . '.C', new JsonObject('{}'));
                $after = null;
                $this->waitFor(static function () use ($fixture, $leader, &$after): bool {
                    try {
                        $after = $fixture->admin->getConsumerInfo($fixture->stream, 'C');
                        return $after->getCluster()->leader !== null && $after->getCluster()->leader !== $leader;
                    } catch (JetStreamApiException) {
                        return false;
                    }
                }, 20);
                self::assertSame('1', $after->getAckFloor()->streamSequence);
                self::assertSame('0', $after->getAckFloor()->consumerSequence);
                $message = $this->one($fixture->pull());
                $this->assertMessage($message, '2', '1');
                $this->ackAndConfirmState($fixture, $message);
                self::assertSame('3', $fixture->admin->resetConsumer($fixture->stream, 'C', timeoutMs: 5000)->getResetSequence());
                $this->assertMessage($this->one($fixture->pull()), '3', '1');
                self::assertCount(2, $fixture->transport->records);
                $this->trace('replicated-leader', $fixture, ['before' => $before->getResponse()->toJson(), 'after_leader_change' => $after->getResponse()->toJson(), 'new_delivery' => $this->metadata($message)]);
            } finally {
                $fixture->close();
            }
        });
    }

    private function requireMatrix(): void
    {
        if (getenv('NATS_RESET') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-reset.');
        }
    }

    private function one(JetStreamPullConsumer $pull): JetStreamMessageInterface
    {
        $messages = iterator_to_array($pull->fetch(1, 3000)->messages());
        self::assertCount(1, $messages);
        return $messages[0];
    }

    private function assertMessage(JetStreamMessageInterface $message, string $streamSequence, string $consumerSequence): void
    {
        self::assertNotNull($message->getMetadata());
        self::assertSame($streamSequence, $message->getMetadata()->streamSequence);
        self::assertSame($consumerSequence, $message->getMetadata()->consumerSequence);
        self::assertSame(JetStreamResetFixture::payload((int) $streamSequence), $message->getPayload());
    }

    private function ackAndConfirmState(JetStreamResetFixture $fixture, JetStreamMessageInterface $message): void
    {
        $metadata = $message->getMetadata();
        $pending  = $fixture->admin->getConsumerInfo($metadata->stream, $metadata->consumer)->getAckPendingCount();
        $fixture->client->publish(new JetStreamRawPubMessage((string) $message->getReplyTo(), '+ACK'));
        $this->waitFor(static fn (): bool => $fixture->admin->getConsumerInfo($metadata->stream, $metadata->consumer)->getAckPendingCount() === (string) ((int) $pending - 1));
    }

    /** @return array<string, mixed> */
    private function metadata(JetStreamMessageInterface $message): array
    {
        return ['stream_seq' => $message->getMetadata()->streamSequence, 'consumer_seq' => $message->getMetadata()->consumerSequence, 'delivery_count' => $message->getMetadata()->deliveryCount, 'reply' => $message->getReplyTo(), 'payload_hex' => bin2hex($message->getPayload())];
    }

    private function waitFor(\Closure $predicate, int $seconds = 5): void
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        while (!$predicate()) {
            self::assertLessThan($deadline, hrtime(true));
            delay(0.01);
        }
    }

    /** @param array<string, mixed> $observations */
    private function trace(string $name, JetStreamResetFixture $fixture, array $observations): void
    {
        self::assertStringNotContainsString('synthetic-n3-password', serialize($fixture->logs));
        self::assertStringNotContainsString('synthetic-n3-message-', serialize($fixture->logs));
        $directory = getenv('NATS_RESET_TRACE_DIR');
        if ($directory !== false && $directory !== '') {
            file_put_contents($directory . '/' . $name . '.json', json_encode(['server_version' => $fixture->client->getServerInformation()->version, 'api_level' => $fixture->client->getServerInformation()->apiLevel, 'requests' => $fixture->transport->records, 'observations' => $observations], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }
}
