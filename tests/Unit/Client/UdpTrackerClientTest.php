<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Client;

use Ecourty\TorrentTrackerClient\Client\UdpTrackerClient;
use Ecourty\TorrentTrackerClient\Enum\AnnounceEvent;
use Ecourty\TorrentTrackerClient\Exception\InvalidResponseException;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use Ecourty\TorrentTrackerClient\Transport\UdpTransportInterface;
use PHPUnit\Framework\TestCase;

final class UdpTrackerClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Builds a mocked UdpTransport.
     * $capturedPackets is filled by each call to send().
     * $receiveCallback is called each time receive() is invoked.
     *
     * @param list<string> $capturedPackets
     */
    private function makeTransport(array &$capturedPackets, \Closure $receiveCallback): UdpTransportInterface
    {
        $transport = $this->createStub(UdpTransportInterface::class);
        $transport->method('send')->willReturnCallback(
            static function (string $data) use (&$capturedPackets): void {
                $capturedPackets[] = $data;
            },
        );
        $transport->method('receive')->willReturnCallback($receiveCallback);

        return $transport;
    }

    /**
     * Returns a receive callback that handles the connect handshake first,
     * then delegates subsequent calls to $afterConnectCallback.
     *
     * @param list<string>             $capturedPackets
     * @param \Closure(string): string $afterConnectCallback
     */
    private function connectThen(array &$capturedPackets, string $connectionId, \Closure $afterConnectCallback): \Closure
    {
        $callCount = 0;

        return function () use (&$capturedPackets, &$callCount, $connectionId, $afterConnectCallback): string {
            ++$callCount;
            // transaction_id is always at offset 12 (both connect and announce/scrape requests)
            $txnId = substr((string) end($capturedPackets), 12, 4);

            if ($callCount === 1) {
                return pack('N', 0) . $txnId . $connectionId;
            }

            return $afterConnectCallback($txnId);
        };
    }

    private function defaultAnnounceRequest(): AnnounceRequest
    {
        return new AnnounceRequest(
            infoHash: str_repeat("\x01", 20),
            peerId: str_repeat("\x02", 20),
            port: 6881,
        );
    }

    /** Unpacks a big-endian uint32 from $binary at byte $offset. */
    private static function unpackBE32(string $binary, int $offset): int
    {
        /** @var array{1: int} $result */
        $result = unpack('N', substr($binary, $offset, 4));

        return $result[1];
    }

    /** Unpacks a big-endian uint16 from $binary at byte $offset. */
    private static function unpackBE16(string $binary, int $offset): int
    {
        /** @var array{1: int} $result */
        $result = unpack('n', substr($binary, $offset, 2));

        return $result[1];
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    public function testUdpUrlWithoutPortThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must include a port number/');

        new UdpTrackerClient('udp://tracker.example.com');
    }

    // -------------------------------------------------------------------------
    // Announce — happy path
    // -------------------------------------------------------------------------

    public function testAnnounceReturnsCorrectResponse(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 1) . $txnId
                    . pack('N', 1800)  // interval
                    . pack('N', 3)     // leechers
                    . pack('N', 10);   // seeders
            }),
        );

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $response = $client->announce($this->defaultAnnounceRequest());

        self::assertSame(1800, $response->interval);
        self::assertSame(10, $response->seeders);
        self::assertSame(3, $response->leechers);
        self::assertCount(0, $response->peers);
    }

    public function testAnnounceParsesPeers(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $peer1 = pack('CCCC', 192, 168, 1, 1) . pack('n', 6881);
        $peer2 = pack('CCCC', 10, 0, 0, 2) . pack('n', 51413);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId) use ($peer1, $peer2): string {
                return pack('N', 1) . $txnId
                    . pack('N', 900)  // interval
                    . pack('N', 1)    // leechers
                    . pack('N', 5)    // seeders
                    . $peer1 . $peer2;
            }),
        );

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $response = $client->announce($this->defaultAnnounceRequest());

        self::assertCount(2, $response->peers);
        self::assertSame('192.168.1.1', $response->peers[0]->ip);
        self::assertSame(6881, $response->peers[0]->port);
        self::assertSame('10.0.0.2', $response->peers[1]->ip);
        self::assertSame(51413, $response->peers[1]->port);
    }

    public function testAnnouncePacketContainsCorrectFields(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);
        $infoHash = str_repeat("\xAB", 20);
        $peerId = str_repeat("\xCD", 20);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 1) . $txnId . pack('N', 1800) . pack('N', 0) . pack('N', 0);
            }),
        );

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce(new AnnounceRequest(
            infoHash: $infoHash,
            peerId: $peerId,
            port: 6882,
            uploaded: 100,
            downloaded: 200,
            left: 300,
            event: AnnounceEvent::STARTED,
        ));

        // Verify connect packet: magic(8) + action(4) + txnId(4)
        $connectPacket = $capturedPackets[0];
        self::assertSame(16, \strlen($connectPacket));
        self::assertSame(0, self::unpackBE32($connectPacket, 8)); // action=0

        // Verify announce packet layout (98 bytes total per BEP 15)
        $announcePacket = $capturedPackets[1];
        self::assertSame(98, \strlen($announcePacket));
        self::assertSame($connectionId, substr($announcePacket, 0, 8));
        self::assertSame(1, self::unpackBE32($announcePacket, 8)); // action=1
        self::assertSame($infoHash, substr($announcePacket, 16, 20));
        self::assertSame($peerId, substr($announcePacket, 36, 20));
        // downloaded low word (offset 60), left low word (offset 68), uploaded low word (offset 76)
        self::assertSame(200, self::unpackBE32($announcePacket, 60));
        self::assertSame(300, self::unpackBE32($announcePacket, 68));
        self::assertSame(100, self::unpackBE32($announcePacket, 76));
        self::assertSame(2, self::unpackBE32($announcePacket, 80)); // event=Started=2
        self::assertSame(6882, self::unpackBE16($announcePacket, 96)); // port
    }

    // -------------------------------------------------------------------------
    // Announce — error handling
    // -------------------------------------------------------------------------

    public function testAnnounceConnectResponseTooShortThrows(): void
    {
        $capturedPackets = [];

        $transport = $this->makeTransport(
            $capturedPackets,
            static fn (): string => pack('N', 0), // only 4 bytes, needs 16
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/connect response too short/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    public function testAnnounceConnectResponseErrorActionThrows(): void
    {
        $capturedPackets = [];

        $transport = $this->makeTransport(
            $capturedPackets,
            static function () use (&$capturedPackets): string {
                $txnId = substr((string) end($capturedPackets), 12, 4);

                return pack('N', 3) . $txnId . 'error message here';
            },
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/UDP tracker error/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    public function testAnnounceConnectResponseWrongActionThrows(): void
    {
        $capturedPackets = [];

        $transport = $this->makeTransport(
            $capturedPackets,
            static function () use (&$capturedPackets): string {
                $txnId = substr((string) end($capturedPackets), 12, 4);

                return pack('N', 2) . $txnId . str_repeat("\x00", 8);
            },
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/Expected action 0, got 2/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    public function testAnnounceConnectResponseTransactionIdMismatchThrows(): void
    {
        $capturedPackets = [];

        $transport = $this->makeTransport(
            $capturedPackets,
            static fn (): string => pack('N', 0) . "\x00\x00\x00\x00" . str_repeat("\xCC", 8),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/transaction ID mismatch/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    public function testAnnounceResponseTooShortThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 1) . $txnId; // only 8 bytes, needs 20
            }),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/announce response too short/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    public function testAnnounceResponseWrongActionThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 2) . $txnId . pack('N', 0) . pack('N', 0) . pack('N', 0);
            }),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/Expected action 1, got 2/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    public function testAnnounceResponseErrorActionThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 3) . $txnId . 'tracker error';
            }),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/UDP tracker error/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    public function testAnnounceResponseTransactionIdMismatchThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (): string {
                return pack('N', 1) . "\x00\x00\x00\x00"
                    . pack('N', 1800) . pack('N', 0) . pack('N', 0);
            }),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/transaction ID mismatch/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->announce($this->defaultAnnounceRequest());
    }

    // -------------------------------------------------------------------------
    // Scrape — happy path
    // -------------------------------------------------------------------------

    public function testScrapeReturnsCorrectStats(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);
        $infoHash = str_repeat("\xAB", 20);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                // scrape response: action=2, txnId, then per-hash: seeders(4)+completed(4)+leechers(4)
                return pack('N', 2) . $txnId . pack('NNN', 42, 500, 7);
            }),
        );

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $response = $client->scrape(new ScrapeRequest(infoHashes: [$infoHash]));

        $hexHash = bin2hex($infoHash);
        self::assertArrayHasKey($hexHash, $response->torrents);
        self::assertSame(42, $response->torrents[$hexHash]->seeders);
        self::assertSame(500, $response->torrents[$hexHash]->completed);
        self::assertSame(7, $response->torrents[$hexHash]->leechers);
    }

    public function testScrapePacketContainsInfoHashes(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);
        $hash1 = str_repeat("\xAA", 20);
        $hash2 = str_repeat("\xBB", 20);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 2) . $txnId
                    . pack('NNN', 1, 0, 0)  // hash1 stats
                    . pack('NNN', 2, 0, 0); // hash2 stats
            }),
        );

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->scrape(new ScrapeRequest(infoHashes: [$hash1, $hash2]));

        // scrape packet: connectionId(8) + action(4) + txnId(4) + hashes(40)
        $scrapePacket = $capturedPackets[1];
        self::assertSame(56, \strlen($scrapePacket));
        self::assertSame(2, self::unpackBE32($scrapePacket, 8)); // action=2
        self::assertSame($hash1, substr($scrapePacket, 16, 20));
        self::assertSame($hash2, substr($scrapePacket, 36, 20));
    }

    public function testScrapeExtraChunksFromTrackerAreIgnored(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);
        $infoHash = str_repeat("\xAB", 20);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                // tracker returns 3 chunks but only 1 was requested
                return pack('N', 2) . $txnId
                    . pack('NNN', 10, 100, 2)  // chunk 0
                    . pack('NNN', 20, 200, 3)  // chunk 1 (extra — must be ignored)
                    . pack('NNN', 30, 300, 4); // chunk 2 (extra — must be ignored)
            }),
        );

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $response = $client->scrape(new ScrapeRequest(infoHashes: [$infoHash]));

        self::assertCount(1, $response->torrents);
        self::assertSame(10, $response->torrents[bin2hex($infoHash)]->seeders);
    }

    public function testScrapeTooManyHashesThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/74/');

        $capturedPackets = [];
        $transport = $this->makeTransport($capturedPackets, static fn (): string => '');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->scrape(new ScrapeRequest(infoHashes: array_fill(0, 75, str_repeat("\x00", 20))));
    }

    // -------------------------------------------------------------------------
    // Scrape — error handling
    // -------------------------------------------------------------------------

    public function testScrapeResponseTooShortThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static fn (): string => pack('N', 2)),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/scrape response too short/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->scrape(new ScrapeRequest(infoHashes: [str_repeat("\x00", 20)]));
    }

    public function testScrapeResponseWrongActionThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 1) . $txnId; // action=1 instead of 2
            }),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/Expected action 2, got 1/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->scrape(new ScrapeRequest(infoHashes: [str_repeat("\x00", 20)]));
    }

    public function testScrapeResponseErrorActionThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (string $txnId): string {
                return pack('N', 3) . $txnId . 'scrape error';
            }),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/UDP tracker error/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->scrape(new ScrapeRequest(infoHashes: [str_repeat("\x00", 20)]));
    }

    public function testScrapeResponseTransactionIdMismatchThrows(): void
    {
        $capturedPackets = [];
        $connectionId = str_repeat("\xCC", 8);

        $transport = $this->makeTransport(
            $capturedPackets,
            $this->connectThen($capturedPackets, $connectionId, static function (): string {
                return pack('N', 2) . "\x00\x00\x00\x00";
            }),
        );

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/transaction ID mismatch/');

        $client = new UdpTrackerClient('udp://tracker.example.com:1337', 5, $transport);
        $client->scrape(new ScrapeRequest(infoHashes: [str_repeat("\x00", 20)]));
    }
}
