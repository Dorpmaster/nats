<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream;

use Amp\CancelledException;
use Dorpmaster\Nats\Domain\Client\ClientInterface;
use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Capability\Feature;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\Support;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamConfigurationUnconfirmedException;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamTimeoutException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfig;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransport;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamJsonTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use Dorpmaster\Nats\Protocol\MsgMessage;
use Dorpmaster\Nats\Tests\Support\CapturedProtocolLogs;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Amp\delay;

final class JetStreamFoundationTest extends TestCase
{
    public function testOldConstructorsAndNamedArgumentsRemainCompatible(): void
    {
        $old   = new StreamConfig('S', ['s'], 'file', 1);
        $named = new StreamConfig(name: 'S', subjects: ['s'], storage: 'file', replicas: 1);
        self::assertSame($old->toRequestPayload(), $named->toRequestPayload());
        self::assertSame(['name' => 'S', 'subjects' => ['s'], 'storage' => 'file', 'num_replicas' => 1], $old->toRequestPayload());
        self::assertNull((new StreamInfo(name: 'S', subjects: ['s']))->getConfiguration());
        $previous = new RuntimeException('synthetic-n1-secret');
        foreach ([new JetStreamApiException(404, 'description', $previous), new JetStreamApiException(apiCode: 404, description: 'description', previous: $previous, errorCode: 10059)] as $index => $exception) {
            self::assertSame(404, $exception->getCode());
            self::assertSame(404, $exception->getApiCode());
            self::assertSame('description', $exception->getMessage());
            self::assertSame('description', $exception->getDescription());
            self::assertSame($previous, $exception->getPrevious());
            self::assertSame($index === 0 ? null : 10059, $exception->getErrorCode());
        }
        // These doubles implement ONLY the original contracts, with no optional capability.
        $client    = self::createStub(ClientInterface::class);
        $transport = new JetStreamControlPlaneTransport(client: $client, subscriptionIdHelper: null, logger: null);
        self::assertSame(Support::UNKNOWN, $transport->getCapabilities()->check(Feature::ATOMIC_PUBLISH)->support);
        $legacy = self::createMock(JetStreamControlPlaneTransportInterface::class);
        $legacy->expects(self::once())->method('request')->with('STREAM.INFO.S', [], null)->willReturn(['config' => ['name' => 'S', 'subjects' => ['s']]]);
        self::assertSame(['s'], (new JetStreamAdmin(transport: $legacy, logger: null))->getStreamInfo('S')->getSubjects());
    }

    public static function capabilityCases(): iterable
    {
        $unknown = Support::UNKNOWN;
        $no      = Support::UNSUPPORTED;
        $yes     = Support::SUPPORTED;
        // Reference table from the pinned server generations, independent of Feature::minimumApiLevel().
        foreach (
            [
            [null, [$unknown, $unknown, $unknown, $unknown, $unknown, $unknown]],
            [0, [$no, $no, $no, $no, $no, $no]],
            [1, [$no, $no, $no, $no, $no, $no]],
            [2, [$no, $yes, $no, $no, $yes, $no]],
            [3, [$no, $yes, $no, $no, $yes, $no]],
            [4, [$yes, $yes, $yes, $yes, $yes, $yes]],
            [5, [$yes, $yes, $yes, $yes, $yes, $yes]],
            [99, [$yes, $yes, $yes, $yes, $yes, $yes]],
            ] as [$level, $states]
        ) {
            foreach (Feature::cases() as $index => $feature) {
                yield $feature->value . '-' . ($level ?? 'missing') => [$feature, $level, $states[$index]];
            }
        }
    }

    #[DataProvider('capabilityCases')]
    public function testPerFeatureApiRulesWithoutVersionGuessing(Feature $feature, int|null $level, Support $expected): void
    {
        $server       = new ServerInformation('id', '99.100.0', false, $level, 7, static fn (): bool => true);
        $capabilities = new JetStreamCapabilities($server);
        self::assertSame($expected, $capabilities->check($feature)->support);
        self::assertNotEmpty($capabilities->check($feature)->reason);
        self::assertFalse($capabilities->server->jetStream, 'Protocol support does not imply server/account enablement');
        foreach (['$JS.EU.API', 'IMPORT@EU+API'] as $prefix) {
            self::assertSame(Support::UNKNOWN, (new JetStreamCapabilities($server, $prefix))->check($feature)->support);
        }
    }

    public function testCachedCapabilityBecomesUnknownWhenConnectionExpires(): void
    {
        $current      = true;
        $information  = new ServerInformation('id', '2.15.2', true, 5, 1, static function () use (&$current): bool {
            return $current;
        });
        $capabilities = new JetStreamCapabilities($information);
        self::assertSame(Support::SUPPORTED, $capabilities->check(Feature::ATOMIC_EOB)->support);
        $current = false;
        self::assertFalse($information->isCurrentConnection());
        self::assertSame(Support::UNKNOWN, $capabilities->check(Feature::ATOMIC_EOB)->support);
    }

    public static function prefixes(): iterable
    {
        foreach (['$JS.API', '$JS.EU.API', 'IMPORT@EU+API'] as $prefix) {
            foreach (['', '.'] as $ending) {
                yield $prefix . $ending => [$prefix . $ending, $prefix];
            }
        }
    }

    #[DataProvider('prefixes')]
    public function testPrefixIsSharedByRequestAndBothPublishFrames(string $prefix, string $canonical): void
    {
        $client = self::createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willReturnCallback(static function ($message) use ($canonical): MsgMessage {
            self::assertSame($canonical . '.STREAM.INFO.ORDERS@EU', $message->getSubject());
            return new MsgMessage('reply', '1', '{"ok":true}');
        });
        $client->expects(self::exactly(2))->method('publish')->willReturnCallback(static function ($message) use ($canonical): void {
            self::assertSame($canonical . '.CONSUMER.MSG.NEXT.ORDERS+EU.WORKER@EU', $message->getSubject());
            self::assertSame('inbox', $message->getReplyTo());
        });
        $transport = new JetStreamControlPlaneTransport($client, apiPrefix: $prefix);
        self::assertSame($canonical, $transport->getApiPrefix());
        self::assertTrue($transport->request('STREAM.INFO.ORDERS@EU', [])['ok']);
        $transport->publishRequest('CONSUMER.MSG.NEXT.ORDERS+EU.WORKER@EU', [], 'inbox');
        $transport->publishRequest('CONSUMER.MSG.NEXT.ORDERS+EU.WORKER@EU', [], 'inbox', ['X' => 'value']);
    }

    public static function badPrefixes(): iterable
    {
        foreach (['', '.', '..', '$JS..API', '$JS.API..', '*.API', '$JS.>', ' JS.API', 'JS.API ', "JS\t.API", "JS.API\r", "JS.API\n", "JS.API\r\n", "JS.\0API"] as $prefix) {
            yield 'prefix-' . bin2hex($prefix) => [$prefix];
        }
    }

    #[DataProvider('badPrefixes')]
    public function testInvalidPrefixFailsBeforeAnyClientEffects(string $prefix): void
    {
        $client = self::createMock(ClientInterface::class);
        $client->expects(self::never())->method('request');
        $client->expects(self::never())->method('publish');
        $client->expects(self::never())->method('subscribe');
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('prefix');
        new JetStreamControlPlaneTransport($client, apiPrefix: $prefix);
    }

    public function testJsonPatchPreservesUnknownObjectsListsAndExactNumbers(): void
    {
        $json    = new JsonObject('{"name":"S","subjects":["old"],"metadata":{},"placement":{"cluster":"EU","tags":[]},"sources":[{"name":"SRC","opt_start_seq":18446744073709551615}],"unknown":{"a:b":"x,} suffix","fraction":0.123456789012345678901,"exp":1e-123},"max_age":9223372036854775807}');
        $patched = $json->withFields(['subjects' => ['new'], 'allow_atomic' => false]);
        foreach (['18446744073709551615', '0.123456789012345678901', '1e-123', '9223372036854775807', '"metadata":{}', '"tags":[]'] as $token) {
            self::assertStringContainsString($token, $patched->toJson());
        }
        self::assertSame(['new'], $patched->get('subjects'));
        self::assertFalse($patched->get('allow_atomic'));
        self::assertSame('x,} suffix', $patched->get('unknown')->{'a:b'});
        self::assertSame('18446744073709551615', $patched->get('sources')[0]->opt_start_seq);
        self::assertSame(['old'], $json->get('subjects'));
    }

    public function testJsonScannerHandlesEscapedKeysAndStringsWithoutChangingTokens(): void
    {
        $data    = ['a:"b' => ['quoted' => "comma, brace} slash\\ and quote\"", 'list' => [(object) [], []]], '0' => 'numeric key'];
        $object  = JsonObject::fromArray($data);
        $patched = $object->withFields(['new' => true]);
        self::assertEquals(json_decode($object->toJson())->{'a:"b'}, json_decode($patched->toJson())->{'a:"b'});
        self::assertSame('numeric key', $patched->get('0'));
        self::assertTrue($patched->get('new'));
    }

    public function testUpdateOnlySendsPatchedConfigAndReturnsServerState(): void
    {
        $configuration = new JsonObject('{"name":"S","subjects":["old"],"retention":"limits","max_age":123456789012345678,"metadata":{"tenant":"synthetic-n1-secret"},"placement":{"cluster":"EU","tags":[]},"consumer_limits":{},"sources":[{"name":"SRC","external":{"api":"$JS.REMOTE.API"}}],"mirror":{"name":"MIRROR","external":{"api":"$JS.OTHER.API"}},"unknown":{"int":18446744073709551615}}');
        $transport     = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::never())->method('request');
        $call = 0;
        $transport->expects(self::exactly(2))->method(self::logicalOr(self::equalTo('requestJson'), self::equalTo('requestJsonOnce')))->willReturnCallback(static function (string $suffix, JsonObject $payload, int $timeoutMs) use (&$call, $configuration): JsonObject {
            self::assertGreaterThan(0, $timeoutMs);
            self::assertLessThanOrEqual(500, $timeoutMs);
            if ($call++ === 0) {
                self::assertSame('STREAM.INFO.S', $suffix);
                return new JsonObject('{"config":' . $configuration->toJson() . ',"state":{"messages":12}}');
            }
            self::assertSame('STREAM.UPDATE.S', $suffix);
            self::assertSame('S', $payload->get('name'));
            self::assertSame(['new'], $payload->get('subjects'));
            self::assertFalse($payload->has('state'));
            foreach (['retention', 'max_age', 'metadata', 'placement', 'consumer_limits', 'sources', 'mirror', 'unknown'] as $field) {
                self::assertEquals($configuration->get($field), $payload->get($field));
            }
            self::assertStringContainsString('18446744073709551615', $payload->toJson());
            return new JsonObject('{"config":' . $payload->toJson() . ',"state":{}}');
        });
        $logs = new CapturedProtocolLogs();
        $info = (new JetStreamAdmin($transport, $logs))->updateStream('S', new StreamConfigPatch(subjects: ['new'], replicas: 1, allowAtomicPublish: false, allowBatchPublish: false, maxConsumers: 3), 500);
        self::assertSame(['new'], $info->getSubjects());
        self::assertSame(3, $info->getConfiguration()->get('max_consumers'));
        $logs->assertSafe(['synthetic-n1-secret']);
    }

    public function testUpdateUnconfirmedContainsActualStateAndDoesNotRetry(): void
    {
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::exactly(2))->method(self::logicalOr(self::equalTo('requestJson'), self::equalTo('requestJsonOnce')))->willReturn(new JsonObject('{"config":{"name":"S","subjects":["s"]}}'));
        try {
            (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(allowBatchPublish: true));
            self::fail('Missing confirmation must fail');
        } catch (JetStreamConfigurationUnconfirmedException $error) {
            self::assertSame(['allow_batched'], $error->getFields());
            self::assertSame('S', $error->getStreamInfo()->getName());
        }
    }

    public static function invalidPatches(): iterable
    {
        yield 'bad name' => ["S\n", new StreamConfigPatch(replicas: 1), 100];
        yield 'empty patch' => ['S', new StreamConfigPatch(), 100];
        yield 'zero timeout' => ['S', new StreamConfigPatch(replicas: 1), 0];
    }

    #[DataProvider('invalidPatches')]
    public function testUpdateValidationHasNoTransportEffects(string $stream, StreamConfigPatch $patch, int $timeout): void
    {
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::never())->method('requestJson');
        self::expectException(InvalidArgumentException::class);
        (new JetStreamAdmin($transport))->updateStream($stream, $patch, $timeout);
    }

    public function testInfoTimeoutConsumesWholeBudgetAndPreventsUpdate(): void
    {
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJson')->with('STREAM.INFO.S', self::anything(), self::anything())->willReturnCallback(static function (): JsonObject {
            delay(0.025);
            return new JsonObject('{"config":{"name":"S","subjects":["s"]}}');
        });
        self::expectException(JetStreamTimeoutException::class);
        (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(replicas: 1), 10);
    }

    public function testMissingStreamAndRefusalArePreservedWithoutCreateOrRetry(): void
    {
        $error     = new JetStreamApiException(404, 'synthetic-n1-secret', errorCode: 10059);
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJson')->willThrowException($error);
        $logs = new CapturedProtocolLogs();
        try {
            (new JetStreamAdmin($transport, $logs))->updateStream('S', new StreamConfigPatch(replicas: 1));
            self::fail('Missing stream');
        } catch (JetStreamApiException $actual) {
            self::assertSame($error, $actual);
        }
        $logs->assertSafe(['synthetic-n1-secret']);
    }

    public static function malformedPatches(): iterable
    {
        yield 'replicas zero' => [['replicas' => 0]];
        yield 'replicas too many' => [['replicas' => 6]];
        yield 'negative maxConsumers' => [['maxConsumers' => -2]];
        foreach (['', 'a..b', 'a.>.b', 'a*b', "a\n", "a\0"] as $index => $subject) {
            yield 'subject-' . $index => [['subjects' => [$subject]]];
        }
        yield 'subjects not a list' => [['subjects' => ['named' => 'a']]];
        yield 'subject not a string' => [['subjects' => [1]]];
    }

    #[DataProvider('malformedPatches')]
    public function testMalformedPatchFailsLocally(array $arguments): void
    {
        self::expectException(InvalidArgumentException::class);
        new StreamConfigPatch(...$arguments);
    }

    public function testUpdateTimeoutPreservesCancellationAndDoesNotRetry(): void
    {
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $call      = 0;
        $cause     = new CancelledException();
        $transport->expects(self::exactly(2))->method(self::logicalOr(self::equalTo('requestJson'), self::equalTo('requestJsonOnce')))->willReturnCallback(static function () use (&$call, $cause): JsonObject {
            if ($call++ === 0) {
                return new JsonObject('{"config":{"name":"S","subjects":["s"]}}');
            }
            throw $cause;
        });
        try {
            (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(replicas: 1), 100);
            self::fail('Expected timeout');
        } catch (JetStreamTimeoutException $error) {
            self::assertSame($cause, $error->getPrevious());
        }
    }

    public function testLegacyTransportCannotPerformLossyUpdate(): void
    {
        $transport = self::createMock(JetStreamControlPlaneTransportInterface::class);
        $transport->expects(self::never())->method('request');
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('JetStreamJsonTransportInterface');
        (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(replicas: 1));
    }

    public function testWrongInfoNameCannotBeUsedToRenameAStream(): void
    {
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJson')->willReturn(new JsonObject('{"config":{"name":"OTHER","subjects":[]}}'));
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('different stream name');
        (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(replicas: 1));
    }

    public function testIgnoredNewCreateOptionCannotReportSuccess(): void
    {
        $client = self::createMock(ClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn(new MsgMessage('reply', '1', '{"config":{"name":"S","subjects":["s"]}}'));
        self::expectException(JetStreamConfigurationUnconfirmedException::class);
        (new JetStreamAdmin(new JetStreamControlPlaneTransport($client)))->createStream(new StreamConfig('S', ['s'], allowBatchPublish: true));
    }

    public static function invalidInfo(): iterable
    {
        yield 'missing config' => ['{}'];
        yield 'wrong subjects type' => ['{"config":{"name":"S","subjects":{}}}'];
        yield 'invalid subject entry' => ['{"config":{"name":"S","subjects":[5]}}'];
    }

    #[DataProvider('invalidInfo')]
    public function testInvalidInfoCannotProceedToUpdate(string $json): void
    {
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJson')->willReturn(new JsonObject($json));
        self::expectException(JetStreamApiException::class);
        (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(replicas: 1));
    }

    public function testNullFlagDoesNotConfirmExplicitFalse(): void
    {
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::exactly(2))->method(self::logicalOr(self::equalTo('requestJson'), self::equalTo('requestJsonOnce')))->willReturn(new JsonObject('{"config":{"name":"S","subjects":[],"allow_atomic":null}}'));
        self::expectException(JetStreamConfigurationUnconfirmedException::class);
        (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(allowAtomicPublish: false));
    }

    public function testUpdateRefusalKeepsCodesCauseAndNoRetry(): void
    {
        $cause     = new RuntimeException('synthetic-n1-cause-secret');
        $refusal   = new JetStreamApiException(403, 'synthetic-n1-refusal-secret', $cause, 10003);
        $transport = self::createMock(JetStreamSingleAttemptTransportInterface::class);
        $call      = 0;
        $transport->expects(self::exactly(2))->method(self::logicalOr(self::equalTo('requestJson'), self::equalTo('requestJsonOnce')))->willReturnCallback(static function (string $subject) use (&$call, $refusal): JsonObject {
            if ($call++ === 0) {
                self::assertSame('STREAM.INFO.S', $subject);
                return new JsonObject('{"config":{"name":"S","subjects":["s"]}}');
            }
            self::assertSame('STREAM.UPDATE.S', $subject);
            throw $refusal;
        });
        $logs = new CapturedProtocolLogs();
        try {
            (new JetStreamAdmin($transport, $logs))->updateStream('S', new StreamConfigPatch(replicas: 1));
            self::fail('Expected update refusal');
        } catch (JetStreamApiException $error) {
            self::assertSame($refusal, $error);
            self::assertSame(403, $error->getCode());
            self::assertSame(10003, $error->getErrorCode());
            self::assertSame($cause, $error->getPrevious());
        }
        $logs->assertSafe(['synthetic-n1-cause-secret', 'synthetic-n1-refusal-secret']);
    }

    public function testLosslessLegacyTransportCannotSilentlyReplayUpdate(): void
    {
        $transport = self::createMock(JetStreamJsonTransportInterface::class);
        $transport->expects(self::never())->method('requestJson');
        self::expectException(JetStreamApiException::class);
        self::expectExceptionMessage('JetStreamSingleAttemptTransportInterface');
        (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(replicas: 1));
    }

    public static function maxConsumers(): iterable
    {
        foreach ([null, -1, 0, 1, 42] as $value) {
            yield (string) ($value ?? 'omitted') => [$value];
        }
    }

    #[DataProvider('maxConsumers')]
    public function testOptionalConfigValuesPreserveFalseAndZero(int|null $value): void
    {
        $config  = new StreamConfig('S', ['s'], allowAtomicPublish: false, allowBatchPublish: false, maxConsumers: $value);
        $payload = $config->toRequestPayload();
        self::assertFalse($payload['allow_atomic']);
        self::assertFalse($payload['allow_batched']);
        self::assertSame($value !== null, array_key_exists('max_consumers', $payload));
        if ($value !== null) {
            self::assertSame($value, $payload['max_consumers']);
        }
        $patch = new StreamConfigPatch(maxConsumers: $value);
        self::assertSame($value === null ? [] : ['max_consumers' => $value], $patch->toRequestPayload());
    }
}
