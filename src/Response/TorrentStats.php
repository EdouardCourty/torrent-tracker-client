<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Response;

readonly class TorrentStats
{
    public function __construct(
        public int $seeders,
        public int $leechers,
        public int $completed,
    ) {
    }
}
