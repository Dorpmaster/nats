<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Integration;

use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\MessageSchedule;
use Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleRollup;
use Dorpmaster\Nats\Domain\JetStream\Exception\ScheduledPublishException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Tests\Support\AsyncTestTools;
use Dorpmaster\Nats\Tests\Support\ScheduleFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamScheduleIntegrationTest extends TestCase
{
    use AsyncTestTools;

    private function enabled(): void
    {
        if (getenv('NATS_SCHEDULES') !== '1') {
            self::markTestSkipped('Run make integration-jetstream-schedules.');
        }
        $this->setTimeout(25);
    }
    private function publish(ScheduleFixture $f, MessageSchedule $schedule, string $payload = 'body', string|null $source = null, int|null $ttl = null, ScheduleRollup|null $rollup = null): \Dorpmaster\Nats\Domain\JetStream\Model\PubAck
    {
        return (new JetStreamPublisher($f->probe))->publish($f->definition, $payload, new PublishOptions(headers:['X-Trace' => 'synthetic-N6'], schedule:new ScheduleOptions($f->stream, $f->target, $schedule, $source, $ttl, $rollup)), 5000);
    }
    private function advanced(ScheduleFixture $f, \Closure $operation): bool
    {
        if (getenv('NATS_EXPECTED_VERSION') !== '2.12.6') {
            return true;
        }
        try {
            $operation();
            self::fail('Extended schedule must not be sent');
        } catch (ScheduledPublishException $error) {
            self::assertFalse($error->isOutcomeUnknown());
        }
        self::assertSame([], $f->probe->records);
        self::assertSame([], $f->rows($f->definition));
        self::assertSame([], $f->rows($f->target));
        $this->trace('old-refusal-' . bin2hex(random_bytes(3)), $f, ['data_frames' => 0,'definitions' => 0]);
        return false;
    }
    public function testAtStoresDefinitionAndDeliversOnce(): void
    {
        $this->enabled();$this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();try {
                $at  = (new \DateTimeImmutable('+2 seconds'))->format('Y-m-d\TH:i:s\Z');
                $ack = $this->publish($f, MessageSchedule::at($at), "one\r\n\0");
                self::assertSame($f->stream, $ack->getStream());
                self::assertGreaterThan(0, $ack->getSeq());
                $definition = $f->rows($f->definition);
                self::assertCount(1, $definition);
                $rows = $f->waitRows($f->target, 1);
                self::assertSame([bin2hex("one\r\n\0")], array_column($rows, 'payload_hex'));
                self::assertStringContainsString('Nats-Schedule-Next: purge', $rows[0]['headers']);
                self::assertStringContainsString('X-Trace: synthetic-N6', $rows[0]['headers']);
                \Amp\delay(1.2);
                self::assertCount(1, $f->rows($f->target));
                self::assertSame([], $f->rows($f->definition));
                $this->trace('at', $f, ['definition_puback' => $f->probe->records[0]['response'],'definition_after_puback' => $definition,'target' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    public function testEveryRepeatsAndPurgeStopsIt(): void
    {
        $this->enabled();$this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();try {
                if (!$this->advanced($f, fn()=>$this->publish($f, MessageSchedule::every(1)))) {
                    return;
                }
                $this->publish($f, MessageSchedule::every(1));
                $rows = $f->waitRows($f->target, 2);
                self::assertSame(['626f6479','626f6479'], array_slice(array_column($rows, 'payload_hex'), 0, 2));
                $purge = $f->stop();
                self::assertSame(true, $purge->get('success'));
                $before = count($f->rows($f->target));
                \Amp\delay(2.2);
                self::assertCount($before, $f->rows($f->target));
                self::assertSame([], $f->rows($f->definition));
                $this->trace('every-stop', $f, ['definition_puback' => $f->probe->records[0]['response'],'target' => $rows,'purge' => $purge->toJson(),'count_after_stop' => $before]);
            } finally {
                $f->close();
            }
        });
    }
    public static function zones(): iterable
    {
        yield [null];
        yield ['UTC'];
        yield ['Europe/Athens'];
    }
    #[DataProvider('zones')]
    public function testCronSecondsTimeZoneAndServerNext(string|null $zone): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($zone): void {
            $f = new ScheduleFixture();try {
                $hour     = (new \DateTimeImmutable('now', new \DateTimeZone($zone ?? 'UTC')))->format('G');
                $hours    = [$hour, (string) (((int) $hour + 1) % 24)];
                $pattern  = '* * ' . implode(',', $hours) . ' * * *';
                $schedule = MessageSchedule::cron($pattern, $zone);
                if (!$this->advanced($f, fn()=>$this->publish($f, $schedule))) {
                    return;
                }
                $this->publish($f, $schedule);
                $rows = $f->waitRows($f->target, 1);
                $f->stop();
                self::assertSame(1, preg_match('/Nats-Schedule-Next: ([^\r\n]+)/', $rows[0]['headers'], $match));
                $next   = new \DateTimeImmutable($match[1]);
                $stored = new \DateTimeImmutable($rows[0]['time']);
                self::assertGreaterThanOrEqual($stored->getTimestamp(), $next->getTimestamp());
                self::assertLessThanOrEqual(2, $next->getTimestamp() - $stored->getTimestamp());
                self::assertContains($next->setTimezone(new \DateTimeZone($zone ?? 'UTC'))->format('G'), $hours);
                $this->trace('cron-' . str_replace('/', '-', $zone ?? 'default-UTC'), $f, ['definition_puback' => $f->probe->records[0]['response'],'pattern' => $pattern,'time_zone' => $zone ?? 'UTC','server_next' => $match[1],'target' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    public static function sourceStates(): iterable
    {
        yield [true];
        yield [false];
    }
    #[DataProvider('sourceStates')]
    public function testSamplingReadsLatestAtFiringAndFallsBack(bool $populated): void
    {
        $this->enabled();$this->runAsyncTest(function () use ($populated): void {
            $f = new ScheduleFixture();try {
                if (!$this->advanced($f, fn()=>$this->publish($f, MessageSchedule::every(1), 'fallback', $f->source))) {
                    return;
                }
                $plain = new JetStreamPublisher($f->writer);
                if ($populated) {
                    $plain->publish($f->source, 'A');
                }
                $this->publish($f, MessageSchedule::every(1), 'fallback', $f->source);
                $first = $f->waitRows($f->target, 1);
                self::assertSame(bin2hex($populated ? 'A' : 'fallback'), $first[0]['payload_hex']);
                $plain->publish($f->source, 'B');
                $rows = $f->waitRows($f->target, 2);
                $f->stop();
                self::assertSame([bin2hex($populated ? 'A' : 'fallback'),bin2hex('B')], array_slice(array_column($rows, 'payload_hex'), 0, 2));
                $this->trace('sampling-' . ($populated ? 'populated' : 'empty'), $f, ['definition_puback' => $f->probe->records[0]['response'],'target' => $rows]);
            } finally {
                $f->close();
            }
        });
    }
    public function testScheduledRollupAndGeneratedTtl(): void
    {
        $this->enabled();$this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();try {
                $at = MessageSchedule::at((new \DateTimeImmutable('+2 seconds'))->format('Y-m-d\TH:i:s\Z'));
                if (!$this->advanced($f, fn()=>$this->publish($f, $at, 'latest', null, 1, ScheduleRollup::SUB))) {
                    return;
                }
                $plain = new JetStreamPublisher($f->writer);
                $plain->publish($f->target, 'old1');
                $plain->publish($f->target, 'old2');
                $plain->publish($f->neighbor, 'keep1');
                $plain->publish($f->neighbor, 'keep2');
                $this->publish($f, $at, 'latest', null, 1, ScheduleRollup::SUB);
                $deadline = hrtime(true) + 5000000000;
                do {
                    $rows = $f->rows($f->target);
                    if (count($rows) === 1 && $rows[0]['payload_hex'] === bin2hex('latest')) {
                        break;
                    }\Amp\delay(.02);
                } while (hrtime(true) < $deadline);
                self::assertCount(1, $rows);
                self::assertSame(bin2hex('latest'), $rows[0]['payload_hex']);
                self::assertStringContainsString('Nats-TTL: 1s', $rows[0]['headers']);
                self::assertStringContainsString('Nats-Rollup: sub', $rows[0]['headers']);
                self::assertSame([bin2hex('keep1'),bin2hex('keep2')], array_column($f->rows($f->neighbor), 'payload_hex'));
                $deadline = hrtime(true) + 5000000000;
                do {
                    $expired = $f->rows($f->target);
                    if ($expired === []) {
                        break;
                    }\Amp\delay(.05);
                } while (hrtime(true) < $deadline);
                self::assertSame([], $expired);
                $this->trace('rollup-ttl', $f, ['definition_puback' => $f->probe->records[0]['response'],'target_before_ttl' => $rows,'target_after_ttl' => $expired,'neighbor' => $f->rows($f->neighbor)]);
            } finally {
                $f->close();
            }
        });
    }
    public function testDisabledFlagsAndUnroutedAddressHaveNoDefinition(): void
    {
        $this->enabled();$this->runAsyncTest(function (): void {
            foreach (['disabled','ttl','route'] as $case) {
                $f = new ScheduleFixture($case !== 'disabled', $case !== 'ttl');try {
                    $schedule = new ScheduleOptions($f->stream, $case === 'route' ? 'unrouted.target' : $f->target, MessageSchedule::at('2099-01-01T00:00:00Z'), ttlSeconds:$case === 'ttl' ? 1 : null);
                    try {
                        (new JetStreamPublisher($f->probe))->publish($f->definition, 'x', new PublishOptions(schedule:$schedule));
                        self::fail('Local admission refusal');
                    } catch (ScheduledPublishException $error) {
                        self::assertFalse($error->isOutcomeUnknown());
                    }
                    self::assertSame([], $f->probe->records);
                    self::assertSame([], $f->rows());
                    $this->trace('disabled-' . $case, $f, ['data_frames' => 0,'definitions' => 0]);
                } finally {
                    $f->close();
                }
            }
        });
    }
    public function testStreamUpdatePreservesScheduleFlagAndOtherConfiguration(): void
    {
        $this->enabled();$this->runAsyncTest(function (): void {
            $f = new ScheduleFixture(false);try {
                $enabled = $f->admin->updateStream($f->stream, new StreamConfigPatch(allowMessageSchedules:true));
                self::assertSame(true, $enabled->getConfiguration()->get('allow_msg_schedules'));
                $updated = $f->admin->updateStream($f->stream, new StreamConfigPatch(maxConsumers:7));
                $config  = $updated->getConfiguration();
                self::assertSame(true, $config->get('allow_msg_schedules'));
                self::assertSame($f->subjects, $config->get('subjects'));
                self::assertSame(true, $config->get('allow_msg_ttl'));
                self::assertSame(7, $config->get('max_consumers'));
                $this->trace('update-preserves', $f, ['config' => $config->toJson()]);
            } finally {
                $f->close();
            }
        });
    }
    public function testServerErrorPreservedAndAllRollupIsRejected(): void
    {
        $this->enabled();$this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();try {
                try {
                    (new JetStreamPublisher($f->probe))->publish($f->definition, 'x', new PublishOptions(expectedLastSeq:99, schedule:new ScheduleOptions($f->stream, $f->target, MessageSchedule::at('2099-01-01T00:00:00Z'))));
                    self::fail('Expected conflict');
                } catch (ScheduledPublishException $error) {
                    self::assertSame(400, $error->getApiCode());
                    self::assertSame(10071, $error->getErrorCode());
                    self::assertFalse($error->isOutcomeUnknown());
                }
                self::assertSame([], $f->rows($f->definition));
                if (getenv('NATS_EXPECTED_VERSION') !== '2.12.6') {
                    try {
                        (new JetStreamPublisher($f->writer))->publish($f->definition, 'x', new PublishOptions(headers:['Nats-Schedule' => '@at 2099-01-01T00:00:00Z','Nats-Schedule-Target' => $f->target,'Nats-Schedule-Rollup' => 'all']));
                        self::fail('Server must reject all');
                    } catch (JetStreamApiException $error) {
                        self::assertSame(400, $error->getApiCode());
                        self::assertSame(10192, $error->getErrorCode());
                    }
                }
                self::assertSame([], $f->rows());
                $this->trace('server-error', $f, ['definitions' => 0]);
            } finally {
                $f->close();
            }
        });
    }
    public function testLostDefinitionReplyIsNotReplayedAfterReconnect(): void
    {
        $this->enabled();$this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();try {
                $f->probe->after = static function ($message, $reply) use ($f) {
                    $f->writer->disconnect();
                    throw new \Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException(true, new \RuntimeException('synthetic lost definition confirmation'));
                };
                try {
                    $this->publish($f, MessageSchedule::at('2099-01-01T00:00:00Z'), 'uncertain');
                    self::fail('Reply lost');
                } catch (ScheduledPublishException $error) {
                    self::assertTrue($error->isOutcomeUnknown());
                }
                $rows = $f->rows($f->definition);
                self::assertCount(1, $rows);
                self::assertSame(bin2hex('uncertain'), $rows[0]['payload_hex']);
                self::assertCount(1, $f->probe->records);
                $f->probe->after = null;
                $f->writer->connect();
                $f->admin->getStreamInfo($f->stream);
                self::assertCount(1, $f->probe->records);
                $f->definition .= '.new';
                $ack            = $this->publish($f, MessageSchedule::at('2099-01-01T00:00:00Z'), 'new');
                self::assertGreaterThan(0, $ack->getSeq());
                self::assertCount(2, $f->probe->records);
                $this->trace('lost-reply', $f, ['first_definition' => $rows,'old_definition_attempts' => 1,'new_definition_puback' => $f->probe->records[1]['response'],'target' => $f->rows($f->target)]);
            } finally {
                $f->close();
            }
        });
    }
    public static function recurringTtlModes(): iterable
    {
        yield ['every'];
        yield ['cron'];
    }
    #[DataProvider('recurringTtlModes')]
    public function testRecurringTtlDefinitionHasBoundedServerLifetime(string $mode): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($mode): void {
            $f = new ScheduleFixture();
            try {
                $schedule = $mode === 'every' ? MessageSchedule::every(1) : MessageSchedule::cron('* * * * * *');
                if (!$this->advanced($f, fn() => $this->publish($f, $schedule, 'bounded', null, 60))) {
                    return;
                }
                $ack        = $this->publish($f, $schedule, 'bounded', null, 60);
                $definition = $f->rows($f->definition);
                self::assertCount(1, $definition);
                self::assertStringContainsString('Nats-TTL: 31536000s', $definition[0]['headers']);
                $rows = $f->waitRows($f->target, 2);
                self::assertSame([bin2hex('bounded'), bin2hex('bounded')], array_slice(array_column($rows, 'payload_hex'), 0, 2));
                foreach ($rows as $row) {
                    self::assertStringContainsString('Nats-TTL: 60s', $row['headers']);
                    self::assertStringNotContainsString('31536000s', $row['headers']);
                }
                $stopped = $f->stop();
                self::assertTrue($stopped->get('success'));
                $this->trace('bounded-ttl-' . $mode, $f, ['definition_puback' => $f->probe->records[0]['response'], 'definition' => $definition, 'target' => $rows, 'stopped' => $stopped->toJson()]);
            } finally {
                $f->close();
            }
        });
    }
    public function testRecurringDefinitionExpiresWithoutFiringRenewal(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();
            try {
                if (!$this->advanced($f, fn() => $this->publish($f, MessageSchedule::every(1), 'expires', null, 60))) {
                    return;
                }
                $driverTtl        = null;
                $f->probe->before = static function ($message) use (&$driverTtl) {
                    $driverTtl = $message->getHeaders()->get('Nats-TTL');
                    // Test adapter shortens only the definition lifetime; production always emits 365 days.
                    $message->getHeaders()->set('Nats-TTL', '3s');
                    return $message;
                };
                $this->publish($f, MessageSchedule::every(1), 'expires', null, 60);
                self::assertSame('31536000s', $driverTtl);
                $stored = $f->rows($f->definition);
                self::assertCount(1, $stored);
                self::assertStringContainsString('Nats-TTL: 3s', $stored[0]['headers']);
                $delivered   = $f->waitRows($f->target, 2);
                $stillStored = $f->rows($f->definition);
                self::assertSame($stored[0]['time'], $stillStored[0]['time']);
                $deadline = hrtime(true) + 5_000_000_000;
                do {
                    if ($f->rows($f->definition) === []) {
                        break;
                    }
                    \Amp\delay(.02);
                } while (hrtime(true) < $deadline);
                self::assertSame([], $f->rows($f->definition));
                // Allow an already started firing to settle before proving no further deliveries.
                \Amp\delay(.2);
                $afterExpiry = $f->rows($f->target);
                \Amp\delay(2.2);
                self::assertSame($afterExpiry, $f->rows($f->target));
                self::assertGreaterThanOrEqual(2, count($afterExpiry));
                $this->trace('definition-ttl-expiry', $f, ['driver_definition_ttl' => $driverTtl, 'test_wire_ttl' => '3s', 'definition_puback' => $f->probe->records[0]['response'], 'definition' => $stored, 'first_deliveries' => $delivered, 'target_after_expiry' => $afterExpiry, 'target_after_wait' => $f->rows($f->target)]);
            } finally {
                $f->close();
            }
        });
    }
    public static function atExpirationCases(): iterable
    {
        yield 'one ns over' => ['854775808', 0, false];
        yield 'equal' => ['854775807', 0, false];
        yield 'one ns below' => ['854775806', 0, false];
        yield '60-second supported window' => ['854775807', 60, true];
    }
    #[DataProvider('atExpirationCases')]
    public function testAtExecutionDelayCannotDestroyTargetHistory(string $fraction, int $headroomSeconds, bool $allowed): void
    {
        $this->enabled();
        $this->runAsyncTest(function () use ($fraction, $headroomSeconds, $allowed): void {
            $f = new ScheduleFixture();
            try {
                $plain = new JetStreamPublisher($f->writer);
                $plain->publish($f->target, 'old');
                $plain->publish($f->neighbor, 'keep');
                $second    = time() + 4;
                $ttl       = 9_223_372_036 - $second - $headroomSeconds;
                $timestamp = gmdate('Y-m-d\TH:i:s', $second) . '.' . $fraction . 'Z';
                $schedule  = MessageSchedule::at($timestamp);
                $publish   = fn() => (new JetStreamPublisher($f->probe))->publish($f->definition, 'new', new PublishOptions(schedule:new ScheduleOptions($f->stream, $f->target, $schedule, ttlSeconds:$ttl, rollup:ScheduleRollup::SUB)), 1500);
                $ack       = null;
                if ($allowed) {
                    // Rollup is an extension; the old server must still get zero data frames.
                    if (getenv('NATS_EXPECTED_VERSION') === '2.12.6') {
                        try {
                            $publish();
                            self::fail('Old server cannot receive scheduled rollup');
                        } catch (ScheduledPublishException $error) {
                            self::assertFalse($error->isOutcomeUnknown());
                        }
                        self::assertSame([], $f->probe->records);
                        self::assertSame([bin2hex('old')], array_column($f->rows($f->target), 'payload_hex'));
                        self::assertSame([bin2hex('keep')], array_column($f->rows($f->neighbor), 'payload_hex'));
                        $this->trace('at-execution-window-old-refusal', $f, ['data_frames' => 0, 'target' => $f->rows($f->target)]);
                        return;
                    }
                    $ack      = $publish();
                    $deadline = hrtime(true) + 7_000_000_000;
                    do {
                        $rows = $f->rows($f->target);
                        if (array_column($rows, 'payload_hex') === [bin2hex('new')]) {
                            break;
                        }
                        \Amp\delay(.012);
                    } while (hrtime(true) < $deadline);
                    self::assertSame([bin2hex('new')], array_column($rows, 'payload_hex'));
                    \Amp\delay(1.2);
                    self::assertSame($rows, $f->rows($f->target));
                    self::assertCount(1, $f->probe->records);
                    $stored   = \Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp::fromRfc3339($rows[0]['time'])->getUnixNanoseconds();
                    $nominal  = \Dorpmaster\Nats\Domain\JetStream\Model\NanoTimestamp::fromRfc3339($timestamp)->getUnixNanoseconds();
                    $lateness = (int) $stored - (int) $nominal;
                    self::assertGreaterThanOrEqual(0, $lateness);
                    self::assertLessThanOrEqual(60_000_000_000, $lateness);
                } else {
                    try {
                        $publish();
                        self::fail('Nominal timestamp has insufficient execution-delay headroom');
                    } catch (\InvalidArgumentException $error) {
                        self::assertStringContainsString('ttlSeconds', $error->getMessage());
                    }
                    self::assertSame([], $f->probe->records);
                    // Wait beyond the requested firing so rejection is confirmed with an independent read.
                    \Amp\delay(max(0, $second + 1 - microtime(true)));
                    self::assertSame([bin2hex('old')], array_column($f->rows($f->target), 'payload_hex'));
                    self::assertSame([], $f->rows($f->definition));
                }
                self::assertSame([bin2hex('keep')], array_column($f->rows($f->neighbor), 'payload_hex'));
                $this->trace('at-execution-window-' . $headroomSeconds . '-' . $fraction, $f, ['nominal_at' => $timestamp, 'ttl_seconds' => $ttl, 'execution_reserve_seconds' => 60, 'definition_puback' => $ack === null ? null : $f->probe->records[0]['response'], 'data_frames' => count($f->probe->records), 'actual_lateness_ns' => $lateness ?? null, 'target' => $f->rows($f->target), 'neighbor' => $f->rows($f->neighbor)]);
            } finally {
                $f->close();
            }
        });
    }
    public function testOverflowingTtlDoesNotRollupTargetHistory(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();
            try {
                $plain = new JetStreamPublisher($f->writer);
                $plain->publish($f->target, 'old');
                $plain->publish($f->neighbor, 'keep');
                foreach (['at', 'past', 'future', 'every', 'cron'] as $mode) {
                    $schedule = match ($mode) {
                        'at' => MessageSchedule::at((new \DateTimeImmutable('+1 second'))->format('Y-m-d\TH:i:s\Z')),
                        'past' => MessageSchedule::at('2000-01-01T00:00:00Z'),
                        'future' => MessageSchedule::at('2250-01-01T00:00:00Z'),
                        'every' => MessageSchedule::every(1),
                        'cron' => MessageSchedule::cron('* * * * * *'),
                    };
                    try {
                        $this->publish($f, $schedule, 'new', null, $mode === 'future' ? 400_000_000 : 8_000_000_000, ScheduleRollup::SUB);
                        self::fail('Expiration overflow must fail locally');
                    } catch (\InvalidArgumentException $error) {
                        self::assertStringContainsString('ttlSeconds', $error->getMessage());
                    }
                }
                self::assertSame([], $f->probe->records);
                \Amp\delay(1.2);
                self::assertSame([], $f->rows($f->definition));
                self::assertSame([bin2hex('old')], array_column($f->rows($f->target), 'payload_hex'));
                self::assertSame([bin2hex('keep')], array_column($f->rows($f->neighbor), 'payload_hex'));
                $this->trace('ttl-overflow-refusal', $f, ['data_frames' => count($f->probe->records), 'target' => $f->rows($f->target), 'neighbor' => $f->rows($f->neighbor)]);
            } finally {
                $f->close();
            }
        });
    }
    public function testNoAckStreamIsRejectedBeforeDefinition(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();
            try {
                $config = $f->readerAdmin->getStreamInfo($f->stream)->getConfiguration();
                $f->readerTransport->requestJson('STREAM.UPDATE.' . $f->stream, $config->withFields(['no_ack' => true]));
                self::assertTrue($f->readerAdmin->getStreamInfo($f->stream)->getConfiguration()->get('no_ack'));
                try {
                    $this->publish($f, MessageSchedule::at((new \DateTimeImmutable('+1 second'))->format('Y-m-d\TH:i:s\Z')));
                    self::fail('no_ack must fail locally');
                } catch (ScheduledPublishException $error) {
                    self::assertFalse($error->isOutcomeUnknown());
                    self::assertStringContainsString('no_ack', $error->getMessage());
                }
                self::assertSame([], $f->probe->records);
                \Amp\delay(1.2);
                self::assertSame([], $f->rows());
                $this->trace('no-ack-refusal', $f, ['data_frames' => count($f->probe->records), 'rows' => $f->rows()]);
            } finally {
                $f->close();
            }
        });
    }
    public function testMalformedTransformCannotHideActualDefinitionRoute(): void
    {
        $this->enabled();
        $this->runAsyncTest(function (): void {
            $f = new ScheduleFixture();
            try {
                $config = $f->readerAdmin->getStreamInfo($f->stream)->getConfiguration();
                $f->readerTransport->requestJson('STREAM.UPDATE.' . $f->stream, $config->withFields(['subject_transform' => (object) ['src' => $f->definition, 'dest' => $f->neighbor]]));
                $attempts = [];
                foreach (['actual', 'null', '[]', '"bad"', '42', 'true', 'false'] as $value) {
                    $client = new \Dorpmaster\Nats\Tests\Support\AtomicBatchTargetStubClient($f->probe, static function ($message) use ($f, $value): string {
                        $reply = $f->probe->requestOnceOnConnection($message, $f->writer->getServerInformation()->connectionId, 5);
                        if ($value === 'actual' || !str_contains($message->getSubject(), '.INFO.')) {
                            return $reply->getPayload();
                        }
                        // Change only this one token in the real INFO. All remaining replies and data are genuine.
                        $json                            = json_decode($reply->getPayload(), false, 512, JSON_THROW_ON_ERROR);
                        $json->config->subject_transform = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
                        return json_encode($json, JSON_THROW_ON_ERROR);
                    });
                    try {
                        (new JetStreamPublisher($client))->publish($f->definition, 'must-not-be-routed', new PublishOptions(schedule:new ScheduleOptions($f->stream, $f->target, MessageSchedule::at((new \DateTimeImmutable('+1 second'))->format('Y-m-d\TH:i:s\Z')))), 5000);
                        self::fail('Actual or untrustworthy transform must be refused');
                    } catch (ScheduledPublishException $error) {
                        self::assertFalse($error->isOutcomeUnknown());
                        self::assertStringContainsString('subject_transform', $error->getMessage());
                        $attempts[$value] = ['unknown' => $error->isOutcomeUnknown(), 'reason' => $error->getMessage()];
                    }
                }
                self::assertSame([], $f->probe->records);
                \Amp\delay(1.2);
                self::assertSame([], $f->rows());
                self::assertSame(0, $f->stop()->get('purged'));
                $this->trace('transform-refusal', $f, ['cases' => $attempts, 'data_frames' => count($f->probe->records), 'rows' => $f->rows()]);
            } finally {
                $f->close();
            }
        });
    }
    private function trace(string $name, ScheduleFixture $f, array $observations): void
    {
        if ($directory = getenv('NATS_SCHEDULE_TRACE_DIR')) {
            file_put_contents($directory . '/' . $name . '.json', json_encode(['definitions' => $f->probe->records,'checks' => $f->probe->checks,'observations' => $observations], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }
}
