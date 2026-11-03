<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Dorpmaster\Nats\Domain\JetStream\Publish\MessageSchedule;
use Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleRollup;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageScheduleTest extends TestCase
{
    public static function invalidSchedules(): iterable
    {
        foreach (['0001-01-01T00:00:00Z', '9999-12-31T00:00:00Z', '', '2026-11-31T00:00:00Z', '2026-11-02', "2026-11-02T00:00:00Z\n"] as $at) {
            yield ['at', [$at]];
        }
        yield ['at', ['2026-11-02T00:00:00Z', 'UTC']];
        foreach ([0,-1,.5,1.5,true,false,'1','1s',9223372036,9223372037] as $seconds) {
            yield ['every', [$seconds]];
        }
        yield ['every', [1,'Europe/Athens']];
        foreach (['* * * * *', '* * * * * * *', '60 * * * * *', '0 0 24 * * *', '0 0 0 0 * *', '0 0 0 * 13 *', '0 0 0 * * 7', '*/0 * * * * *', '5-2 * * * * *', '0,,2 * * * * *', "* * * * * *\r\n", '* * * * * TZ=UTC'] as $cron) {
            yield ['cron', [$cron]];
        }
        foreach (['', '+02:00', 'Local', 'No/Zone', "UTC\n"] as $zone) {
            yield ['cron', ['* * * * * *',$zone]];
        }
    }
    #[DataProvider('invalidSchedules')]
    public function testInvalidScheduleIsRejectedLocally(string $method, array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MessageSchedule::$method(...$args);
    }
    public function testAtEveryAndCronRetainTypedValues(): void
    {
        self::assertSame(['Nats-Schedule' => '@at 2026-11-02T10:20:30.123456789+02:00'], MessageSchedule::at('2026-11-02T10:20:30.123456789+02:00')->toHeaders());
        self::assertSame(['Nats-Schedule' => '@every 60s'], MessageSchedule::every(60)->toHeaders());
        self::assertSame(['Nats-Schedule' => '*/2 0-30/5 12 * jan-mar mon,wed,fri', 'Nats-Schedule-Time-Zone' => 'Europe/Athens'], MessageSchedule::cron('*/2 0-30/5 12 * jan-mar mon,wed,fri', 'Europe/Athens')->toHeaders());
        self::assertSame(['Nats-Schedule' => '* * * * * *'], MessageSchedule::cron('* * * * * *')->toHeaders());
        self::assertSame('UTC', MessageSchedule::cron('* * * * * *', 'UTC')->toHeaders()['Nats-Schedule-Time-Zone']);
    }
    public static function unsafeSubjects(): iterable
    {
        foreach (['target','source','definition'] as $field) {
            foreach (['','a b',"a\t", "a\r", "a\n", "a\r\n", "a\0", 'a.*', 'a.>', 'a..b'] as $subject) {
                yield [$field,$subject];
            }
        }
    }
    #[DataProvider('unsafeSubjects')]
    public function testAllAddressesMustBeConcrete(string $field, string $subject): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        $options = new ScheduleOptions('S', $field === 'target' ? $subject : 'out', MessageSchedule::every(1), $field === 'source' ? $subject : 'source');
        $options->validateDefinition($field === 'definition' ? $subject : 'definition');
    }
    public static function conflictingHeaders(): iterable
    {
        foreach (['Nats-Schedule','NATS-SCHEDULE-SOURCE','nats-schedule-target','nats-schedule-rollup','Nats-Schedule-TTL','Nats-Schedule-Time-Zone','Nats-Schedule-Next','Nats-Scheduler','Nats-Required-Api-Level','nats-expected-stream','Nats-Rollup','Nats-TTL','Nats-Batch-Id'] as $name) {
            yield [$name];
        }
    }
    #[DataProvider('conflictingHeaders')]
    public function testTypedHeadersCannotBeOverridden(string $header): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PublishOptions(headers:[$header => 'override'], schedule:new ScheduleOptions('S', 'out', MessageSchedule::at('2026-11-02T00:00:00Z'))))->toHeaders();
    }
    public function testAllThreeAddressesDiffer(): void
    {
        foreach ([['same','same','definition'], ['out','source','source'],['out','source','out']] as [$target,$source,$definition]) {
            try {
                (new ScheduleOptions('S', $target, MessageSchedule::every(1), $source))->validateDefinition($definition);
                self::fail('Distinct addresses required');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
    public function testLegacyOptionsAndScheduleHeadersRemainCompatible(): void
    {
        $options = PublishOptions::create(msgId:'id', expectedStream:'S', expectedLastSeq:7, expectedLastMsgId:'prior', headers:['X-Trace' => 'one'], schedule:new ScheduleOptions('S', 'out', MessageSchedule::every(1), 'source', 3, ScheduleRollup::SUB));
        self::assertSame(['Nats-Msg-Id' => 'id','Nats-Expected-Stream' => 'S','Nats-Expected-Last-Sequence' => '7','Nats-Expected-Last-Msg-Id' => 'prior','Nats-Schedule' => '@every 1s','Nats-Schedule-Target' => 'out','Nats-Required-Api-Level' => '4','Nats-Schedule-Source' => 'source','Nats-Schedule-TTL' => '3s','Nats-TTL' => '31536000s','Nats-Schedule-Rollup' => 'sub','X-Trace' => 'one'], $options->toHeaders());
        self::assertSame(['Nats-Schedule' => 'raw-legacy'], (new PublishOptions(headers:['Nats-Schedule' => 'raw-legacy']))->toHeaders());
        self::assertSame('2', (new ScheduleOptions('S', 'out', MessageSchedule::at('2026-11-02T00:00:00Z')))->toHeaders()['Nats-Required-Api-Level']);
    }
    public static function invalidTtls(): iterable
    {
        foreach ([0, -1, .5, 1.5, true, false, '1', 9223372037] as $value) {
            yield [$value];
        }
    }
    #[DataProvider('invalidTtls')]
    public function testGeneratedTtlChecksTheOriginalType(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ttlSeconds');
        new ScheduleOptions('S', 'out', MessageSchedule::every(1), ttlSeconds:$value);
    }
    public static function overflowingTtls(): iterable
    {
        yield 'past execution uses current time' => [MessageSchedule::at('2000-01-01T00:00:00Z'), 8_000_000_000];
        yield 'future execution' => [MessageSchedule::at('2250-01-01T00:00:00Z'), 400_000_000];
        yield 'one nanosecond overflow' => [MessageSchedule::at('2262-04-11T23:47:15.854775808Z'), 1];
        yield 'every' => [MessageSchedule::every(1), 8_000_000_000];
        yield 'cron' => [MessageSchedule::cron('* * * * * *'), 8_000_000_000];
    }
    #[DataProvider('overflowingTtls')]
    public function testTtlMustFitExpirationNotOnlyDuration(MessageSchedule $schedule, int $ttl): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ttlSeconds');
        (new ScheduleOptions('S', 'out', $schedule, ttlSeconds:$ttl))->toHeaders();
    }
    public static function atNominalExpirationBoundaries(): iterable
    {
        yield 'one ns over' => ['2262-04-11T23:47:15.854775808Z'];
        yield 'equal' => ['2262-04-11T23:47:15.854775807Z'];
        yield 'one ns below' => ['2262-04-11T23:47:15.854775806Z'];
    }
    #[DataProvider('atNominalExpirationBoundaries')]
    public function testAtNominalBoundaryNeedsExecutionDelayHeadroom(string $timestamp): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ttlSeconds');
        (new ScheduleOptions('S', 'out', MessageSchedule::at($timestamp), ttlSeconds:1))->toHeaders();
    }
    public function testAtBoundaryIncludesSeparateExecutionWindow(): void
    {
        $headers = (new ScheduleOptions('S', 'out', MessageSchedule::at('2262-04-11T23:46:15.854775807Z'), ttlSeconds:1))->toHeaders();
        self::assertSame('1s', $headers['Nats-Schedule-TTL']);
        self::assertArrayNotHasKey('Nats-TTL', $headers);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ttlSeconds');
        // Nominal time + TTL + the 60-second supported execution window exceeds Int64 by 1 ns.
        new ScheduleOptions('S', 'out', MessageSchedule::at('2262-04-11T23:46:15.854775808Z'), ttlSeconds:1);
    }
    public function testRecurringTtlCannotOutliveFirstInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ttlSeconds');
        new ScheduleOptions('S', 'out', MessageSchedule::every(ScheduleOptions::RECURRING_TTL_LIFETIME_SECONDS), ttlSeconds:1);
    }
    public function testTypedServiceHeadersRejectBareLineBreaks(): void
    {
        foreach (['msgId','expectedLastMsgId'] as $name) {
            try {
                (new PublishOptions(...[$name => "value\n",'schedule' => new ScheduleOptions('S', 'out', MessageSchedule::every(1))]))->toHeaders();
                self::fail('Header control characters must not be sanitized');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('header', $error->getMessage());
            }
        }
    }
    public function testStreamFlagsAreOptionalAndPatchOnlySelectedFields(): void
    {
        self::assertSame(['name' => 'S','subjects' => ['x']], (new StreamConfig('S', ['x']))->toRequestPayload());
        self::assertSame(['allow_msg_schedules' => true], (new StreamConfigPatch(allowMessageSchedules:true))->toRequestPayload());
        self::assertSame(['allow_msg_ttl' => false], (new StreamConfigPatch(allowMessageTtl:false))->toRequestPayload());
        self::assertSame(['name' => 'S','subjects' => ['x'],'allow_msg_schedules' => true,'allow_msg_ttl' => true], (new StreamConfig('S', ['x'], allowMessageSchedules:true, allowMessageTtl:true))->toRequestPayload());
    }
}
