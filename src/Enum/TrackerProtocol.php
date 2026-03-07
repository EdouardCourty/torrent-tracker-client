<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Enum;

enum TrackerProtocol
{
    case HTTP;
    case UDP;

    public static function fromUrl(string $url): self
    {
        $scheme = mb_strtolower((string) parse_url($url, \PHP_URL_SCHEME));

        return match ($scheme) {
            'http', 'https' => self::HTTP,
            'udp' => self::UDP,
            default => throw new \InvalidArgumentException(
                \sprintf('Unsupported tracker URL scheme "%s". Expected http, https or udp.', $scheme),
            ),
        };
    }
}
