<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Enum;

enum AnnounceEvent: string
{
    case STARTED = 'started';
    case COMPLETED = 'completed';
    case STOPPED = 'stopped';
    case EMPTY = 'empty';

    public function toUdpValue(): int
    {
        return match ($this) {
            self::EMPTY => 0,
            self::COMPLETED => 1,
            self::STARTED => 2,
            self::STOPPED => 3,
        };
    }
}
