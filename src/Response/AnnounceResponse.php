<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Response;

readonly class AnnounceResponse
{
    /**
     * @param PeerInfo[] $peers
     */
    public function __construct(
        public int $interval,
        public int $seeders,
        public int $leechers,
        public array $peers,
        public ?int $minInterval = null,
        public ?string $trackerId = null,
        public ?string $warningMessage = null,
    ) {
    }
}
