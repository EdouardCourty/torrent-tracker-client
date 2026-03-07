<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Tests\Functional;

use Ecourty\TorrentTrackerClient\Exception\TimeoutException;
use Ecourty\TorrentTrackerClient\Transport\UdpTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional')]
final class UdpTransportFunctionalTest extends TestCase
{
    private const string HOST = 'localhost';
    private const int PORT = 6969;

    protected function setUp(): void
    {
        if (!\extension_loaded('sockets')) {
            self::markTestSkipped('ext-sockets not available.');
        }
    }

    public function testOpenSendReceiveClose(): void
    {
        $transport = new UdpTransport();
        $transport->open(self::HOST, self::PORT, 5);

        // BEP 15 connect request: magic(8) + action=0(4) + transaction_id(4)
        $transactionId = random_bytes(4);
        $packet = pack('NN', 0x00000417, 0x27101980)
            . pack('N', 0)
            . $transactionId;

        $transport->send($packet);
        $response = $transport->receive();
        $transport->close();

        // Connect response: action(4) + transaction_id(4) + connection_id(8) = 16 bytes
        self::assertGreaterThanOrEqual(16, \strlen($response));

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($response, 0, 4));
        self::assertSame(0, $unpacked[1], 'Response action must be 0 (connect).');
        self::assertSame($transactionId, substr($response, 4, 4), 'Transaction ID must match.');
    }

    public function testReceiveTimesOutWhenNothingResponds(): void
    {
        $transport = new UdpTransport();
        // Port 9 is the discard protocol — accepts packets but never responds.
        // Any unbound port on localhost would also work (UDP is connectionless).
        $transport->open(self::HOST, 59999, 1);

        $this->expectException(TimeoutException::class);

        try {
            $transport->send(str_repeat("\x00", 16));
            $transport->receive();
        } finally {
            $transport->close();
        }
    }
}
