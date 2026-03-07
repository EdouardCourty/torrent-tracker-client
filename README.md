# torrent-tracker-client

A modern PHP 8.3+ library for communicating with BitTorrent trackers over **HTTP** (BEP 3) and **UDP** (BEP 15), supporting both **announce** and **scrape** operations.

## Requirements

- PHP 8.3+
- `ext-sockets` (for UDP trackers)

## Installation

```bash
composer require ecourty/torrent-tracker-client
```

## Usage

### Basic usage

The `TrackerClient` façade auto-detects the protocol from the tracker URL (`http://`, `https://`, or `udp://`).

```php
use Ecourty\TorrentTrackerClient\TrackerClient;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use Ecourty\TorrentTrackerClient\Enum\AnnounceEvent;

$client = new TrackerClient('udp://tracker.opentrackr.org:1337/announce');

// Announce
$response = $client->announce(new AnnounceRequest(
    infoHash: $infoHash,   // 20-byte binary string
    peerId: $peerId,       // 20-byte binary string
    port: 6881,
    uploaded: 0,
    downloaded: 0,
    left: $totalBytes,
    event: AnnounceEvent::STARTED,
));

echo $response->interval;        // re-announce interval in seconds
echo $response->seeders;         // number of seeders
echo $response->leechers;        // number of leechers
foreach ($response->peers as $peer) {
    echo $peer->ip . ':' . $peer->port . PHP_EOL;
}

// Scrape
$scrape = $client->scrape(new ScrapeRequest(infoHashes: [$infoHash]));
$stats = $scrape->torrents[bin2hex($infoHash)];
echo $stats->seeders . ' seeders, ' . $stats->completed . ' completed';
```

### Injecting a custom HTTP client

For HTTP trackers, you can inject any `Symfony\Contracts\HttpClient\HttpClientInterface` — useful when you need a pre-configured client (proxy, retry, authentication, etc.):

```php
use Symfony\Component\HttpClient\HttpClient;

$httpClient = HttpClient::create([
    'timeout' => 10,
    'proxy' => 'http://proxy.example.com:8080',
]);

$client = new TrackerClient(
    trackerUrl: 'http://tracker.example.com/announce',
    httpClient: $httpClient,
);
```

### Direct client usage

You can also instantiate `HttpTrackerClient` or `UdpTrackerClient` directly:

```php
use Ecourty\TorrentTrackerClient\Client\HttpTrackerClient;
use Ecourty\TorrentTrackerClient\Client\UdpTrackerClient;

$http = new HttpTrackerClient('http://tracker.example.com/announce', timeout: 5);
$udp  = new UdpTrackerClient('udp://tracker.opentrackr.org:1337/announce', timeout: 5);
```

## Exceptions

All exceptions extend `Ecourty\TorrentTrackerClient\Exception\TrackerException` (itself extending `\RuntimeException`):

| Exception | When |
|---|---|
| `ConnectionException` | Cannot connect to the tracker |
| `TimeoutException` | Request timed out |
| `InvalidResponseException` | Malformed or failure response |

```php
use Ecourty\TorrentTrackerClient\Exception\TrackerException;

try {
    $response = $client->announce($request);
} catch (TrackerException $e) {
    echo 'Tracker error: ' . $e->getMessage();
}
```

## BEP references

- [BEP 3](https://www.bittorrent.org/beps/bep_0003.html) — HTTP tracker protocol
- [BEP 15](https://www.bittorrent.org/beps/bep_0015.html) — UDP tracker protocol
- [BEP 48](https://www.bittorrent.org/beps/bep_0048.html) — HTTP scrape convention
