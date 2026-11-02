<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\JetStream\Admin;

use Dorpmaster\Nats\Domain\JetStream\Admin\JetStreamAdmin;
use Dorpmaster\Nats\Domain\JetStream\Exception\JetStreamApiException;
use Dorpmaster\Nats\Domain\JetStream\Model\JsonObject;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\ConsumerInfo;
use Dorpmaster\Nats\Domain\JetStream\Model\StreamConfigPatch;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamControlPlaneTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamJsonTransportInterface;
use Dorpmaster\Nats\Domain\JetStream\Transport\JetStreamSingleAttemptTransportInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JetStreamAdminResponseRegressionTest extends TestCase
{
    public static function invalidSubjects(): iterable
    {
        foreach (['null', '{}', '"orders.*"', '["orders.*",1]', '{"route":"orders.*"}', '[9223372036854775808]', '["orders.*",18446744073709551615]'] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidSubjects')]
    public function testUpdateRejectsInvalidSubjectsBeforeAnyUpdate(string $value): void
    {
        // Arrange: INFO is damaged; the modifying request must never be invoked.
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJson')->with('STREAM.INFO.S', self::isInstanceOf(JsonObject::class), self::greaterThan(0))
            ->willReturn(new JsonObject('{"config":{"name":"S","subjects":' . $value . '}}'));
        $transport->expects(self::never())->method('requestJsonOnce');
        $transport->expects(self::never())->method('request');
        $admin = new JetStreamAdmin($transport);

        // Act / Assert.
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage('subjects');
        $admin->updateStream('S', new StreamConfigPatch(maxConsumers: 7));
    }

    public static function validSubjects(): iterable
    {
        yield 'missing subjects' => ['{"name":"S"}', false, []];
        yield 'missing mirror subjects' => ['{"name":"S","mirror":{"name":"ORIGIN"}}', false, []];
        yield 'missing source subjects' => ['{"name":"S","sources":[{"name":"ORIGIN"}]}', false, []];
        yield 'empty list' => ['{"name":"S","subjects":[]}', true, []];
        yield 'quoted big numeric string' => ['{"name":"S","subjects":["9223372036854775808"]}', true, ['9223372036854775808']];
        yield 'subscription list' => ['{"name":"S","subjects":["orders.*"]}', true, ['orders.*']];
    }

    #[DataProvider('validSubjects')]
    public function testUpdatePreservesMissingAndEmptySubjects(string $configuration, bool $present, array $subjects): void
    {
        // Arrange.
        $transport = $this->createMock(JetStreamSingleAttemptTransportInterface::class);
        $transport->expects(self::once())->method('requestJson')->willReturn(new JsonObject('{"config":' . $configuration . '}'));
        $transport->expects(self::once())->method('requestJsonOnce')->willReturnCallback(static function (string $suffix, JsonObject $payload) use ($configuration, $present): JsonObject {
            self::assertSame('STREAM.UPDATE.S', $suffix);
            self::assertSame($present, $payload->has('subjects'));
            self::assertSame((new JsonObject($configuration))->withFields(['max_consumers' => 7])->toJson(), $payload->toJson());
            return new JsonObject('{"config":' . $payload->toJson() . '}');
        });

        // Act.
        $info = (new JetStreamAdmin($transport))->updateStream('S', new StreamConfigPatch(maxConsumers: 7));

        // Assert: absence stays absent on the wire; [] remains an explicit list.
        self::assertSame($subjects, $info->getSubjects());
        self::assertSame($present, $info->getConfiguration()->has('subjects'));
        self::assertSame(7, $info->getConfiguration()->get('max_consumers'));
    }

    public static function subjectWireValues(): iterable
    {
        yield 'big number' => ['[9223372036854775808]', null];
        yield 'second big number' => ['["orders.*",18446744073709551615]', null];
        yield 'quoted big string' => ['["9223372036854775808"]', ['9223372036854775808']];
        yield 'null field' => ['null', null];
        yield 'absent field' => [null, []];
        yield 'empty list' => ['[]', []];
        foreach (['0', '-1', '42', '9223372036854775807', '18446744073709551616', '1.5', '1e1000', 'null', 'true', '{}', '[]'] as $token) {
            yield 'non-string ' . $token => ['["orders.*",' . $token . ']', null];
        }
        $escaped = ['orders."quote', 'orders.comma,]', 'orders.\\slash'];
        yield 'escaped string delimiters' => [json_encode($escaped, JSON_THROW_ON_ERROR), $escaped];
        yield 'unicode escaped string' => ['["\u0034\u0032"]', ['42']];
    }

    #[DataProvider('subjectWireValues')]
    public function testGetStreamInfoChecksOriginalSubjectElementTypes(string|null $subjectsJson, array|null $expected): void
    {
        $json = '{"config":{"name":"S"' . ($subjectsJson === null ? '' : ',"subjects":' . $subjectsJson) . '}}';
        if ($expected === null) {
            $this->expectException(JetStreamApiException::class);
            $this->expectExceptionMessage('subjects');
        }
        $info = $this->read($json, false);
        if ($expected !== null) {
            self::assertSame($expected, $info->getSubjects());
            self::assertSame($json, $info->getResponse()->toJson());
        }
    }

    public static function metadataMaps(): iterable
    {
        foreach ([false, true] as $consumer) {
            foreach (['{}', '{"0":"zero","1":"one"}'] as $map) {
                yield [$consumer, $map];
            }
        }
    }

    #[DataProvider('metadataMaps')]
    public function testLegacyArrayTransportRestoresKnownMetadataObjects(bool $consumer, string $map): void
    {
        // Arrange: the same lossy json_decode(..., true) contract supported by N1.
        $json = self::response($consumer, '"metadata":' . $map);
        $info = $this->read($json, $consumer, true);

        // Assert: object-shaped maps recover their keys, including empty/numeric maps.
        self::assertSame($consumer ? 'C' : 'S', $consumer ? $info->getDurableName() : $info->getName());
        self::assertSame($map, $info->getMetadata()->toJson());
        self::assertSame($map, $info->getConfiguration()->getObject('metadata')->toJson());
    }

    #[DataProvider('metadataMaps')]
    public function testRawJsonTransportRetainsObjectTokens(bool $consumer, string $map): void
    {
        $json = self::response($consumer, '"metadata":' . $map);
        $info = $this->read($json, $consumer);
        self::assertSame($json, $info->getResponse()->toJson());
        self::assertSame($map, $info->getMetadata()->toJson());
    }

    public static function methods(): iterable
    {
        yield 'Stream' => [false];
        yield 'Consumer' => [true];
    }

    #[DataProvider('methods')]
    public function testRawJsonTransportRejectsMetadataArray(bool $consumer): void
    {
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage('metadata');
        $this->read(self::response($consumer, '"metadata":[]'), $consumer);
    }

    public function testLegacyRestoresOnlyKnownObjectsAndKeepsLists(): void
    {
        $json = '{"config":{"name":"S","subjects":[],"metadata":{}},"state":{},"unknown":[],"cluster":{"replicas":[{}],"desired":{"replicas":[{}],"origin":{"placement":{"0":"zero","1":"one"}},"status":{}}}}';
        $info = $this->read($json, false, true);
        self::assertSame('{}', $info->getState()->raw->toJson());
        self::assertSame('{}', $info->getCluster()->replicas[0]->raw->toJson());
        self::assertSame('{}', $info->getCluster()->desired->replicas[0]->raw->toJson());
        self::assertSame('{"0":"zero","1":"one"}', $info->getCluster()->desired->origin->placement->toJson());
        self::assertSame('[]', $info->getResponse()->getRawValue('unknown'));
        self::assertSame([], $info->getSubjects());
    }

    public static function invalidText(): iterable
    {
        foreach ([false, true] as $consumer) {
            foreach (['42', '9223372036854775808', '18446744073709551615', '1e2', 'true', '[]', '{}'] as $value) {
                yield [$consumer, $value];
            }
        }
    }

    #[DataProvider('invalidText')]
    public function testTextStatusRejectsNumericAndOtherNonStringTokens(bool $consumer, string $value): void
    {
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage('DesiredClusterStatus.type');
        $this->read(self::response($consumer, extra: ',"cluster":{"desired":{"status":{"type":' . $value . '}}}'), $consumer);
    }

    #[DataProvider('methods')]
    public function testQuotedBigNumberRemainsUnknownStringStatus(bool $consumer): void
    {
        $info = $this->read(self::response($consumer, extra: ',"cluster":{"desired":{"status":{"type":"9223372036854775808"}}}'), $consumer);
        self::assertSame('9223372036854775808', $info->getCluster()->desired->status->type);
        self::assertSame('"9223372036854775808"', $info->getCluster()->desired->status->raw->getRawValue('type'));
    }

    public static function otherTextFields(): iterable
    {
        foreach (['42', '9223372036854775808'] as $value) {
            yield ['{"config":{"name":' . $value . '}}', false, 'config.name'];
            yield ['{"config":{"name":"S"},"domain":' . $value . '}', false, 'stream.domain'];
            yield ['{"name":' . $value . '}', true, 'consumer.name'];
            yield ['{"name":"C","config":{"durable_name":' . $value . '}}', true, 'config.durable_name'];
            yield ['{"name":"C","cluster":{"leader":' . $value . '}}', true, 'ClusterInfo.leader'];
            yield ['{"name":"C","cluster":{"desired":{"status":{"description":' . $value . '}}}}', true, 'DesiredClusterStatus.description'];
            yield ['{"name":"C","cluster":{"desired":{"status":{"err":' . $value . '}}}}', true, 'DesiredClusterStatus.err'];
        }
    }

    #[DataProvider('otherTextFields')]
    public function testGeneralTextReaderValidatesWireTypes(string $json, bool $consumer, string $field): void
    {
        $this->expectException(JetStreamApiException::class);
        $this->expectExceptionMessage($field);
        $this->read($json, $consumer);
    }

    private static function response(bool $consumer, string $configField = '', string $extra = ''): string
    {
        return ($consumer ? '{"name":"C","config":{"durable_name":"C"' : '{"config":{"name":"S"')
            . ($configField === '' ? '' : ',' . $configField) . '}' . $extra . '}';
    }

    private function read(string $json, bool $consumer, bool $legacy = false): StreamInfo|ConsumerInfo
    {
        $transport = $this->createMock($legacy ? JetStreamControlPlaneTransportInterface::class : JetStreamJsonTransportInterface::class);
        if ($legacy) {
            $transport->expects(self::once())->method('request')->willReturn(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } else {
            $transport->expects(self::once())->method('requestJson')->willReturn(new JsonObject($json));
            $transport->expects(self::never())->method('request');
        }
        $admin = new JetStreamAdmin($transport);
        return $consumer ? $admin->getConsumerInfo('S', 'C') : $admin->getStreamInfo('S');
    }
}
