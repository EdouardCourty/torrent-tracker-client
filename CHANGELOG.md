# kodi-nfo-parser Changelog

This file contains information about every addition, update and deletion in the `ecourty/kodi-nfo-parser` library.  
It is recommended to read this file before updating the library to a new version.

## v1.0.0

Initial release of the library.

#### Additions

- Added [`NfoParser`](./src/NfoParser.php) as the main parsing entry point
  - `parseFile(string $path)` — parses an `.nfo` file from disk
  - `parseString(string $content)` — parses raw NFO content
  - `parseParsingNfo(string $url)` — builds a Parsing NFO model from a scraper URL
  - Auto-detects the 3 NFO variants: **Metadata** (XML only), **Parsing** (URL only), **Combination** (XML + trailing URL)
  - Auto-detects the NFO type from the root XML tag

- Added [`NfoSerializer`](./src/NfoSerializer.php) as the serialization entry point
  - `serialize(NfoInterface $nfo): string` — converts any NFO model back to valid XML

- Added support for all 7 Kodi NFO types:
  - [`MovieNfo`](./src/Model/MovieNfo.php)
  - [`TvShowNfo`](./src/Model/TvShowNfo.php)
  - [`EpisodeNfo`](./src/Model/EpisodeNfo.php) — with multi-episode support (multiple `<episodedetails>` blocks)
  - [`ArtistNfo`](./src/Model/ArtistNfo.php)
  - [`AlbumNfo`](./src/Model/AlbumNfo.php)
  - [`MusicVideoNfo`](./src/Model/MusicVideoNfo.php)
  - [`MovieSetNfo`](./src/Model/MovieSetNfo.php)

- Added 14 readonly value objects: [`Actor`](./src/ValueObject/Actor.php), [`AlbumArtistCredits`](./src/ValueObject/AlbumArtistCredits.php), [`AudioStream`](./src/ValueObject/AudioStream.php), [`Fanart`](./src/ValueObject/Fanart.php), [`FileInfo`](./src/ValueObject/FileInfo.php), [`MovieSet`](./src/ValueObject/MovieSet.php), [`NamedSeason`](./src/ValueObject/NamedSeason.php), [`Rating`](./src/ValueObject/Rating.php), [`Resume`](./src/ValueObject/Resume.php), [`StreamDetails`](./src/ValueObject/StreamDetails.php), [`SubtitleStream`](./src/ValueObject/SubtitleStream.php), [`Thumb`](./src/ValueObject/Thumb.php), [`UniqueId`](./src/ValueObject/UniqueId.php), [`VideoStream`](./src/ValueObject/VideoStream.php)

- Added exception hierarchy under [`src/Exception/`](./src/Exception/):
  - `NfoException` — base exception
  - `InvalidNfoException` — malformed or unrecognisable NFO content
  - `UnknownNfoTypeException` — unrecognised root XML tag
  - `NfoFileNotFoundException` — file missing or unreadable
  - `NfoFileReadException` — `file_get_contents` failure

- Added unit tests for all parsers and serializers under [`tests/Unit/`](./tests/Unit/)
