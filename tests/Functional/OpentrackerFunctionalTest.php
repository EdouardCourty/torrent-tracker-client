<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Functional;

use Ecourty\TorrentTrackerClient\Enum\AnnounceEvent;
use Ecourty\TorrentTrackerClient\Exception\ConnectionException;
use Ecourty\TorrentTrackerClient\Exception\TimeoutException;
use Ecourty\TorrentTrackerClient\Exception\TrackerException;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use Ecourty\TorrentTrackerClient\Response\AnnounceResponse;
use Ecourty\TorrentTrackerClient\Response\ScrapeResponse;
use Ecourty\TorrentTrackerClient\TrackerClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional')]
final class OpentrackerFunctionalTest extends TestCase
{
    private const string HTTP_URL = 'http://localhost:6969/announce';
    private const string UDP_URL = 'udp://localhost:6969';

    private string $infoHash;
    private string $peerId;

    protected function setUp(): void
    {
        if (!$this->isTrackerReachable(self::HTTP_URL)) {
            self::markTestSkipped('opentracker not reachable at ' . self::HTTP_URL . '. Run: docker compose up -d');
        }

        $this->infoHash = random_bytes(20);
        $this->peerId = '-TC0001-' . substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(16))), 0, 12);
    }

    public function testHttpAnnounceReturnsValidResponse(): void
    {
        $client = new TrackerClient(self::HTTP_URL);

        $response = $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 0,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::STARTED,
        ));

        self::assertGreaterThan(0, $response->interval);
        self::assertGreaterThanOrEqual(0, \count($response->peers));
    }

    public function testHttpScrapeAfterAnnounceReturnsStats(): void
    {
        $client = new TrackerClient(self::HTTP_URL);

        $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 100,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::COMPLETED,
        ));

        $response = $client->scrape(new ScrapeRequest(
            infoHashes: [$this->infoHash],
        ));

        self::assertArrayHasKey(bin2hex($this->infoHash), $response->torrents);
        self::assertGreaterThanOrEqual(1, $response->torrents[bin2hex($this->infoHash)]->seeders);
    }

    public function testUdpAnnounceReturnsValidResponse(): void
    {
        $this->requireSockets();

        $client = new TrackerClient(self::UDP_URL);

        $response = $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 0,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::STARTED,
        ));

        self::assertGreaterThan(0, $response->interval);
        self::assertGreaterThanOrEqual(0, \count($response->peers));
    }

    public function testUdpScrapeAfterAnnounceReturnsStats(): void
    {
        $this->requireSockets();

        $client = new TrackerClient(self::UDP_URL);

        $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 100,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::COMPLETED,
        ));

        $response = $client->scrape(new ScrapeRequest(
            infoHashes: [$this->infoHash],
        ));

        self::assertArrayHasKey(bin2hex($this->infoHash), $response->torrents);
        self::assertGreaterThanOrEqual(1, $response->torrents[bin2hex($this->infoHash)]->seeders);
    }

    public function testHttpAnnounceStopRemovesPeer(): void
    {
        $client = new TrackerClient(self::HTTP_URL);

        $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 0,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::STARTED,
        ));

        $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 0,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::STOPPED,
        ));

        $scrape = $client->scrape(new ScrapeRequest(infoHashes: [$this->infoHash]));
        $stats = $scrape->torrents[bin2hex($this->infoHash)] ?? null;

        self::assertNotNull($stats);
        self::assertSame(0, $stats->seeders);
        self::assertSame(0, $stats->leechers);
    }

    public function testUdpAnnounceStopRemovesPeer(): void
    {
        $this->requireSockets();

        $client = new TrackerClient(self::UDP_URL);

        $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 0,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::STARTED,
        ));

        $client->announce(new AnnounceRequest(
            infoHash: $this->infoHash,
            peerId: $this->peerId,
            port: 51413,
            uploaded: 0,
            downloaded: 0,
            left: 0,
            event: AnnounceEvent::STOPPED,
        ));

        $scrape = $client->scrape(new ScrapeRequest(infoHashes: [$this->infoHash]));
        $stats = $scrape->torrents[bin2hex($this->infoHash)] ?? null;

        self::assertNotNull($stats);
        self::assertSame(0, $stats->seeders);
        self::assertSame(0, $stats->leechers);
    }

    private function requireSockets(): void
    {
        if (!\extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets not available.');
        }
    }

    private function isTrackerReachable(string $url): bool
    {
        try {
            $client = new TrackerClient($url, timeout: 2);
            $client->announce(new AnnounceRequest(
                infoHash: str_repeat("\x00", 20),
                peerId: str_repeat("\x00", 20),
                port: 1,
                uploaded: 0,
                downloaded: 0,
                left: 0,
                event: AnnounceEvent::STARTED,
            ));

            return true;
        } catch (ConnectionException|TimeoutException) {
            return false;
        } catch (TrackerException) {
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
