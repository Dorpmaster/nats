<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Admin;

use Dorpmaster\Nats\Domain\Client\ServerInformation;
use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilities;
use Dorpmaster\Nats\Domain\JetStream\Capability\JetStreamCapabilitiesProviderInterface;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\{JsonObject,StreamConfig,StreamConfigPatch,StreamSource};
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamSourceAdmissionRegressionTest extends TestCase
{
    private function transport(string $prefix = '$JS.API'): object
    {
        $t = $this->createMockForIntersectionOfInterfaces([JetStreamSingleAttemptTransportInterface::class,JetStreamCapabilitiesProviderInterface::class]);
        $t->method('getCapabilities')->willReturn(new JetStreamCapabilities(new ServerInformation('test', '2.12.6', true, 3, 1, static fn()=>true), $prefix));
        $t->expects(self::never())->method('request');
        return $t;
    }
    public static function fields(): iterable
    {
        yield ['mirror'];
        yield ['sources'];
    }
    #[DataProvider('fields')]
    public function testNullSnapshotCannotBeReplayedAsRemoval(string $field): void
    {
        $t = $this->transport();
        $t->expects(self::once())->method('requestJson')->with('STREAM.INFO.T')->willReturn(new JsonObject('{"config":{"name":"T","' . $field . '":null}}'));
        $t->expects(self::never())->method('requestJsonOnce');
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage($field);
        (new JetStreamAdmin($t))->updateStream('T', new StreamConfigPatch(maxConsumers:7));
    }
    #[DataProvider('fields')]
    public function testUnchangedExternalLinksNeedNoConsumerLookup(string $field): void
    {
        $t      = $this->transport();
        $link   = '{"name":"O","consumer":{"name":"C","deliver_subject":"delivery","future":{}},"external":{"api":"$JS.ORIGIN.API"},"future":{"counter":18446744073709551615}}';
        $config = new JsonObject('{"name":"T","' . $field . '":' . ($field === 'sources' ? '[' . $link . ']' : $link) . '}');
        $t->expects(self::never())->method('getClient');
        $t->expects(self::once())->method('requestJson')->with('STREAM.INFO.T')->willReturn(new JsonObject('{"config":' . $config->toJson() . '}'));
        $t->expects(self::once())->method('requestJsonOnce')->willReturnCallback(static function (string $suffix, JsonObject $payload) use ($config, $field): JsonObject {
            self::assertSame('STREAM.UPDATE.T', $suffix);
            self::assertSame($config->getRawValue($field), $payload->getRawValue($field));
            self::assertSame(7, $payload->get('max_consumers'));
            return new JsonObject('{"config":' . $payload->toJson() . '}');
        });
        $info = (new JetStreamAdmin($t))->updateStream('T', new StreamConfigPatch(maxConsumers:7));
        self::assertSame(7, $info->getConfiguration()->get('max_consumers'));
    }
    public static function prefixPolicies(): iterable
    {
        foreach (['$JS.API','$JS.EU.API'] as $prefix) {
            foreach (['source','mirror'] as $mode) {
                foreach (['limits','interest','workqueue'] as $policy) {
                                yield [$prefix,$mode,$policy];
                }
            }
        }
    }
    #[DataProvider('prefixPolicies')]
    public function testOldPrefixCannotAuthorizeImplicitDurableMode(string $prefix, string $mode, string $policy): void
    {
        $t      = $this->transport($prefix);
        $writes = 0;
        $t->method('requestJson')->willReturnCallback(static function (string $suffix, JsonObject $body) use ($policy, &$writes): JsonObject {
            if ($suffix === 'STREAM.INFO.O') {
                return new JsonObject('{"config":{"name":"O","retention":"' . $policy . '"}}');
            }
            if ($suffix === 'INFO') {
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":3}}');
            }
            self::assertSame('STREAM.CREATE.T', $suffix);
            $writes++;
            return new JsonObject('{"config":' . $body->toJson() . '}');
        });
        try {
            $src = new StreamSource('O');
            (new JetStreamAdmin($t))->createStream(new StreamConfig('T', [], mirror:$mode === 'mirror' ? $src : null, sources:$mode === 'source' ? [$src] : null));
            self::assertSame('limits', $policy);
        } catch (JetStreamApiException $error) {
            self::assertNotSame('limits', $policy);
        }
        self::assertSame($policy === 'limits' ? 1 : 0, $writes);
    }
    public function testExplicitRemovalsAndUnlinkedLimitRemainAvailable(): void
    {
        foreach (['none', 'mirror', 'sources'] as $kind) {
            $t      = $this->transport();
            $before = $kind === 'none' ? '{"name":"T"}' : ($kind === 'mirror' ? '{"name":"T","mirror":{"name":"O"}}' : '{"name":"T","sources":[{"name":"O"}]}');
            $t->expects(self::once())->method('requestJson')->with('STREAM.INFO.T')->willReturn(new JsonObject('{"config":' . $before . '}'));
            $t->expects(self::once())->method('requestJsonOnce')->willReturnCallback(static function (string $suffix, JsonObject $payload) use ($kind): JsonObject {
                self::assertSame('STREAM.UPDATE.T', $suffix);
                if ($kind === 'mirror') {
                    self::assertSame('null', $payload->getRawValue('mirror'));
                }
                if ($kind === 'sources') {
                    self::assertSame('[]', $payload->getRawValue('sources'));
                }
                if ($kind === 'none') {
                    self::assertFalse($payload->has('mirror'));
                    self::assertFalse($payload->has('sources'));
                    return new JsonObject('{"config":{"name":"T","max_consumers":7}}');
                }
                return new JsonObject('{"config":{"name":"T"}}');
            });
            (new JetStreamAdmin($t))->updateStream('T', match ($kind) {
                'mirror'=>new StreamConfigPatch(mirror:false),'sources'=>new StreamConfigPatch(sources:[]),default=>new StreamConfigPatch(maxConsumers:7)
            });
        }
    }
    public function testChangedExternalConsumerUsesOnlyItsOriginApi(): void
    {
        $receiver  = [];
        $origin    = [];
        $caps      = new JetStreamCapabilities(new ServerInformation('dest', '2.15.2', true, 5, 1, static fn()=>true));
        $admission = new \Dorpmaster\Nats\Domain\JetStream\Internal\StreamSourceAdmission(
            $caps,
            static function (string $suffix) use (&$receiver): JsonObject {
                $receiver[] = $suffix;
                self::assertSame('INFO', $suffix);
                return new JsonObject('{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}');
            },
            static function (string $prefix, string $suffix) use (&$origin): JsonObject {
                self::assertSame('$JS.ORIGIN.API', $prefix);
                $origin[] = $suffix;
                return new JsonObject($suffix === 'INFO' ? '{"type":"io.nats.jetstream.api.v1.account_info_response","api":{"level":5}}' : '{"stream_name":"O","name":"C","config":{"durable_name":"C","name":"C","ack_policy":"flow_control","flow_control":true,"idle_heartbeat":1000000000,"deliver_subject":"delivery"}}');
            }
        );
        $config    = new JsonObject('{"sources":[{"name":"O","external":{"api":"$JS.ORIGIN.API"},"consumer":{"name":"C","deliver_subject":"delivery"}}]}');
        $admission->verify($config, new JsonObject('{}'));
        self::assertSame(['INFO'], $receiver);
        self::assertSame(['INFO','CONSUMER.INFO.O.C'], $origin);
    }
    public static function inconsistentSnapshots(): iterable
    {
        yield ['mirror','{"name":"T"}','{"name":"O"}'];
        yield ['mirror','{"name":"T","mirror":{}}','{"name":"O"}'];
        yield ['sources','{"name":"T"}','[{"name":"O"}]'];
        yield ['sources','{"name":"T","sources":[]}','[{"name":"O"}]'];
    }
    #[DataProvider('inconsistentSnapshots')]
    public function testActiveObservationCannotBeLostByIncompleteConfig(string $field, string $config, string $observed): void
    {
        $t = $this->transport();
        $t->expects(self::once())->method('requestJson')->with('STREAM.INFO.T')->willReturn(new JsonObject('{"config":' . $config . ',"' . $field . '":' . $observed . '}'));
        $t->expects(self::never())->method('requestJsonOnce');
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage($field);
        (new JetStreamAdmin($t))->updateStream('T', new StreamConfigPatch(maxConsumers:7));
    }
    public static function multipleSourceSnapshots(): iterable
    {
        $ab = '[{"name":"A"},{"name":"B"}]';
        yield 'missing B' => ['[{"name":"A"}]', $ab, false, false];
        yield 'B replaced by C' => ['[{"name":"A"},{"name":"C"}]', $ab, false, false];
        yield 'duplicate A cannot stand for B' => ['[{"name":"A"},{"name":"A"}]', $ab, false, false];
        yield 'config order differs' => ['[{"name":"B"},{"name":"A"}]', $ab, true, false];
        yield 'observed order and lag differ' => [$ab, '[{"name":"B","lag":999},{"name":"A","lag":0}]', true, false];
        yield 'explicit removal' => [$ab, $ab, true, true];
        yield 'patch cannot repair untrusted snapshot' => ['[{"name":"A"}]', $ab, false, true];
        foreach (['api', 'filter', 'transforms'] as $kind) {
            $one = match ($kind) {
                'api' => '{"external":{"api":"$JS.ONE.API"}}',
                'filter' => '{"filter_subject":"orders.one.*"}',
                'transforms' => '{"subject_transforms":[{"src":"orders.one.*","dest":"copy.$1"}]}',
            };
            $two  = str_replace(['ONE','one'], ['TWO','two'], $one);
            $a    = '{"name":"A",' . substr($one, 1);
            $b    = '{"name":"A",' . substr($two, 1);
            $both = '[' . $a . ',' . $b . ']';
            yield $kind . ' correct' => [$both, $both, true, false];
            yield $kind . ' replaced' => ['[' . $a . ',' . $a . ']', $both, false, false];
            yield $kind . ' missing identity' => ['[{"name":"A"},{"name":"A"}]', $both, false, false];
        }
        yield 'older observation omits optional metadata' => ['[{"name":"A","external":{"api":"$JS.ONE.API"}}]', '[{"name":"A"}]', true, false];
        yield 'ambiguous observation needs distinct matching' => ['[{"name":"A","filter_subject":"x"},{"name":"A","filter_subject":"y"}]', '[{"name":"A"},{"name":"A","filter_subject":"x"}]', true, false];
        yield 'one entry cannot match two observations' => ['[{"name":"A"}]', '[{"name":"A"},{"name":"A"}]', false, false];
        yield 'empty filter equals omitted default' => ['[{"name":"A"}]', '[{"name":"A","filter_subject":""}]', true, false];
        yield 'external API trailing separator' => ['[{"name":"A","external":{"api":"$JS.ONE.API."}}]', '[{"name":"A","external":{"api":"$JS.ONE.API"}}]', true, false];
        yield 'invalid API must not normalize to valid origin' => ['[{"name":"A","external":{"api":"$JS.ONE.API.."}}]', '[{"name":"A","external":{"api":"$JS.ONE.API"}}]', false, false];
        yield 'numeric configured name' => ['[{"name":9223372036854775808}]', $ab, false, false];
        yield 'null observed API' => [$ab, '[{"name":"A","external":{"api":null}}]', false, false];
    }

    #[DataProvider('multipleSourceSnapshots')]
    public function testObservedSourcesMustMatchOriginalConfigBeforeUpdate(string $configured, string $observed, bool $accepted, bool $remove): void
    {
        $t = $this->transport();
        $t->method('requestJson')->willReturnCallback(static function (string $suffix) use ($configured, $observed): JsonObject {
            if ($suffix === 'STREAM.INFO.A') {
                return new JsonObject('{"config":{"name":"A","retention":"limits"}}');
            }
            self::assertSame('STREAM.INFO.T', $suffix);
            return new JsonObject('{"config":{"name":"T","sources":' . $configured . '},"sources":' . $observed . '}');
        });
        $t->expects($accepted ? self::once() : self::never())->method('requestJsonOnce')->willReturnCallback(static function (string $suffix, JsonObject $body) use ($configured, $remove): JsonObject {
            self::assertSame('STREAM.UPDATE.T', $suffix);
            if ($remove) {
                self::assertCount(1, $body->get('sources'));
                self::assertSame('A', $body->get('sources')[0]->name);
            } else {
                self::assertSame($configured, $body->getRawValue('sources'));
                self::assertSame(7, $body->get('max_consumers'));
            }
            return new JsonObject('{"config":' . $body->toJson() . '}');
        });
        if (!$accepted) {
            $this->expectException(JetStreamApiException::class);
            $this->expectExceptionMessage('config.sources');
        }
        (new JetStreamAdmin($t))->updateStream('T', $remove ? new StreamConfigPatch(sources:[new StreamSource('A')]) : new StreamConfigPatch(maxConsumers:7));
    }
}
