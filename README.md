# IFSC Event Details

[![Generate Schedule Release](https://github.com/sportclimbing/event-schedule/actions/workflows/generate-schedule-release.yml/badge.svg)](https://github.com/sportclimbing/event-schedule/actions/workflows/generate-schedule-release.yml)

`sportclimbing/event-details` generates normalized IFSC event data with parsed infosheet schedules and ticket details.

It fetches events from IFSC Results API, downloads infosheet PDFs, extracts round schedules with OpenAI, and outputs a single JSON payload:

- top-level `events` array
- event metadata (name, league, location, timezone, disciplines)
- parsed `schedule` rounds with RFC3339 timestamps
- ticket info (`purchase_url`, `price`, `currency`, `summary`)
- optional `schedule_error` when parsing fails

## Requirements

- PHP `>=8.5`
- Composer
- `ext-curl`
- OpenAI API key (`OPENAI_API_KEY`) for infosheet parsing

## Installation

```bash
composer install
```

## Generate Schedule JSON

Command:

```bash
php bin/generate-schedule \
  --season 2026 \
  --outfile events-with-schedules.json
```

Notes:

- `--season` is required
- `--outfile` is required


- OpenAI runtime options are optional:
  - `--openai-model` (default: `gpt-5-mini`)
  - `--openai-temperature` (default: `0`)
  - `--openai-top-p` (default: `1`)
  - `--openai-http-timeout` (default: `120`)
  - `--openai-http-connect-timeout` (default: `10`)
  - `--openai-http-max-retries` (default: `4`)
  - `--openai-http-retry-backoff-ms` (default: `500`)


- if no `--league` options are provided, all supported league season ids are included
  - world cups (`457`)
  - games (`318`)
  - paraclimbing (`438`)

Useful examples:

```bash
# write to file
php bin/generate-schedule --season 2026 --outfile events-with-schedules.json

# force re-parse infosheets
php bin/generate-schedule --season 2026 --force-rescan --outfile events-with-schedules.json

# only selected leagues
php bin/generate-schedule --season 2026 --league world-cups --league games --outfile events-with-schedules.json
```

## Generate Release Notes (Diff Two JSON Files)

Command:

```bash
php bin/generate-schedule-release-notes \
  --previous previous-events-with-schedules.json \
  --current events-with-schedules.json \
  --outfile release-notes.txt
```

## Environment Variables

- `OPENAI_API_KEY` (required): OpenAI API key used to parse infosheet PDFs
- `IFSC_INFOSHEET_CACHE_DIR` (default: `.cache/infosheet`): parsed infosheet cache directory
- `IFSC_INFOSHEET_PDF_CACHE_DIR` (default: `.cache/infosheet/pdf`): downloaded infosheet PDF directory
- `IFSC_INFOSHEET_CACHE_LAST_MODIFIED_DAYS` (default: `21`): stale-window days for URL-based fallback cache usage
- `IFSC_EVENTS_SCHEDULE_DISABLE_CACHE_WRITE` (default: `1`): disables writing `.cache/events-with-schedules.json` by default
- `IFSC_EVENTS_SCHEDULE_CACHE_FILE` (default: `.cache/events-with-schedules.json`): schedule cache path when cache writing is enabled
- `IFSC_RECENT_SEASON_ID` (default: `38`): fallback season id used by the league provider when no season year is provided

## Testing

```bash
./vendor/bin/phpunit
```

## CI / Release Flow

The GitHub workflow:

- generates `events-with-schedules.json`
- compares with latest release asset
- builds release notes when changed
- creates a new release + `latest` tag when changed
- stores infosheet cache via `actions/cache` (no cache commits to the repository)

## License

MIT
