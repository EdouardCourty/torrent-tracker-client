<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Request;

readonly class ScrapeRequest
{
    /** @param string[] $infoHashes List of 20-byte binary info_hash strings */
    public function __construct(
        public array $infoHashes,
    ) {
    }
}
