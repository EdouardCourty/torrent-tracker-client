<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Client;

use Arokettu\Bencode\Bencode;
use Ecourty\TorrentTrackerClient\Client\HttpTrackerClient;
use Ecourty\TorrentTrackerClient\Exception\ConnectionException;
use Ecourty\TorrentTrackerClient\Exception\InvalidResponseException;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpTrackerClientParsingTest extends TestCase
{
    public function testParseCompactAnnounceResponse(): void
    {
        $peer1 = pack('CCCC', 192, 168, 1, 1) . pack('n', 6881);
        $peer2 = pack('CCCC', 10, 0, 0, 2) . pack('n', 51413);

        $bencoded = Bencode::encode([
            'interval' => 1800,
            'complete' => 10,
            'incomplete' => 3,
            'peers' => $peer1 . $peer2,
            'min interval' => 900,
            'tracker id' => 'test-tracker',
        ]);

        $client = $this->buildClient($bencoded);
        $response = $client->announce($this->defaultRequest());

        self::assertSame(1800, $response->interval);
        self::assertSame(10, $response->seeders);
        self::assertSame(3, $response->leechers);
        self::assertSame(900, $response->minInterval);
        self::assertSame('test-tracker', $response->trackerId);
        self::assertCount(2, $response->peers);
        self::assertSame('192.168.1.1', $response->peers[0]->ip);
        self::assertSame(6881, $response->peers[0]->port);
        self::assertSame('10.0.0.2', $response->peers[1]->ip);
        self::assertSame(51413, $response->peers[1]->port);
    }

    public function testParseDictAnnounceResponse(): void
    {
        $bencoded = Bencode::encode([
            'interval' => 600,
            'complete' => 5,
            'incomplete' => 1,
            'peers' => [
                ['ip' => '127.0.0.1', 'peer id' => str_repeat("\x00", 20), 'port' => 1234],
            ],
        ]);

        $client = $this->buildClient($bencoded);
        $response = $client->announce($this->defaultRequest(compact: false));

        self::assertSame(600, $response->interval);
        self::assertCount(1, $response->peers);
        self::assertSame('127.0.0.1', $response->peers[0]->ip);
        self::assertSame(1234, $response->peers[0]->port);
    }

    public function testFailureReasonThrowsInvalidResponseException(): void
    {
        $bencoded = Bencode::encode(['failure reason' => 'torrent not found']);

        $client = $this->buildClient($bencoded);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/torrent not found/');

        $client->announce($this->defaultRequest());
    }

    public function testScrapeResponse(): void
    {
        $infoHash = str_repeat("\xAB", 20);
        $bencoded = Bencode::encode([
            'files' => [
                $infoHash => ['complete' => 42, 'incomplete' => 7, 'downloaded' => 500],
            ],
        ]);

        $client = $this->buildClient($bencoded);
        $response = $client->scrape(new ScrapeRequest(infoHashes: [$infoHash]));

        $hexHash = bin2hex($infoHash);
        self::assertArrayHasKey($hexHash, $response->torrents);
        self::assertSame(42, $response->torrents[$hexHash]->seeders);
        self::assertSame(7, $response->torrents[$hexHash]->leechers);
        self::assertSame(500, $response->torrents[$hexHash]->completed);
    }

    private function buildClient(string $responseBody): HttpTrackerClient
    {
        $mockHttpClient = new MockHttpClient(new MockResponse($responseBody));

        return new HttpTrackerClient('http://tracker.example.com/announce', $mockHttpClient);
    }

    private function defaultRequest(bool $compact = true): AnnounceRequest
    {
        return new AnnounceRequest(
            infoHash: str_repeat("\x00", 20),
            peerId: str_repeat("\x01", 20),
            port: 6881,
            compact: $compact,
        );
    }

    public function testClientErrorThrowsConnectionExceptionWithStatusCode(): void
    {
        $mockHttpClient = new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404]));
        $client = new HttpTrackerClient('http://tracker.example.com/announce', $mockHttpClient);

        try {
            $client->announce($this->defaultRequest());
            self::fail('Expected ConnectionException to be thrown.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('404', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'Previous exception must be set to allow access to the raw HTTP response.');
        }
    }

    public function testServerErrorThrowsConnectionExceptionWithStatusCode(): void
    {
        $mockHttpClient = new MockHttpClient(new MockResponse('Internal Server Error', ['http_code' => 500]));
        $client = new HttpTrackerClient('http://tracker.example.com/announce', $mockHttpClient);

        try {
            $client->announce($this->defaultRequest());
            self::fail('Expected ConnectionException to be thrown.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('500', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'Previous exception must be set to allow access to the raw HTTP response.');
        }
    }

    public function testSpaceByteInInfoHashEncodedAsPercent20(): void
    {
        // Byte 0x20 (space) in binary info_hash must appear as %20 in the query string,
        // not as %2B (which happens if rawurldecode is incorrectly used instead of urldecode).
        $infoHash = "\x20" . str_repeat("\x00", 19);

        $capturedUrl = null;
        $mockHttpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse(Bencode::encode([
                'interval' => 1800,
                'complete' => 0,
                'incomplete' => 0,
                'peers' => '',
            ]));
        });

        $client = new HttpTrackerClient('http://tracker.example.com/announce', $mockHttpClient);
        $client->announce(new AnnounceRequest(infoHash: $infoHash, peerId: str_repeat("\x01", 20), port: 6881));

        self::assertIsString($capturedUrl);
        $query = (string) (parse_url($capturedUrl, \PHP_URL_QUERY) ?? '');
        self::assertStringContainsString('info_hash=%20', $query);
        self::assertStringNotContainsString('info_hash=%2B', $query);
    }

    public function testAnnounceWithPasskeyInTrackerUrlDoesNotProduceDoubleQuestionMark(): void
    {
        $capturedUrl = null;
        $mockHttpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse(Bencode::encode([
                'interval' => 1800,
                'complete' => 0,
                'incomplete' => 0,
                'peers' => '',
            ]));
        });

        $client = new HttpTrackerClient('http://tracker.example.com/announce?passkey=secret123', $mockHttpClient);
        $client->announce($this->defaultRequest());

        self::assertIsString($capturedUrl);
        self::assertSame(1, substr_count($capturedUrl, '?'), 'URL must contain exactly one "?"');
        self::assertStringContainsString('passkey=secret123', $capturedUrl);
        self::assertStringContainsString('info_hash=', $capturedUrl);
    }

    public function testScrapeWithPasskeyInTrackerUrlPreservesPasskey(): void
    {
        $infoHash = str_repeat("\xAB", 20);

        $capturedUrl = null;
        $mockHttpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl, $infoHash): MockResponse {
            $capturedUrl = $url;

            return new MockResponse(Bencode::encode([
                'files' => [$infoHash => ['complete' => 5, 'incomplete' => 1, 'downloaded' => 10]],
            ]));
        });

        $client = new HttpTrackerClient('http://tracker.example.com/announce?passkey=secret123', $mockHttpClient);
        $client->scrape(new ScrapeRequest(infoHashes: [$infoHash]));

        self::assertIsString($capturedUrl);
        self::assertStringContainsString('/scrape', $capturedUrl);
        self::assertStringContainsString('passkey=secret123', $capturedUrl);
        self::assertStringContainsString('info_hash=', $capturedUrl);
    }

    public function testScrapeThrowsWhenTrackerUrlHasNoAnnouncePath(): void
    {
        $client = new HttpTrackerClient('http://tracker.example.com/peers', new MockHttpClient());

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('#/announce#');

        $client->scrape(new ScrapeRequest(infoHashes: [str_repeat("\x00", 20)]));
    }

    public function testAnnounceWarningMessageIsForwarded(): void
    {
        $bencoded = Bencode::encode([
            'interval' => 1800,
            'complete' => 5,
            'incomplete' => 1,
            'peers' => '',
            'warning message' => 'low disk space on tracker',
        ]);

        $client = $this->buildClient($bencoded);
        $response = $client->announce($this->defaultRequest());

        self::assertSame('low disk space on tracker', $response->warningMessage);
    }
}
