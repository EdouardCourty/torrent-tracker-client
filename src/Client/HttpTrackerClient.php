<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Client;

use Arokettu\Bencode\Bencode;
use Arokettu\Bencode\Bencode\Collection;
use Ecourty\TorrentTrackerClient\Exception\ConnectionException;
use Ecourty\TorrentTrackerClient\Exception\InvalidResponseException;
use Ecourty\TorrentTrackerClient\Exception\TimeoutException;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use Ecourty\TorrentTrackerClient\Response\AnnounceResponse;
use Ecourty\TorrentTrackerClient\Response\PeerInfo;
use Ecourty\TorrentTrackerClient\Response\ScrapeResponse;
use Ecourty\TorrentTrackerClient\Response\TorrentStats;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpTrackerClient implements TrackerClientInterface
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $trackerUrl,
        ?HttpClientInterface $httpClient = null,
        private readonly int $timeout = 5,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => $this->timeout,
            'headers' => ['User-Agent' => 'ecourty/torrent-tracker-client/1.0'],
        ]);
    }

    public function announce(AnnounceRequest $request): AnnounceResponse
    {
        $params = [
            'info_hash' => $request->infoHash,
            'peer_id' => $request->peerId,
            'port' => $request->port,
            'uploaded' => $request->uploaded,
            'downloaded' => $request->downloaded,
            'left' => $request->left,
            'compact' => $request->compact ? 1 : 0,
            'numwant' => $request->numWant,
        ];

        if ($request->event->value !== 'empty') {
            $params['event'] = $request->event->value;
        }

        $query = http_build_query($params);
        // info_hash and peer_id are binary strings: re-encode them with rawurlencode
        $query = preg_replace_callback(
            '/(?:info_hash|peer_id)=([^&]+)/',
            static fn (array $m): string => substr($m[0], 0, strpos($m[0], '=') + 1) . rawurlencode(urldecode($m[1])),
            $query,
        );

        $separator = str_contains($this->trackerUrl, '?') ? '&' : '?';
        $url = $this->trackerUrl . $separator . $query;
        $raw = $this->fetch($url);
        $data = Bencode::decode($raw, dictType: Collection::ARRAY);

        if (!\is_array($data)) {
            throw new InvalidResponseException('Tracker response is not a bencoded dictionary.');
        }

        if (isset($data['failure reason'])) {
            throw new InvalidResponseException('Tracker failure: ' . $this->mixedToString($data['failure reason']));
        }

        $interval = $this->mixedToInt($data['interval'] ?? 0);
        $seeders = $this->mixedToInt($data['complete'] ?? 0);
        $leechers = $this->mixedToInt($data['incomplete'] ?? 0);
        $minInterval = isset($data['min interval']) ? $this->mixedToInt($data['min interval']) : null;
        $trackerId = isset($data['tracker id']) ? $this->mixedToString($data['tracker id']) : null;
        $warningMessage = isset($data['warning message']) ? $this->mixedToString($data['warning message']) : null;

        $peers = $this->parsePeers($data['peers'] ?? '');

        return new AnnounceResponse(
            interval: $interval,
            seeders: $seeders,
            leechers: $leechers,
            peers: $peers,
            minInterval: $minInterval,
            trackerId: $trackerId,
            warningMessage: $warningMessage,
        );
    }

    public function scrape(ScrapeRequest $request): ScrapeResponse
    {
        $scrapeUrl = $this->buildScrapeUrl();

        $query = '?' . implode('&', array_map(
            static fn (string $h): string => 'info_hash=' . rawurlencode($h),
            $request->infoHashes,
        ));

        $raw = $this->fetch($scrapeUrl . $query);
        $data = Bencode::decode($raw, dictType: Collection::ARRAY);

        if (!\is_array($data)) {
            throw new InvalidResponseException('Scrape response is not a bencoded dictionary.');
        }

        if (isset($data['failure reason'])) {
            throw new InvalidResponseException('Tracker failure: ' . $this->mixedToString($data['failure reason']));
        }

        $files = $data['files'] ?? [];
        $torrents = [];

        if (!\is_array($files)) {
            throw new InvalidResponseException('Scrape response "files" field is not an array.');
        }

        foreach ($files as $rawHash => $stats) {
            if (!\is_array($stats)) {
                continue;
            }
            $hexHash = bin2hex((string) $rawHash);
            $torrents[$hexHash] = new TorrentStats(
                seeders: $this->mixedToInt($stats['complete'] ?? 0),
                leechers: $this->mixedToInt($stats['incomplete'] ?? 0),
                completed: $this->mixedToInt($stats['downloaded'] ?? 0),
            );
        }

        return new ScrapeResponse(torrents: $torrents);
    }

    /** @return PeerInfo[] */
    private function parsePeers(mixed $peers): array
    {
        if (\is_string($peers)) {
            return $this->parseCompactPeers($peers);
        }

        if (\is_array($peers)) {
            return $this->parseDictPeers($peers);
        }

        return [];
    }

    /** @return PeerInfo[] */
    private function parseCompactPeers(string $peers): array
    {
        $result = [];
        $count = intdiv(\strlen($peers), 6);

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($peers, $i * 6, 6);
            $ip = implode('.', array_map('ord', str_split(substr($chunk, 0, 4))));
            $port = (\ord($chunk[4]) << 8) | \ord($chunk[5]);
            $result[] = new PeerInfo(ip: $ip, port: $port);
        }

        return $result;
    }

    /**
     * @param array<mixed> $peers
     *
     * @return PeerInfo[]
     */
    private function parseDictPeers(array $peers): array
    {
        $result = [];
        foreach ($peers as $peer) {
            if (\is_array($peer) && isset($peer['ip'], $peer['port'])) {
                $result[] = new PeerInfo(
                    ip: $this->mixedToString($peer['ip']),
                    port: $this->mixedToInt($peer['port']),
                );
            }
        }

        return $result;
    }

    private function buildScrapeUrl(): string
    {
        $parsed = parse_url($this->trackerUrl);
        $path = $parsed['path'] ?? '/';

        if (!str_contains($path, '/announce')) {
            throw new InvalidResponseException(
                \sprintf('Cannot derive scrape URL: tracker path "%s" does not contain "/announce".', $path),
            );
        }

        $scrapePath = (string) preg_replace('#/announce#', '/scrape', $path, 1);

        $url = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '');
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }

        $result = $url . $scrapePath;
        if (isset($parsed['query'])) {
            $result .= '?' . $parsed['query'];
        }

        return $result;
    }

    private function fetch(string $url): string
    {
        try {
            return $this->httpClient->request('GET', $url)->getContent();
        } catch (TransportExceptionInterface $e) {
            $message = $e->getMessage();

            if (str_contains(mb_strtolower($message), 'timed out') || str_contains(mb_strtolower($message), 'timeout')) {
                throw new TimeoutException(
                    \sprintf('HTTP tracker request timed out: %s', $message),
                    previous: $e,
                );
            }

            throw new ConnectionException(
                \sprintf('Failed to connect to HTTP tracker "%s": %s', $url, $message),
                previous: $e,
            );
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException(
                \sprintf(
                    'HTTP tracker "%s" returned client error %d.',
                    $url,
                    $e->getResponse()->getStatusCode(),
                ),
                previous: $e,
            );
        } catch (ServerExceptionInterface $e) {
            throw new ConnectionException(
                \sprintf(
                    'HTTP tracker "%s" returned server error %d.',
                    $url,
                    $e->getResponse()->getStatusCode(),
                ),
                previous: $e,
            );
        } catch (HttpExceptionInterface $e) {
            throw new ConnectionException(
                \sprintf('HTTP tracker "%s" returned an unexpected HTTP error: %s', $url, $e->getMessage()),
                previous: $e,
            );
        }
    }

    private function mixedToInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) || \is_float($value) || \is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function mixedToString(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value) || \is_bool($value)) {
            return (string) $value;
        }

        return '';
    }
}
