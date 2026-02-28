<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Tests\Unit\Client;

use Dorpmaster\Nats\Client\Cluster\InfoConnectUrlsExtractor;
use Dorpmaster\Nats\Domain\Connection\ServerAddress;
use Dorpmaster\Nats\Protocol\InfoMessage;
use PHPUnit\Framework\TestCase;

final class InfoConnectUrlsExtractorTest extends TestCase
{
    public function testExtractsConnectUrls(): void
    {
        // Arrange
        $extractor = new InfoConnectUrlsExtractor();
        $info      = $this->createInfoMessage(['n2:4222', 'n3:4222']);

        // Act
        $servers = $extractor->extract($info, false);

        // Assert
        self::assertEquals(
            [
                new ServerAddress('n2', 4222, false),
                new ServerAddress('n3', 4222, false),
            ],
            $servers,
        );
    }

    public function testIgnoresInvalidEntries(): void
    {
        // Arrange
        $extractor = new InfoConnectUrlsExtractor();
        $info      = $this->createInfoMessage([
            'n2:4222',
            'broken',
            'n3:not-port',
            'n4:4333',
        ]);

        // Act
        $servers = $extractor->extract($info, true);

        // Assert
        self::assertEquals(
            [
                new ServerAddress('n2', 4222, true),
                new ServerAddress('n4', 4333, true),
            ],
            $servers,
        );
    }

    /**
     * @param list<string> $connectUrls
     */
    private function createInfoMessage(array $connectUrls): InfoMessage
    {
        return new InfoMessage((string) json_encode([
            'server_id' => 'id',
            'server_name' => 'nats',
            'version' => '1.0.0',
            'go' => 'go1.24',
            'host' => '127.0.0.1',
            'port' => 4222,
            'headers' => true,
            'max_payload' => 1024,
            'proto' => 1,
            'connect_urls' => $connectUrls,
        ]));
    }
}
