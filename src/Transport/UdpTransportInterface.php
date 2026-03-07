<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Transport;

interface UdpTransportInterface
{
    public function open(string $host, int $port, int $timeout): void;

    public function send(string $data): void;

    public function receive(): string;

    public function close(): void;
}
