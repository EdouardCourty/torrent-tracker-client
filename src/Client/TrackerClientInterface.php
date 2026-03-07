<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Client;

use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use Ecourty\TorrentTrackerClient\Response\AnnounceResponse;
use Ecourty\TorrentTrackerClient\Response\ScrapeResponse;

interface TrackerClientInterface
{
    public function announce(AnnounceRequest $request): AnnounceResponse;

    public function scrape(ScrapeRequest $request): ScrapeResponse;
}
