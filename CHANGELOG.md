# ecourty/torrent-tracker-client Changelog

This file documents every notable change to `ecourty/torrent-tracker-client`.

## v1.0.0

Initial release.

### Added

- **`TrackerClient`** — single entry point. Auto-detects `http://`, `https://`, or `udp://` from the tracker URL and delegates to the appropriate client.

- **`HttpTrackerClient`** — HTTP tracker support (BEP 3 + BEP 48):
  - `announce()` — builds query string with correctly URL-encoded binary `info_hash` and `peer_id`, parses bencoded responses, supports both compact (6-byte) and dictionary peer formats
  - `scrape()` — derives the scrape URL from the announce URL, preserves query string parameters (e.g. private tracker passkeys)
  - Injects any `Symfony\Contracts\HttpClient\HttpClientInterface` for proxy, retry, or custom configuration
  - Distinguishes HTTP 4xx (client errors) from 5xx (server errors) with the HTTP status code in the exception message; `getPrevious()` gives access to the original Symfony exception and its response

- **`UdpTrackerClient`** — UDP tracker support (BEP 15):
  - Implements the full connect → announce/scrape handshake
  - Correct 98-byte announce packet layout (big-endian, 64-bit fields split as high/low 32-bit words)
  - Transaction ID verification on every response (prevents spoofing)
  - Scrape capped at 74 info_hashes per request per spec
  - Accepts a `UdpTransportInterface` for full unit-test coverage without real sockets

- **`UdpTransport`** — raw UDP socket layer (`ext-sockets`):
  - `open()` / `send()` / `receive()` / `close()`
  - Timeout enforced via `socket_select` (reliable on all platforms including macOS)
  - Validates that timeout is a positive integer

- **Request DTOs** (`readonly`): `AnnounceRequest`, `ScrapeRequest`
- **Response DTOs** (`readonly`): `AnnounceResponse`, `ScrapeResponse`, `PeerInfo`, `TorrentStats`
- **Enums**: `AnnounceEvent` (Started / Stopped / Completed / Empty), `TrackerProtocol` (Http / Udp)
- **Exception hierarchy** under `TrackerException extends \RuntimeException`:
  - `ConnectionException` — socket or HTTP-level failure
  - `TimeoutException` — request timed out
  - `InvalidResponseException` — malformed or failure response
