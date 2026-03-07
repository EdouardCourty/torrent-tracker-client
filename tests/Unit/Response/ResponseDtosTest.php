<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Response;

use Ecourty\TorrentTrackerClient\Response\AnnounceResponse;
use Ecourty\TorrentTrackerClient\Response\PeerInfo;
use Ecourty\TorrentTrackerClient\Response\ScrapeResponse;
use Ecourty\TorrentTrackerClient\Response\TorrentStats;
use PHPUnit\Framework\TestCase;

final class ResponseDtosTest extends TestCase
{
    public function testPeerInfo(): void
    {
        $peer = new PeerInfo(ip: '192.168.1.100', port: 6881);

        self::assertSame('192.168.1.100', $peer->ip);
        self::assertSame(6881, $peer->port);
    }

    public function testTorrentStats(): void
    {
        $stats = new TorrentStats(seeders: 10, leechers: 5, completed: 100);

        self::assertSame(10, $stats->seeders);
        self::assertSame(5, $stats->leechers);
        self::assertSame(100, $stats->completed);
    }

    public function testAnnounceResponse(): void
    {
        $peer = new PeerInfo(ip: '10.0.0.1', port: 1234);
        $response = new AnnounceResponse(
            interval: 1800,
            seeders: 25,
            leechers: 3,
            peers: [$peer],
            minInterval: 900,
            trackerId: 'tracker-abc',
            warningMessage: 'test warning',
        );

        self::assertSame(1800, $response->interval);
        self::assertSame(25, $response->seeders);
        self::assertSame(3, $response->leechers);
        self::assertCount(1, $response->peers);
        self::assertSame($peer, $response->peers[0]);
        self::assertSame(900, $response->minInterval);
        self::assertSame('tracker-abc', $response->trackerId);
        self::assertSame('test warning', $response->warningMessage);
    }

    public function testAnnounceResponseNullableDefaults(): void
    {
        $response = new AnnounceResponse(interval: 300, seeders: 1, leechers: 0, peers: []);

        self::assertNull($response->minInterval);
        self::assertNull($response->trackerId);
        self::assertNull($response->warningMessage);
    }

    public function testScrapeResponse(): void
    {
        $hash = bin2hex(str_repeat("\xAB", 20));
        $stats = new TorrentStats(seeders: 42, leechers: 7, completed: 500);
        $response = new ScrapeResponse(torrents: [$hash => $stats]);

        self::assertArrayHasKey($hash, $response->torrents);
        self::assertSame($stats, $response->torrents[$hash]);
    }
}
