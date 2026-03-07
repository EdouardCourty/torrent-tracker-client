# AGENTS.md - Coding Guidelines for AI Agents

## 🎯 Core Concept

### Problem Solved

Interacting with BitTorrent trackers from PHP requires implementing low-level binary protocols (UDP/BEP 15) or parsing bencoded HTTP responses. No modern, well-architected PHP library exists for this. This library fills that gap.

### Solution

A standalone PHP 8.3+ library (`ecourty/torrent-tracker-client`) that provides a clean, typed API to communicate with BitTorrent trackers over both **HTTP** (BEP 3) and **UDP** (BEP 15). Supports **announce** and **scrape** operations. The single entry point `TrackerClient` auto-detects the protocol from the tracker URL.

---

## 🏗️ Architecture

### Overview

```
TrackerClient          → auto-detects http:// vs udp://, delegates to the right client
src/Client/            → TrackerClientInterface, HttpTrackerClient (BEP 3), UdpTrackerClient (BEP 15)
src/Transport/         → UdpTransport (raw socket I/O)
src/Request/           → AnnounceRequest, ScrapeRequest (readonly DTOs)
src/Response/          → AnnounceResponse, ScrapeResponse, PeerInfo, TorrentStats (readonly DTOs)
src/Enum/              → AnnounceEvent, TrackerProtocol
src/Exception/         → TrackerException hierarchy
```

### Main Components

- **`TrackerClient`** — public entry point. Parses the tracker URL, detects the protocol, instantiates and calls the appropriate client.
- **`HttpTrackerClient`** — implements `TrackerClientInterface`. HTTP tracker announce (BEP 3) and scrape (BEP 48) via `symfony/http-client`. Accepts an optional `HttpClientInterface` in its constructor for injection.
- **`UdpTrackerClient`** — implements `TrackerClientInterface`. Pure BEP 15 protocol logic: connect handshake, announce, scrape packet building and parsing. Delegates all socket I/O to `UdpTransport`. Accepts an optional `UdpTransport` in its constructor for injection.
- **`UdpTransport`** — raw UDP socket layer (`ext-sockets`). Handles `open()`, `send()`, `receive()`, `close()`. No BEP 15 knowledge.
- **`src/Request/`** — `AnnounceRequest` and `ScrapeRequest`, both `readonly`.
- **`src/Response/`** — `AnnounceResponse`, `ScrapeResponse`, `PeerInfo`, `TorrentStats`, all `readonly`.
- **`src/Enum/AnnounceEvent`** — `Started`, `Stopped`, `Completed`, `Empty`.
- **`src/Enum/TrackerProtocol`** — `Http`, `Udp`.
- **`TrackerClientInterface`** — internal interface implemented by both clients, used to type `TrackerClient::$client` properly. Not intended as a public extension point.

---

## 🚀 Typical Use Cases

```php
use Ecourty\TorrentTrackerClient\TrackerClient;
use Ecourty\TorrentTrackerClient\Request\AnnounceRequest;
use Ecourty\TorrentTrackerClient\Enum\AnnounceEvent;

// Announce to an HTTP tracker
$client = new TrackerClient('http://tracker.opentrackr.org/announce');
$response = $client->announce(new AnnounceRequest(
    infoHash: '...',
    peerId: '...',
    port: 6881,
    uploaded: 0,
    downloaded: 0,
    left: 1000,
    event: AnnounceEvent::STARTED,
));

// Scrape from a UDP tracker
$client = new TrackerClient('udp://tracker.openbittorrent.com:6969');
$response = $client->scrape(['<info_hash_1>', '<info_hash_2>']);
foreach ($response->torrents as $hash => $stats) {
    echo "{$hash}: {$stats->seeders} seeders, {$stats->leechers} leechers\n";
}
```

---

## 💡 Design Patterns Used

- **Readonly DTOs** — all request/response objects are `readonly`, constructed once, never mutated
- **Auto-detection** — `TrackerClient` inspects the URL scheme to pick `HttpTrackerClient` or `UdpTrackerClient` transparently
- **No public extension interface** — `TrackerClientInterface` is internal. HTTP and UDP clients are not meant to be extended by consumers.
- **Single responsibility** — `TrackerClient` only routes; each client only handles its own protocol
- **Transport interface** — `UdpTransportInterface` decouples the UDP protocol logic from raw socket I/O, enabling full unit test coverage of `UdpTrackerClient` without real sockets

---

## Project Breakdown

```
src/
├── TrackerClient.php              Entry point — protocol detection + delegation
├── Client/
│   ├── TrackerClientInterface.php Internal interface for both clients
│   ├── HttpTrackerClient.php      BEP 3 + BEP 48 — HTTP announce & scrape
│   └── UdpTrackerClient.php       BEP 15 — protocol logic only (no socket calls)
└── Transport/
    ├── UdpTransportInterface.php  Interface for UDP socket I/O (enables mocking in tests)
    └── UdpTransport.php           Raw UDP socket I/O (open/send/receive/close)
├── Enum/
│   ├── AnnounceEvent.php          Started | Stopped | Completed | Empty
│   └── TrackerProtocol.php        Http | Udp
├── Request/
│   ├── AnnounceRequest.php        info_hash, peer_id, port, uploaded, downloaded, left, event
│   └── ScrapeRequest.php          info_hashes[]
├── Response/
│   ├── AnnounceResponse.php       interval, peers[], warning?
│   ├── ScrapeResponse.php         TorrentStats[] indexed by info_hash
│   ├── PeerInfo.php               ip, port, peer_id?
│   └── TorrentStats.php           seeders, leechers, downloaded
└── Exception/
    ├── TrackerException.php       Base exception (extends RuntimeException)
    ├── ConnectionException.php    Socket/HTTP connection failure
    ├── InvalidResponseException.php Malformed or unexpected response
    └── TimeoutException.php       Request timed out
```

**IMPORTANT**: This section should evolve with the project. When a new feature is created, updated or removed, this section should too.

---

## 🧪 Testing

Tests live in `tests/Unit/`. Run tests: `composer test`.

When adding a new feature:
1. Add or update the relevant test in `tests/Unit/`
2. Use mocking for HTTP responses and UDP sockets — never hit real trackers in tests

---

## Remarks & Guidelines

### General

- NEVER commit or push the git repository.
- When unsure about something, you MUST ask the user for clarification. Same goes if the user request is unclear.
- When facing a problem that has an easy "hacky" solution, and a more robust but more difficult to implement one, always choose the robust one.
- ALWAYS write tests for the important components.
- Do NOT write ANY type documentation unless explicitly asked.
- Once a feature is complete, update the @README.md and @AGENTS.md accordingly.
- The @README.md file should consist of a project overview for end-users, not a technical explanation of the project.

### Key Implementation Rules

- **UDP protocol order** — always: connect request → connect response (get `connection_id`) → announce/scrape request. Never skip the connect step.
- **UDP binary packing** — use `pack()`/`unpack()` with explicit format strings. Document the format string against the BEP spec.
- **Bencode decoding** — use `arokettu/bencode` for HTTP responses, never hand-roll a bencode parser.
- **info_hash encoding** — info_hashes are 20-byte binary strings; URL-encode them with `rawurlencode()` for HTTP, send raw for UDP.
- **Timeouts** — always apply timeouts to both HTTP (`stream_context_create`) and UDP (socket options). Default: 5 seconds.
- **`readonly` everywhere** — all DTOs (Request and Response) must be `readonly` classes.

## 📚 References

- **Source code**: `/src`
- **Tests**: `/tests`
- **README**: User documentation
- **BEP 3** (HTTP tracker): https://www.bittorrent.org/beps/bep_0003.html
- **BEP 15** (UDP tracker): https://www.bittorrent.org/beps/bep_0015.html
- **BEP 48** (HTTP scrape): https://www.bittorrent.org/beps/bep_0048.html
