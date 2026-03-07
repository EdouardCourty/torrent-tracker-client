<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Enum;

use Ecourty\TorrentTrackerClient\Enum\AnnounceEvent;
use PHPUnit\Framework\TestCase;

final class AnnounceEventTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('started', AnnounceEvent::STARTED->value);
        self::assertSame('completed', AnnounceEvent::COMPLETED->value);
        self::assertSame('stopped', AnnounceEvent::STOPPED->value);
        self::assertSame('empty', AnnounceEvent::EMPTY->value);
    }

    public function testUdpValues(): void
    {
        self::assertSame(0, AnnounceEvent::EMPTY->toUdpValue());
        self::assertSame(1, AnnounceEvent::COMPLETED->toUdpValue());
        self::assertSame(2, AnnounceEvent::STARTED->toUdpValue());
        self::assertSame(3, AnnounceEvent::STOPPED->toUdpValue());
    }
}
