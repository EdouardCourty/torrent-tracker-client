<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Transport;

use Ecourty\TorrentTrackerClient\Exception\ConnectionException;
use Ecourty\TorrentTrackerClient\Exception\TimeoutException;

final class UdpTransport implements UdpTransportInterface
{
    private const int PACKET_SIZE = 65535;

    private ?\Socket $socket = null;
    private int $timeout = 5;

    public function open(string $host, int $port, int $timeout): void
    {
        if ($timeout <= 0) {
            throw new \InvalidArgumentException(\sprintf('Timeout must be a positive integer, got %d.', $timeout));
        }

        $this->timeout = $timeout;

        $socket = @socket_create(\AF_INET, \SOCK_DGRAM, \SOL_UDP);
        if ($socket === false) {
            throw new ConnectionException('Failed to create UDP socket: ' . socket_strerror(socket_last_error()));
        }

        $ip = gethostbyname($host);
        if ($ip === $host && filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            socket_close($socket);
            throw new ConnectionException(\sprintf('Cannot resolve UDP tracker hostname "%s".', $host));
        }

        if (@socket_connect($socket, $ip, $port) === false) {
            socket_close($socket);
            throw new ConnectionException(\sprintf(
                'Failed to connect to UDP tracker "%s:%d": %s',
                $host,
                $port,
                socket_strerror(socket_last_error()),
            ));
        }

        $this->socket = $socket;
    }

    public function send(string $data): void
    {
        $socket = $this->requireSocket();
        $result = @socket_send($socket, $data, \strlen($data), 0);

        if ($result === false) {
            throw new ConnectionException('UDP send failed: ' . socket_strerror(socket_last_error($socket)));
        }

        if ($result !== \strlen($data)) {
            throw new ConnectionException(\sprintf('UDP send incomplete: sent %d of %d bytes.', $result, \strlen($data)));
        }
    }

    public function receive(): string
    {
        $socket = $this->requireSocket();

        // socket_select is the only cross-platform way to enforce a receive timeout on UDP sockets.
        // SO_RCVTIMEO is unreliable on macOS with ext-sockets.
        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @socket_select($read, $write, $except, $this->timeout);

        if ($ready === false) {
            throw new ConnectionException('UDP select failed: ' . socket_strerror(socket_last_error($socket)));
        }

        if ($ready === 0) {
            throw new TimeoutException('UDP tracker request timed out.');
        }

        $buffer = null;
        /* @var string|null $buffer */
        @socket_recv($socket, $buffer, self::PACKET_SIZE, 0);

        if (!\is_string($buffer)) {
            throw new ConnectionException('UDP receive failed: ' . socket_strerror(socket_last_error($socket)));
        }

        return $buffer;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    private function requireSocket(): \Socket
    {
        if ($this->socket === null) {
            throw new ConnectionException('UDP transport is not open. Call open() first.');
        }

        return $this->socket;
    }
}
