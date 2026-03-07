<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Request;

use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use PHPUnit\Framework\TestCase;

final class ScrapeRequestTest extends TestCase
{
    public function testHoldsInfoHashes(): void
    {
        $hashes = [str_repeat("\xAA", 20), str_repeat("\xBB", 20)];
        $request = new ScrapeRequest(infoHashes: $hashes);

        self::assertSame($hashes, $request->infoHashes);
        self::assertCount(2, $request->infoHashes);
    }
}
