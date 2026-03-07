<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Unit\Transport;

use Ecourty\TorrentTrackerClient\Transport\UdpTransport;
use PHPUnit\Framework\TestCase;

final class UdpTransportTest extends TestCase
{
    public function testOpenWithZeroTimeoutThrowsInvalidArgumentException(): void
    {
        $transport = new UdpTransport();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Timeout must be a positive integer/');

        $transport->open('127.0.0.1', 6969, 0);
    }

    public function testOpenWithNegativeTimeoutThrowsInvalidArgumentException(): void
    {
        $transport = new UdpTransport();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Timeout must be a positive integer/');

        $transport->open('127.0.0.1', 6969, -5);
    }
}
