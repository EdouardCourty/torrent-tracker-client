<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit;

use Ecourty\TorrentTrackerClient\TrackerClient;
use PHPUnit\Framework\TestCase;

final class TrackerClientTest extends TestCase
{
    public function testInvalidSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrackerClient('ftp://tracker.example.com/announce');
    }

    public function testHttpUrlCreatesClient(): void
    {
        $client = new TrackerClient('http://tracker.example.com/announce');

        // Verify it is instantiated without errors for a valid HTTP URL
        self::assertInstanceOf(TrackerClient::class, $client);
    }

    public function testUdpUrlCreatesClient(): void
    {
        $client = new TrackerClient('udp://tracker.example.com:1337/announce');

        self::assertInstanceOf(TrackerClient::class, $client);
    }

    public function testHttpsUrlCreatesClient(): void
    {
        $client = new TrackerClient('https://tracker.example.com/announce');

        self::assertInstanceOf(TrackerClient::class, $client);
    }
}
