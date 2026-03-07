<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Request;

use Ecourty\TorrentTrackerClient\Enum\AnnounceEvent;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use PHPUnit\Framework\TestCase;

final class AnnounceRequestTest extends TestCase
{
    public function testDefaults(): void
    {
        $infoHash = str_repeat("\x00", 20);
        $peerId = str_repeat("\x01", 20);

        $request = new AnnounceRequest(
            infoHash: $infoHash,
            peerId: $peerId,
            port: 6881,
        );

        self::assertSame($infoHash, $request->infoHash);
        self::assertSame($peerId, $request->peerId);
        self::assertSame(6881, $request->port);
        self::assertSame(0, $request->uploaded);
        self::assertSame(0, $request->downloaded);
        self::assertSame(0, $request->left);
        self::assertSame(AnnounceEvent::EMPTY, $request->event);
        self::assertSame(50, $request->numWant);
        self::assertTrue($request->compact);
    }

    public function testCustomValues(): void
    {
        $request = new AnnounceRequest(
            infoHash: str_repeat("\xAB", 20),
            peerId: str_repeat("\xCD", 20),
            port: 51413,
            uploaded: 1024,
            downloaded: 2048,
            left: 4096,
            event: AnnounceEvent::STARTED,
            numWant: 100,
            compact: false,
        );

        self::assertSame(1024, $request->uploaded);
        self::assertSame(2048, $request->downloaded);
        self::assertSame(4096, $request->left);
        self::assertSame(AnnounceEvent::STARTED, $request->event);
        self::assertSame(100, $request->numWant);
        self::assertFalse($request->compact);
    }
}
