<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Admin;

use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilitiesProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\{ConsumerConfig,JsonObject,StreamConfig,StreamConfigPatch,StreamSource,StreamSourceConsumer};
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamSourcesTest extends TestCase
{
    private function transport(int|null $level = 4): object
    {
        $t = $this->createMockForIntersectionOfInterfaces([JetStreamSingleAttemptTransportInterface::class,JetStreamCapabilitiesProviderInterface::class]);
        $t->method('getCapabilities')->willReturn(new JetStreamCapabilities(new ServerInformation('test', '2.14.0', true, $level, 1, static fn()=>true)));
        $t->expects(self::never())->method('request');
        return $t;
    }
    private static function consumer(string $delivery = 'deliver'): string
    {
        return '{"stream_name":"O","name":"C","config":{"durable_name":"C","name":"C","ack_policy":"flow_control","flow_control":true,"idle_heartbeat":1000000000,"deliver_subject":"' . $delivery . '"}}';
    }
    public function testTypedWireAndRemovalPreserveLegacySignatures(): void
    {
        self::assertSame(['durable_name' => 'C','ack_policy' => 'explicit'], (new ConsumerConfig('C'))->toRequestPayload());
        $push = (new ConsumerConfig('C', ackPolicy:'flow_control', deliverSubject:'deliver'))->toRequestPayload();
        self::assertSame('deliver', $push['deliver_subject']);
        self::assertSame(true, $push['flow_control']);
        self::assertSame(1000000000, $push['idle_heartbeat']);
        self::assertSame(1000, $push['max_ack_pending']);
        $source = new StreamSource('O', new StreamSourceConsumer('C', 'deliver'));
        self::assertSame(['name' => 'O','consumer' => ['name' => 'C','deliver_subject' => 'deliver']], $source->toRequestPayload());
        self::assertSame(['sources' => []], (new StreamConfigPatch(sources:[]))->toRequestPayload());
        self::assertSame(['mirror' => null], (new StreamConfigPatch(mirror:false))->toRequestPayload());
        self::assertSame([], (new StreamConfigPatch())->toRequestPayload());
        self::assertSame(['name' => 'M','subjects' => [],'mirror' => $source->toRequestPayload()], (new StreamConfig('M', [], mirror:$source))->toRequestPayload());
        self::assertSame(['name' => 'O','opt_start_seq' => 7,'filter_subject' => 'orders.*'], (new StreamSource('O', startSequence:7, filterSubject:'orders.*'))->toRequestPayload());
    }
    public static function invalidOptions(): iterable
    {
        foreach (['',"a\n",'a.b','a/*'] as $name) {
            yield [static fn()=>new StreamSource($name)];
            yield [static fn()=>new StreamSourceConsumer($name, 'deliver')];
        }
        foreach (['',"d\n","d\r\nPUB x 0",'d.*','d.>','d..x',"d\0"] as $subject) {
            yield [static fn()=>new StreamSourceConsumer('C', $subject)];
            yield [static fn()=>new ConsumerConfig('C', deliverSubject:$subject)];
        }
        foreach ([-1,1.5,true,false,'7'] as $sequence) {
            yield [static fn()=>new StreamSource('O', startSequence:$sequence)];
        }
        foreach ([['startSequence' => 0],['startTime' => '2027-01-01T00:00:00Z'],['filterSubject' => 'x.*']] as $extra) {
            yield [static fn()=>new StreamSource('O', new StreamSourceConsumer('C', 'd'), ...$extra)];
        }
        yield [static fn()=>new StreamSource('O', startSequence:1, startTime:'2027-01-01T00:00:00Z')];
        yield [static fn()=>new StreamSource('O', startTime:'not-a-date')];
        yield [static fn()=>new StreamSource('O', filterSubject:"x\n")];
        $source = new StreamSource('O', new StreamSourceConsumer('C', 'd'));
        yield [static fn()=>new StreamConfig('T', [], sources:[$source,$source])];
        yield [static fn()=>new StreamConfigPatch(sources:[$source,new StreamSource('O', new StreamSourceConsumer('C', 'other'))])];
        yield [static fn()=>new StreamConfig('M', ['x'], mirror:$source)];
        yield [static fn()=>new StreamConfig('M', [], mirror:$source, sources:[$source])];
        yield [static fn()=>new StreamConfigPatch(sources:['wrong'])];
        foreach ([0,0.0,'',true,[],new \stdClass()] as $mirror) {
            yield [static fn()=>new StreamConfigPatch(mirror:$mirror)];
        }
        yield [static fn()=>new ConsumerConfig('C', ackPolicy:'flow_control')];
        yield [static fn()=>new ConsumerConfig('C', ackPolicy:'flow_control', deliverSubject:'d', flowControl:false)];
        yield [static fn()=>new ConsumerConfig('C', ackPolicy:'flow_control', deliverSubject:'d', idleHeartbeatMs:2000)];
    }
    #[DataProvider('invalidOptions')]
    public function testInvalidTypedOptionsFailLocally(\Closure $build): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $build();
    }
    public function testKnownOldServerHasZeroControlWritesIncludingConsumerCreate(): void
    {
        $t = $this->transport(3);
        $t->expects(self::never())->method('requestJson');
        $t->expects(self::never())->method('requestJsonOnce');
        foreach (['source','mirror','consumer'] as $mode) {
            try {
                $admin = new JetStreamAdmin($t);
                $src   = new StreamSource('O', new StreamSourceConsumer('C', 'deliver'));
                match ($mode) {
                    'source' => $admin->createStream(new StreamConfig('T', [], sources:[$src])),
                    'mirror' => $admin->createStream(new StreamConfig('T', [], mirror:$src)),
                    'consumer' => $admin->createOrUpdateConsumer('O', new ConsumerConfig('C', ackPolicy:'flow_control', deliverSubject:'deliver')),
                };
                self::fail('Old API must fail before writes');
            } catch (JetStreamApiException $error) {
                self::assertStringContainsString('level 4', $error->getMessage());
            }
        }
    }
    public static function admissionErrors(): iterable
    {
        yield ['target-old',0,null];
        yield ['missing',404,10014];
        yield ['delivery',0,null];
        yield ['identity',0,null];
    }
    #[DataProvider('admissionErrors')]
    public function testReadOnlyAdmissionRejectsBeforeCreate(string $kind, int $code, int|null $err): void
    {
        $t = $this->transport();
        $t->expects(self::never())->method('requestJsonOnce');
        $t->method('requestJson')->willReturnCallback(static function (string $suffix) use ($kind): JsonObject {
            if ($suffix === 'INFO') {
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":' . ($kind === 'target-old' ? 3 : 4) . '}}');
            }
            self::assertSame('CONSUMER.INFO.O.C', $suffix);
            if ($kind === 'missing') {
                return new JsonObject('{"error":{"code":404,"err_code":10014,"description":"consumer not found"}}');
            }
            return new JsonObject($kind === 'identity' ? str_replace('"name":"C"', '"name":"OTHER"', self::consumer()) : self::consumer('other'));
        });
        try {
            (new JetStreamAdmin($t))->createStream(new StreamConfig('T', [], sources:[new StreamSource('O', new StreamSourceConsumer('C', 'deliver'))]));
            self::fail('No CREATE allowed');
        } catch (JetStreamApiException $error) {
            self::assertSame($code, $error->getApiCode());
            self::assertSame($err, $error->getErrorCode());
        }
    }
    public function testUnrelatedUpdatePreservesAllNestedTokensAndDoesNotDeleteConsumer(): void
    {
        $t   = $this->transport();
        $raw = new JsonObject('{"name":"T","sources":[{"name":"O","consumer":{"name":"C","deliver_subject":"deliver","future":{}},"unknown":{"limit":18446744073709551615}}],"future":[]}');
        $t->method('requestJson')->willReturnCallback(static fn(string $suffix)=>match ($suffix) {
            'STREAM.INFO.T'=>new JsonObject('{"config":' . $raw->toJson() . '}'),
            'INFO'=>new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":4}}'),
            'CONSUMER.INFO.O.C'=>new JsonObject(self::consumer()),
            default=>throw new \LogicException('Unexpected request ' . $suffix),
        });
        $t->expects(self::once())->method('requestJsonOnce')->willReturnCallback(static function (string $suffix, JsonObject $payload) use ($raw): JsonObject {
            self::assertSame('STREAM.UPDATE.T', $suffix);
            self::assertSame($raw->getRawValue('sources'), $payload->getRawValue('sources'));
            self::assertSame($raw->getRawValue('future'), $payload->getRawValue('future'));
            self::assertSame(7, $payload->get('max_consumers'));
            return new JsonObject('{"config":' . $payload->toJson() . '}');
        });
        (new JetStreamAdmin($t))->updateStream('T', new StreamConfigPatch(maxConsumers:7));
    }
    public static function oldRetentionPolicies(): iterable
    {
        yield ['limits',true];
        yield ['interest',false];
        yield ['workqueue',false];
    }
    #[DataProvider('oldRetentionPolicies')]
    public function testOldLocalServerOnlyAllowsBasicLimitsOrigin(string $retention, bool $allowed): void
    {
        $t      = $this->transport(3);
        $writes = 0;
        $t->method('requestJson')->willReturnCallback(static function (string $suffix, JsonObject $payload) use ($retention, &$writes): JsonObject {
            if ($suffix === 'STREAM.INFO.O') {
                return new JsonObject('{"config":{"name":"O","retention":"' . $retention . '"}}');
            }
            self::assertSame('STREAM.CREATE.T', $suffix);
            $writes++;
            return new JsonObject('{"config":' . $payload->toJson() . '}');
        });
        try {
            (new JetStreamAdmin($t))->createStream(new StreamConfig('T', [], sources:[new StreamSource('O')]));
            self::assertTrue($allowed);
        } catch (JetStreamApiException $error) {
            self::assertFalse($allowed);
            self::assertStringContainsString('level 4', $error->getMessage());
        }
        self::assertSame($allowed ? 1 : 0, $writes);
    }
    public static function malformedPushConfirmations(): iterable
    {
        yield ['"ack_policy":"flow_control"', '"ack_policy":42', 'ack_policy'];
        yield ['"ack_policy":"flow_control"', '"ack_policy":9223372036854775808', 'ack_policy'];
        yield ['"durable_name":"C","name":"C"', '"durable_name":"C","name":"OTHER"', 'configuration'];
    }
    #[DataProvider('malformedPushConfirmations')]
    public function testPushCreationDoesNotAcceptMalformedConfirmation(string $needle, string $replacement, string $field): void
    {
        $t = $this->transport();
        $t->method('requestJson')->willReturnCallback(static function (string $suffix) use ($needle, $replacement): JsonObject {
            if ($suffix === 'INFO') {
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":4}}');
            }
            self::assertSame('CONSUMER.CREATE.O.C', $suffix);
            return new JsonObject(str_replace($needle, $replacement, self::consumer()));
        });
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage($field);
        (new JetStreamAdmin($t))->createOrUpdateConsumer('O', new ConsumerConfig('C', ackPolicy:'flow_control', deliverSubject:'deliver'));
    }
    public static function invalidStateFields(): iterable
    {
        foreach (['"1"', '1.5', '1e3', '9223372036854775808', '-9223372036854775809', 'true', '[]'] as $value) {
            yield ['active', $value];
        }
        foreach (['-1', '18446744073709551616', '"7"'] as $value) {
            yield ['lag', $value];
            yield ['seq', $value];
        }
        yield ['name', '9223372036854775808'];
    }
    #[DataProvider('invalidStateFields')]
    public function testMalformedObservedStateIsControlled(string $field, string $token): void
    {
        $t = $this->transport();
        $t->method('requestJson')->willReturn(new JsonObject('{"config":{"name":"T"},"sources":[{"' . $field . '":' . $token . '}]}'));
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage($field);
        (new JetStreamAdmin($t))->getStreamInfo('T');
    }
    public function testLegacyArrayTransportReadsObservedSourceObjects(): void
    {
        $t = self::createMock(\Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface::class);
        $t->expects(self::once())->method('request')->willReturn(['config' => ['name' => 'T','sources' => [['name' => 'O']]],'mirror' => ['name' => 'O','error' => []],'sources' => [['name' => 'O','active' => -1,'lag' => 0]]]);
        $info = (new JetStreamAdmin($t))->getStreamInfo('T');
        self::assertSame('0', $info->getSources()[0]->lag);
        self::assertSame('-1', $info->getSources()[0]->activeNanoseconds);
        self::assertNull($info->getSources()[0]->sequence);
        self::assertNull($info->getMirror()->error->code);
    }
    public function testObservedStateIsSeparateAndPreservesExactCounters(): void
    {
        $t = $this->transport();
        $t->method('requestJson')->willReturn(new JsonObject('{"config":{"name":"T","mirror":{"name":"O"}},"mirror":{"name":"O","lag":18446744073709551615,"active":-1,"seq":9223372036854775808,"error":{"code":400,"err_code":10071,"description":"conflict"}},"sources":[]}'));
        $info = (new JetStreamAdmin($t))->getStreamInfo('T');
        self::assertSame('18446744073709551615', $info->getMirror()->lag);
        self::assertSame('-1', $info->getMirror()->activeNanoseconds);
        self::assertSame('9223372036854775808', $info->getMirror()->sequence);
        self::assertSame(10071, $info->getMirror()->error->errorCode);
        self::assertSame('O', $info->getConfiguration()->getObject('mirror')->get('name'));
        self::assertSame([], $info->getSources());
        self::assertNull((new \Dorpmaster\Nats\Domain\JetStream\Model\StreamSourceInfo(new JsonObject('{}')))->sequence);
        self::assertSame('0', (new \Dorpmaster\Nats\Domain\JetStream\Model\StreamSourceInfo(new JsonObject('{"lag":0,"active":0,"seq":0}')))->sequence);
    }
}
