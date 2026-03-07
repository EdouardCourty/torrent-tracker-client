<?php

declare(strict_types=1);

namespace Ecourty\TorrentTrackerClient\Client;

use Ecourty\TorrentTrackerClient\Exception\InvalidResponseException;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Request\ScrapeRequest;
use Ecourty\TorrentTrackerClient\Response\AnnounceResponse;
use Ecourty\TorrentTrackerClient\Response\PeerInfo;
use Ecourty\TorrentTrackerClient\Response\ScrapeResponse;
use Ecourty\TorrentTrackerClient\Response\TorrentStats;
use Ecourty\TorrentTrackerClient\Transport\UdpTransport;
use Ecourty\TorrentTrackerClient\Transport\UdpTransportInterface;

/**
 * UDP tracker client implementing BEP 15.
 * Protocol order: connect → receive connection_id → announce or scrape.
 */
final class UdpTrackerClient implements TrackerClientInterface
{
    private const int MAGIC_CONSTANT = 0x41727101980;
    private const int ACTION_CONNECT = 0;
    private const int ACTION_ANNOUNCE = 1;
    private const int ACTION_SCRAPE = 2;
    private const int ACTION_ERROR = 3;

    private const int DEFAULT_IP = 0;
    private const int TRANSACTION_ID_SIZE = 4;
    private const int KEY_SIZE = 4;
    private const int MAX_SCRAPE_HASHES = 74;
    private const int COMPACT_PEER_SIZE = 6;
    private const int TORRENT_STATS_SIZE = 12;

    // All responses share: action(4 bytes) + transaction_id(4 bytes) header
    private const int RESPONSE_ACTION_OFFSET = 0;
    private const int RESPONSE_TRANSACTION_OFFSET = 4;
    private const int RESPONSE_HEADER_SIZE = 8;

    // Connect response: header(8) + connection_id(8)
    private const int CONNECT_RESPONSE_MIN_SIZE = 16;
    private const int CONNECT_RESP_CONNECTION_ID_OFFSET = 8;

    // Announce response: header(8) + interval(4) + leechers(4) + seeders(4) + peers(…)
    private const int ANNOUNCE_RESPONSE_MIN_SIZE = 20;
    private const int ANNOUNCE_RESP_INTERVAL_OFFSET = 8;
    private const int ANNOUNCE_RESP_LEECHERS_OFFSET = 12;
    private const int ANNOUNCE_RESP_SEEDERS_OFFSET = 16;
    private const int ANNOUNCE_RESP_PEERS_OFFSET = 20;

    private readonly string $host;
    private readonly int $port;

    public function __construct(
        string $trackerUrl,
        private readonly int $timeout = 5,
        private readonly UdpTransportInterface $transport = new UdpTransport(),
    ) {
        $parsed = parse_url($trackerUrl);
        $this->host = $parsed['host'] ?? '';
        $this->port = $parsed['port'] ?? throw new \InvalidArgumentException(
            \sprintf('UDP tracker URL "%s" must include a port number.', $trackerUrl),
        );
    }

    public function announce(AnnounceRequest $request): AnnounceResponse
    {
        $this->transport->open($this->host, $this->port, $this->timeout);

        try {
            $connectionId = $this->connect();

            return $this->sendAnnounce($connectionId, $request);
        } finally {
            $this->transport->close();
        }
    }

    public function scrape(ScrapeRequest $request): ScrapeResponse
    {
        if (\count($request->infoHashes) > self::MAX_SCRAPE_HASHES) {
            throw new \InvalidArgumentException('UDP scrape supports at most 74 info_hashes per request.');
        }

        $this->transport->open($this->host, $this->port, $this->timeout);

        try {
            $connectionId = $this->connect();

            return $this->sendScrape($connectionId, $request);
        } finally {
            $this->transport->close();
        }
    }

    private function connect(): string
    {
        $transactionId = random_bytes(self::TRANSACTION_ID_SIZE);

        // Connect request: 64-bit magic constant (BEP 15), 32-bit action=0, 32-bit transaction_id
        $packet = $this->packInt64(self::MAGIC_CONSTANT) . pack('N', self::ACTION_CONNECT) . $transactionId;

        $this->transport->send($packet);
        $response = $this->transport->receive();

        if (\strlen($response) < self::CONNECT_RESPONSE_MIN_SIZE) {
            throw new InvalidResponseException('UDP connect response too short.');
        }

        $action = $this->unpackUInt32(substr($response, self::RESPONSE_ACTION_OFFSET, 4));
        $respTransactionId = substr($response, self::RESPONSE_TRANSACTION_OFFSET, 4);
        $connectionId = substr($response, self::CONNECT_RESP_CONNECTION_ID_OFFSET, 8);

        if ($action === self::ACTION_ERROR) {
            throw new InvalidResponseException('UDP tracker error: ' . substr($response, 8));
        }

        if ($action !== self::ACTION_CONNECT) {
            throw new InvalidResponseException(\sprintf('Expected action %d, got %d.', self::ACTION_CONNECT, $action));
        }

        if ($respTransactionId !== $transactionId) {
            throw new InvalidResponseException('UDP connect response transaction ID mismatch.');
        }

        return $connectionId;
    }

    private function sendAnnounce(string $connectionId, AnnounceRequest $request): AnnounceResponse
    {
        $transactionId = random_bytes(self::TRANSACTION_ID_SIZE);
        $key = random_bytes(self::KEY_SIZE);

        // BEP 15 announce packet layout (98 bytes total):
        // 8  connection_id
        // 4  action = 1
        // 4  transaction_id
        // 20 info_hash
        // 20 peer_id
        // 8  downloaded
        // 8  left
        // 8  uploaded
        // 4  event
        // 4  ip (0 = default)
        // 4  key
        // 4  num_want
        // 2  port
        $packet = $connectionId
            . pack('N', self::ACTION_ANNOUNCE)
            . $transactionId
            . $request->infoHash
            . $request->peerId
            . $this->packInt64($request->downloaded)
            . $this->packInt64($request->left)
            . $this->packInt64($request->uploaded)
            . pack('N', $request->event->toUdpValue())
            . pack('N', self::DEFAULT_IP)
            . $key
            . pack('N', $request->numWant)
            . pack('n', $request->port);

        $this->transport->send($packet);
        $response = $this->transport->receive();

        if (\strlen($response) < self::ANNOUNCE_RESPONSE_MIN_SIZE) {
            throw new InvalidResponseException('UDP announce response too short.');
        }

        $action = $this->unpackUInt32(substr($response, self::RESPONSE_ACTION_OFFSET, 4));
        $respTransactionId = substr($response, self::RESPONSE_TRANSACTION_OFFSET, 4);

        if ($action === self::ACTION_ERROR) {
            throw new InvalidResponseException('UDP tracker error: ' . substr($response, 8));
        }

        if ($action !== self::ACTION_ANNOUNCE) {
            throw new InvalidResponseException(\sprintf('Expected action %d, got %d.', self::ACTION_ANNOUNCE, $action));
        }

        if ($respTransactionId !== $transactionId) {
            throw new InvalidResponseException('UDP announce response transaction ID mismatch.');
        }

        $interval = $this->unpackUInt32(substr($response, self::ANNOUNCE_RESP_INTERVAL_OFFSET, 4));
        $leechers = $this->unpackUInt32(substr($response, self::ANNOUNCE_RESP_LEECHERS_OFFSET, 4));
        $seeders = $this->unpackUInt32(substr($response, self::ANNOUNCE_RESP_SEEDERS_OFFSET, 4));

        $peers = [];
        $peerData = substr($response, self::ANNOUNCE_RESP_PEERS_OFFSET);
        $peerCount = intdiv(\strlen($peerData), self::COMPACT_PEER_SIZE);

        for ($i = 0; $i < $peerCount; ++$i) {
            $chunk = substr($peerData, $i * self::COMPACT_PEER_SIZE, self::COMPACT_PEER_SIZE);
            $ip = implode('.', array_map('ord', str_split(substr($chunk, 0, 4))));
            $port = (\ord($chunk[4]) << 8) | \ord($chunk[5]);
            $peers[] = new PeerInfo(ip: $ip, port: $port);
        }

        return new AnnounceResponse(
            interval: $interval,
            seeders: $seeders,
            leechers: $leechers,
            peers: $peers,
        );
    }

    private function sendScrape(string $connectionId, ScrapeRequest $request): ScrapeResponse
    {
        $transactionId = random_bytes(self::TRANSACTION_ID_SIZE);

        $packet = $connectionId
            . pack('N', self::ACTION_SCRAPE)
            . $transactionId
            . implode('', $request->infoHashes);

        $this->transport->send($packet);
        $response = $this->transport->receive();

        if (\strlen($response) < self::RESPONSE_HEADER_SIZE) {
            throw new InvalidResponseException('UDP scrape response too short.');
        }

        $action = $this->unpackUInt32(substr($response, self::RESPONSE_ACTION_OFFSET, 4));
        $respTransactionId = substr($response, self::RESPONSE_TRANSACTION_OFFSET, 4);

        if ($action === self::ACTION_ERROR) {
            throw new InvalidResponseException('UDP tracker error: ' . substr($response, 8));
        }

        if ($action !== self::ACTION_SCRAPE) {
            throw new InvalidResponseException(\sprintf('Expected action %d, got %d.', self::ACTION_SCRAPE, $action));
        }

        if ($respTransactionId !== $transactionId) {
            throw new InvalidResponseException('UDP scrape response transaction ID mismatch.');
        }

        $torrents = [];
        $statsData = substr($response, self::RESPONSE_HEADER_SIZE);
        $count = min(intdiv(\strlen($statsData), self::TORRENT_STATS_SIZE), \count($request->infoHashes));

        for ($i = 0; $i < $count; ++$i) {
            $chunk = substr($statsData, $i * self::TORRENT_STATS_SIZE, self::TORRENT_STATS_SIZE);
            $hexHash = bin2hex($request->infoHashes[$i]);
            $torrents[$hexHash] = new TorrentStats(
                seeders: $this->unpackUInt32(substr($chunk, 0, 4)),
                completed: $this->unpackUInt32(substr($chunk, 4, 4)),
                leechers: $this->unpackUInt32(substr($chunk, 8, 4)),
            );
        }

        return new ScrapeResponse(torrents: $torrents);
    }

    private function unpackUInt32(string $binary): int
    {
        /** @var array{1: int}|false $result */
        $result = unpack('N', $binary);
        if ($result === false) {
            throw new InvalidResponseException('Failed to unpack 32-bit integer from binary data.');
        }

        return $result[1];
    }

    private function packInt64(int $value): string
    {
        // Pack as two 32-bit big-endian unsigned integers (high word, low word)
        $high = (int) ($value / 0x100000000);
        $low = $value & 0xFFFFFFFF;

        return pack('NN', $high, $low);
    }
}
