<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Enum;

use Ecourty\TorrentTrackerClient\Enum\TrackerProtocol;
use PHPUnit\Framework\TestCase;

final class TrackerProtocolTest extends TestCase
{
    public function testHttpDetection(): void
    {
        self::assertSame(TrackerProtocol::HTTP, TrackerProtocol::fromUrl('http://tracker.example.com/announce'));
        self::assertSame(TrackerProtocol::HTTP, TrackerProtocol::fromUrl('https://tracker.example.com/announce'));
    }

    public function testUdpDetection(): void
    {
        self::assertSame(TrackerProtocol::UDP, TrackerProtocol::fromUrl('udp://tracker.example.com:1337/announce'));
    }

    public function testUnknownSchemeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ftp/');

        TrackerProtocol::fromUrl('ftp://tracker.example.com/announce');
    }
}
