<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Response;

readonly class PeerInfo
{
    public function __construct(
        public string $ip,
        public int $port,
    ) {
    }
}
