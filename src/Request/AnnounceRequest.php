<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Request;

use Ecourty\TorrentTrackerClient\Enum\AnnounceEvent;

readonly class AnnounceRequest
{
    public function __construct(
        public string $infoHash,
        public string $peerId,
        public int $port,
        public int $uploaded = 0,
        public int $downloaded = 0,
        public int $left = 0,
        public AnnounceEvent $event = AnnounceEvent::EMPTY,
        public int $numWant = 50,
        public bool $compact = true,
    ) {
    }
}
