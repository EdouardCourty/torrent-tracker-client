<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Response;

readonly class ScrapeResponse
{
    /**
     * @param array<string, TorrentStats> $torrents Keyed by hex-encoded info_hash
     */
    public function __construct(
        public array $torrents,
    ) {
    }
}
