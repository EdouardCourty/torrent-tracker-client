<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient;

use Ecourty\TorrentTrackerClient\Client\HttpTrackerClient;
use Ecourty\TorrentTrackerClient\Client\TrackerClientInterface;
use Ecourty\TorrentTrackerClient\Client\UdpTrackerClient;
use Ecourty\TorrentTrackerClient\Enum\TrackerProtocol;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use Ecourty\TorrentTrackerClient\Response\AnnounceResponse;
use Ecourty\TorrentTrackerClient\Response\ScrapeResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TrackerClient
{
    private readonly TrackerClientInterface $client;

    public function __construct(
        string $trackerUrl,
        int $timeout = 5,
        ?HttpClientInterface $httpClient = null,
    ) {
        $protocol = TrackerProtocol::fromUrl($trackerUrl);

        $this->client = match ($protocol) {
            TrackerProtocol::HTTP => new HttpTrackerClient($trackerUrl, $httpClient, $timeout),
            TrackerProtocol::UDP => new UdpTrackerClient($trackerUrl, $timeout),
        };
    }

    public function announce(AnnounceRequest $request): AnnounceResponse
    {
        return $this->client->announce($request);
    }

    public function scrape(ScrapeRequest $request): ScrapeResponse
    {
        return $this->client->scrape($request);
    }
}
