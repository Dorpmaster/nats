<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Publish;

use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestClientInterface;
use Dorpmaster\Nats\Domain\Client\ConnectionBoundRequestException;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\Client\ServerInformationProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Publish\JetStreamPublisher;
use Dorpmaster\Nats\Domain\JetStream\Publish\PublishOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleOptions;
use Dorpmaster\Nats\Domain\JetStream\Publish\MessageSchedule;
use Dorpmaster\Nats\Domain\JetStream\Exception\ScheduledPublishException;
use Dorpmaster\Nats\Protocol\Contracts\HPubMessageInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\AtomicBatchTargetStubClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScheduledPublisherTest extends TestCase
{
    private function client(int|null $level = 5): object
    {
        $client = $this->createMockForIntersectionOfInterfaces([ClientInterface::class,ConnectionBoundRequestClientInterface::class,ServerInformationProviderInterface::class]);
        $client->method('getServerInformation')->willReturn(new ServerInformation('fake', '2.15.2', true, $level, 1, static fn()=>true));
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('publish');
        return $client;
    }
    private function publisher(object $client, \Closure|null $read = null): JetStreamPublisher
    {
        return new JetStreamPublisher(new AtomicBatchTargetStubClient($client, static function ($message) use ($read) {
            return $read?->__invoke($message) ?? (str_contains($message->getSubject(), '.INFO.') ? '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_msg_schedules":true,"allow_msg_ttl":true,"metadata":{"_nats.level":"5"}}}' : (str_contains($message->getSubject(), '.NAMES') ? '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["S"]}' : null));
        }));
    }
    public function testPublicPublishSnapshotsDefinitionAndParsesAck(): void
    {
        $client = $this->client();
        $client->expects(self::once())->method('requestOnceOnConnection')->with(self::callback(static function ($message): bool {
            self::assertInstanceOf(HPubMessageInterface::class, $message);
            self::assertSame('definition', $message->getSubject());
            self::assertSame("A\r\n\0B", $message->getPayload());
            self::assertSame('@every 1s', $message->getHeaders()->get('Nats-Schedule'));
            self::assertSame('4', $message->getHeaders()->get('Nats-Required-Api-Level'));
            self::assertSame('trace', $message->getHeaders()->get('X-Trace'));
            return true;
        }), 1, self::greaterThan(0), self::anything())->willReturn(new MsgMessage('ack', '1', '{"stream":"S","seq":7}'));
        $ack = $this->publisher($client)->publish('definition', "A\r\n\0B", new PublishOptions(headers:['X-Trace' => 'trace'], schedule:new ScheduleOptions('S', 'out', MessageSchedule::every(1))), 1000);
        self::assertSame('S', $ack->getStream());
        self::assertSame(7, $ack->getSeq());
    }
    public static function refusals(): iterable
    {
        yield ['old'];
        yield ['unknown'];
        yield ['disabled'];
        yield ['ttl'];
        yield ['route'];
        yield ['remote-level'];
        yield ['mapping'];
        yield ['transform'];
    }
    #[DataProvider('refusals')]
    public function testAdmissionHasZeroDefinitionWrites(string $kind): void
    {
        $client = $this->client($kind === 'old' ? 3 : ($kind === 'unknown' ? null : 5));
        $client->expects(self::never())->method('requestOnceOnConnection');
        $read = static function ($message) use ($kind) {
            if ($kind === 'transform' && str_contains($message->getSubject(), '.INFO.')) {
                return '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_msg_schedules":true,"subject_transform":{"src":">","dest":"rewritten"},"metadata":{"_nats.level":"5"}}}';
            }
            if (str_contains($message->getSubject(), '.INFO.')) {
                return '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_msg_schedules":' . ($kind === 'disabled' ? 'false' : 'true') . ',"allow_msg_ttl":' . ($kind === 'ttl' ? 'false' : 'true') . ',"metadata":{"_nats.level":"' . ($kind === 'remote-level' ? '3' : '5') . '"}}}';
            }
            if ($kind === 'route' && str_contains($message->getSubject(), '.NAMES')) {
                return '{"type":"io.nats.jetstream.api.v1.stream_names_response","total":1,"streams":["OTHER"]}';
            }
            if ($kind === 'mapping' && str_ends_with($message->getSubject(), '.ACCOUNTZ')) {
                return '{"server":{"id":"fake"},"data":{"server_id":"fake","account_detail":{"account_name":"HUB","complete":true,"expired":false,"leafnode_connections":0,"mappings":{"out":[{"subject":"remote"}]}}}}';
            }
            return null;
        };
        try {
            $this->publisher($client, $read)->publish('definition', 'x', new PublishOptions(schedule:new ScheduleOptions('S', 'out', MessageSchedule::every(1), ttlSeconds:1)));
            self::fail('Admission must fail');
        } catch (ScheduledPublishException $error) {
            self::assertFalse($error->isOutcomeUnknown());
        }
    }
    public static function invalidAdmissionFields(): iterable
    {
        foreach (['null', '{}', '[]', '"transform"', '42', 'true', 'false'] as $value) {
            yield 'subject_transform=' . $value => ['subject_transform', $value];
        }
        foreach (['true', 'null', '[]', '{}', '"false"', '0'] as $value) {
            yield 'no_ack=' . $value => ['no_ack', $value];
        }
    }
    #[DataProvider('invalidAdmissionFields')]
    public function testUncertifiableInfoHasZeroDefinitionWrites(string $field, string $value): void
    {
        $client = $this->client();
        $client->expects(self::never())->method('requestOnceOnConnection');
        try {
            $this->publisher($client, static fn ($message) => str_contains($message->getSubject(), '.INFO.')
                ? '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_msg_schedules":true,"metadata":{"_nats.level":"5"},"' . $field . '":' . $value . '}}' : null)
                ->publish('definition', 'x', new PublishOptions(schedule:new ScheduleOptions('S', 'out', MessageSchedule::every(1))));
            self::fail('Uncertifiable INFO must fail before definition write');
        } catch (ScheduledPublishException $error) {
            self::assertFalse($error->isOutcomeUnknown());
            self::assertStringContainsString($field, $error->getMessage());
        }
    }
    public function testExplicitNoAckFalseAllowsDefinitionPubAck(): void
    {
        $client = $this->client();
        $client->expects(self::once())->method('requestOnceOnConnection')->willReturn(new MsgMessage('ack', '1', '{"stream":"S","seq":1}'));
        $ack = $this->publisher($client, static fn ($message) => str_contains($message->getSubject(), '.INFO.')
            ? '{"type":"io.nats.jetstream.api.v1.stream_info_response","config":{"name":"S","allow_msg_schedules":true,"no_ack":false,"metadata":{"_nats.level":"5"}}}' : null)
            ->publish('definition', 'x', new PublishOptions(schedule:new ScheduleOptions('S', 'out', MessageSchedule::every(1))));
        self::assertSame(1, $ack->getSeq());
    }
    public static function recurringModes(): iterable
    {
        yield ['every'];
        yield ['cron'];
    }
    #[DataProvider('recurringModes')]
    public function testExactRecurringExpirationBoundaryThroughPublicPublish(string $mode): void
    {
        $schedule = $mode === 'every' ? MessageSchedule::every(1) : MessageSchedule::cron('* * * * * *');
        // Retry only a wall-clock rollover; both boundary calls must observe the same whole second.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $now          = time();
            $last         = intdiv(PHP_INT_MAX, 1_000_000_000) - $now - 1 - 1 - ScheduleOptions::RECURRING_TTL_LIFETIME_SECONDS;
            $observations = [];
            foreach ([$last, $last + 1] as $ttl) {
                $writes = 0;
                $client = $this->client();
                $client->method('requestOnceOnConnection')->willReturnCallback(static function ($message) use (&$writes): MsgMessage {
                    $writes++;
                    self::assertSame('31536000s', $message->getHeaders()->get('Nats-TTL'));
                    return new MsgMessage('ack', '1', '{"stream":"S","seq":1}');
                });
                try {
                    $ack            = $this->publisher($client)->publish('definition', 'x', new PublishOptions(schedule:new ScheduleOptions('S', 'out', $schedule, ttlSeconds:$ttl)), 1000);
                    $observations[] = [$writes, $ack->getSeq(), null];
                } catch (\InvalidArgumentException $error) {
                    $observations[] = [$writes, null, $error->getMessage()];
                }
            }
            if ($now !== time()) {
                continue;
            }
            self::assertSame([1, 1, null], $observations[0]);
            self::assertSame([0, null], array_slice($observations[1], 0, 2));
            self::assertStringContainsString('ttlSeconds', $observations[1][2]);
            return;
        }
        self::fail('A stable clock second is required for the exact expiration boundary');
    }
    public static function atExecutionWindowCases(): iterable
    {
        yield 'overflow' => ['2262-04-11T23:47:15.854775808Z', false];
        yield 'equal' => ['2262-04-11T23:47:15.854775807Z', false];
        yield 'below' => ['2262-04-11T23:47:15.854775806Z', false];
        yield 'supported window' => ['2262-04-11T23:46:15.854775807Z', true];
    }
    #[DataProvider('atExecutionWindowCases')]
    public function testAtTtlExecutionWindowThroughPublicPublish(string $timestamp, bool $allowed): void
    {
        $client = $this->client();
        $client->expects($allowed ? self::once() : self::never())->method('requestOnceOnConnection')->willReturn(new MsgMessage('ack', '1', '{"stream":"S","seq":1}'));
        if (!$allowed) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('ttlSeconds');
        }
        $ack = $this->publisher($client)->publish('definition', 'new', new PublishOptions(schedule:new ScheduleOptions('S', 'out', MessageSchedule::at($timestamp), ttlSeconds:1, rollup:\Dorpmaster\Nats\Domain\JetStream\Publish\ScheduleRollup::SUB)), 1000);
        self::assertSame(1, $ack->getSeq());
    }
    public static function failures(): iterable
    {
        yield ['{"error":{"code":400,"err_code":10071,"description":"wrong last sequence"}}',false,400,10071];
        yield ['{"error":{"code":408,"description":"Request Timeout"}}',true,408,null];
        yield ['{"stream":"OTHER","seq":1}',true,0,null];
        yield ['{"stream":"S","seq":"1"}',true,0,null];
        yield ['{"stream":"S","seq":18446744073709551615}',true,0,null];
        yield ['{"stream":"S","seq":0}',true,0,null];
        yield ['{"stream":"S","seq":1.5}',true,0,null];
    }
    #[DataProvider('failures')]
    public function testFailedDefinitionRetainsCodesAndUncertainty(string $json, bool $unknown, int $code, int|null $errCode): void
    {
        $client = $this->client();
        $client->expects(self::once())->method('requestOnceOnConnection')->willReturn(new MsgMessage('ack', '1', $json));
        try {
            $this->publisher($client)->publish('definition', 'x', new PublishOptions(schedule:new ScheduleOptions('S', 'out', MessageSchedule::every(1))));
            self::fail('Bad confirmation');
        } catch (ScheduledPublishException $error) {
            self::assertSame($unknown, $error->isOutcomeUnknown());
            self::assertSame($code, $error->getApiCode());
            self::assertSame($errCode, $error->getErrorCode());
            self::assertSame('definition', $error->getDefinitionSubject());
            self::assertSame('S', $error->getStream());
        }
    }
    public static function attemptStates(): iterable
    {
        yield [false];
        yield [true];
    }
    #[DataProvider('attemptStates')]
    public function testBoundWriteFailureUsesActualAttemptState(bool $attempted): void
    {
        $client = $this->client();
        $cause  = new ConnectionBoundRequestException($attempted, new \RuntimeException('synthetic'));
        $client->expects(self::once())->method('requestOnceOnConnection')->willThrowException($cause);
        try {
            $this->publisher($client)->publish('definition', 'x', new PublishOptions(schedule:new ScheduleOptions('S', 'out', MessageSchedule::every(1))));
            self::fail('Write failed');
        } catch (ScheduledPublishException $error) {
            self::assertSame($attempted, $error->isOutcomeUnknown());
            self::assertSame($cause, $error->getPrevious());
        }
    }
}
