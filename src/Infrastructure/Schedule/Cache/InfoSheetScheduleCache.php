<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Schedule\Cache;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use SportClimbing\EventDetails\Infrastructure\Schedule\Exception\InfoSheetChatGptScheduleParserException;
use Throwable;

final class InfoSheetScheduleCache
{
    private const string DEFAULT_CACHE_DIR = '.cache/infosheet';
    private const string CACHE_RESULTS_DIR = 'results';
    private const string CACHE_MANIFEST_FILENAME = 'manifest.json';
    private const int CACHE_FORMAT_VERSION = 1;
    private const string CACHE_PARSER_VERSION = 'v1';
    private const int DEFAULT_LAST_MODIFIED_STALE_DAYS = 21;

    private string $cacheDir;
    private int $lastModifiedStaleDays;

    public function __construct()
    {
        $cacheDir = $this->readEnvironmentVariable('IFSC_INFOSHEET_CACHE_DIR');
        $cacheDir = rtrim($cacheDir !== '' ? $cacheDir : self::DEFAULT_CACHE_DIR, '/');
        $this->cacheDir = $this->resolvePath($cacheDir !== '' ? $cacheDir : self::DEFAULT_CACHE_DIR);

        $staleDays = $this->readEnvironmentVariable('IFSC_INFOSHEET_CACHE_LAST_MODIFIED_DAYS');
        $this->lastModifiedStaleDays = ctype_digit($staleDays)
            ? max(1, (int) $staleDays)
            : self::DEFAULT_LAST_MODIFIED_STALE_DAYS;

        if (!$this->ensureCacheDirectories()) {
            throw new RuntimeException(sprintf('Unable to create infosheet cache directories at "%s".', $this->cacheDir));
        }
    }

    /**
     * @param array<mixed> $infoSheetHeaders
     * @return array{
     *   rounds: array<array{name:mixed,starts_at:mixed,ends_at:mixed}>,
     *   ticket_purchase_url: ?string,
     *   ticket_price: ?string,
     *   ticket_currency: ?string,
     *   ticket_summary: ?string
     * }|null
     */
    public function loadFromHeadersAndHash(array $infoSheetHeaders, ?string $pdfHash): ?array
    {
        $normalizedHeaders = $this->normalizeHeaders($infoSheetHeaders);
        $cacheIds = $this->cacheIdsFromHeadersAndHash($normalizedHeaders, $pdfHash);

        foreach (array_values(array_unique($cacheIds)) as $cacheId) {
            $payload = $this->readPayloadFromCache($cacheId);

            if ($payload !== null) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $infoSheetHeaders
     * @return array{
     *   rounds: array<array{name:mixed,starts_at:mixed,ends_at:mixed}>,
     *   ticket_purchase_url: ?string,
     *   ticket_price: ?string,
     *   ticket_currency: ?string,
     *   ticket_summary: ?string
     * }|null
     */
    public function loadFromUrlIfStale(string $infoSheetUrl, array $infoSheetHeaders): ?array
    {
        $normalizedHeaders = $this->normalizeHeaders($infoSheetHeaders);

        if (!$this->isLastModifiedStale($normalizedHeaders)) {
            return null;
        }

        $urlEntry = $this->readUrlCacheEntry($infoSheetUrl);

        if ($urlEntry === null) {
            return null;
        }

        $cacheId = $this->toStringOrNull($urlEntry['cache_id'] ?? null);

        if ($cacheId === null) {
            return null;
        }

        return $this->readPayloadFromCache($cacheId);
    }

    /**
     * @param array<mixed> $infoSheetHeaders
     * @param array<array{name:mixed,starts_at:mixed,ends_at:mixed}> $rounds
     * @param array{
     *   ticket_purchase_url?: mixed,
     *   ticket_price?: mixed,
     *   ticket_currency?: mixed,
     *   ticket_summary?: mixed
     * } $ticketInfo
     */
    public function store(
        string $infoSheetUrl,
        array $infoSheetHeaders,
        ?string $pdfHash,
        array $rounds,
        array $ticketInfo = [],
    ): void
    {
        $normalizedHeaders = $this->normalizeHeaders($infoSheetHeaders);

        try {
            $cacheIds = $this->cacheIdsFromHeadersAndHash($normalizedHeaders, $pdfHash);

            if ($cacheIds === [] || !$this->ensureCacheDirectories()) {
                return;
            }

            $rounds = $this->normalizeRoundsForCache($rounds);
            $normalizedTicketInfo = $this->normalizeTicketInfoForCache($ticketInfo);
            $updatedAt = (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);

            $payload = [
                'format_version' => self::CACHE_FORMAT_VERSION,
                'parser_cache_version' => self::CACHE_PARSER_VERSION,
                'updated_at' => $updatedAt,
                'rounds' => $rounds,
                'ticket_purchase_url' => $normalizedTicketInfo['ticket_purchase_url'],
                'ticket_price' => $normalizedTicketInfo['ticket_price'],
                'ticket_currency' => $normalizedTicketInfo['ticket_currency'],
                'ticket_summary' => $normalizedTicketInfo['ticket_summary'],
            ];

            foreach ($cacheIds as $cacheId) {
                $this->writeJsonFile($this->cacheResultFilePath($cacheId), $payload);
            }

            $url = trim($infoSheetUrl);

            if ($url === '') {
                return;
            }

            $manifest = $this->readManifest();
            $urls = $manifest['urls'] ?? [];

            if (!is_array($urls)) {
                $urls = [];
            }

            $urls[hash('sha256', $url)] = [
                'url' => $url,
                'cache_id' => $cacheIds[0],
                'etag' => $this->toStringOrNull($normalizedHeaders['etag'] ?? null),
                'last_modified' => $this->toStringOrNull($normalizedHeaders['last-modified'] ?? null),
                'content_length' => $this->toStringOrNull($normalizedHeaders['content-length'] ?? null),
                'pdf_sha256' => $pdfHash,
                'updated_at' => $updatedAt,
            ];

            $manifest['format_version'] = self::CACHE_FORMAT_VERSION;
            $manifest['urls'] = $urls;

            $this->writeJsonFile($this->manifestFilePath(), $manifest);
        } catch (Throwable) {
        }
    }

    public function hashFile(string $pdfPath): ?string
    {
        $hash = @hash_file('sha256', $pdfPath);

        if (!is_string($hash) || trim($hash) === '') {
            return null;
        }

        return $hash;
    }

    /**
     * @param array<string,string> $infoSheetHeaders
     * @return string[]
     */
    private function cacheIdsFromHeadersAndHash(array $infoSheetHeaders, ?string $pdfHash): array
    {
        $cacheIds = [];

        $etag = $this->toStringOrNull($infoSheetHeaders['etag'] ?? null);

        if ($etag !== null) {
            $cacheIds[] = $this->cacheId('etag', $etag);
        }

        $lastModified = $this->toStringOrNull($infoSheetHeaders['last-modified'] ?? null);
        $contentLength = $this->toStringOrNull($infoSheetHeaders['content-length'] ?? null);

        if ($lastModified !== null || $contentLength !== null) {
            $cacheIds[] = $this->cacheId(
                'meta',
                sprintf('%s|%s', $lastModified ?? '', $contentLength ?? ''),
            );
        }

        if ($pdfHash !== null) {
            $cacheIds[] = $this->cacheId('pdf', $pdfHash);
        }

        return array_values(array_unique($cacheIds));
    }

    /**
     * @param array<mixed> $headers
     * @return array<string,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        /** @var array<string,string> $normalized */
        $normalized = [];

        foreach ($headers as $name => $values) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $headerName = strtolower(trim($name));

            foreach ((array) $values as $value) {
                if (!is_scalar($value) || trim((string) $value) === '') {
                    continue;
                }

                $normalizedValue = trim((string) $value);

                if ($headerName === 'etag') {
                    $normalizedValue = $this->normalizeEtag($normalizedValue);
                }

                if ($normalizedValue === '') {
                    continue;
                }

                $normalized[$headerName] = $normalizedValue;
                break;
            }
        }

        return $normalized;
    }

    private function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);

        if ($etag === '') {
            return '';
        }

        $prefix = '';

        if (str_starts_with($etag, 'W/')) {
            $prefix = 'W/';
            $etag = ltrim(substr($etag, 2));
        }

        if (strlen($etag) >= 2 && str_starts_with($etag, '"') && str_ends_with($etag, '"')) {
            $etag = substr($etag, 1, -1);
        }

        $etag = trim($etag);

        return $etag === '' ? '' : "{$prefix}{$etag}";
    }

    /** @return array<string,mixed>|null */
    private function readUrlCacheEntry(string $infoSheetUrl): ?array
    {
        try {
            $url = trim($infoSheetUrl);

            if ($url === '') {
                return null;
            }

            $manifest = $this->readManifest();
            $urls = $manifest['urls'] ?? null;

            if (!is_array($urls)) {
                return null;
            }

            $entry = $urls[hash('sha256', $url)] ?? null;

            return is_array($entry) ? $entry : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,string> $infoSheetHeaders */
    private function isLastModifiedStale(array $infoSheetHeaders): bool
    {
        $lastModified = $this->toStringOrNull($infoSheetHeaders['last-modified'] ?? null);

        if ($lastModified === null) {
            return false;
        }

        $timestamp = strtotime($lastModified);

        if ($timestamp === false) {
            return false;
        }

        return $timestamp <= (time() - ($this->lastModifiedStaleDays * 86400));
    }

    /**
     * @return array{
     *   rounds: array<array{name:mixed,starts_at:mixed,ends_at:mixed}>,
     *   ticket_purchase_url: ?string,
     *   ticket_price: ?string,
     *   ticket_currency: ?string,
     *   ticket_summary: ?string
     * }|null
     */
    private function readPayloadFromCache(string $cacheId): ?array
    {
        try {
            $path = $this->cacheResultFilePath($cacheId);

            if (!is_file($path)) {
                return null;
            }

            $json = @file_get_contents($path);

            if (!is_string($json) || trim($json) === '') {
                return null;
            }

            $payload = $this->decodeJson($json);

            if (($payload['format_version'] ?? null) !== self::CACHE_FORMAT_VERSION ||
                ($payload['parser_cache_version'] ?? null) !== self::CACHE_PARSER_VERSION
            ) {
                return null;
            }

            if (!array_key_exists('rounds', $payload)) {
                return null;
            }

            $rounds = $payload['rounds'];

            if (!is_array($rounds)) {
                return null;
            }

            return [
                'rounds' => $this->normalizeRoundsForCache($rounds),
                'ticket_purchase_url' => $this->toStringOrNull($payload['ticket_purchase_url'] ?? null),
                'ticket_price' => $this->toStringOrNull($payload['ticket_price'] ?? null),
                'ticket_currency' => $this->toStringOrNull($payload['ticket_currency'] ?? null),
                'ticket_summary' => $this->toStringOrNull($payload['ticket_summary'] ?? null),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param mixed $rounds
     * @return array<array{name:mixed,starts_at:mixed,ends_at:mixed}>
     */
    private function normalizeRoundsForCache(mixed $rounds): array
    {
        if (!is_array($rounds)) {
            return [];
        }

        $normalized = [];

        foreach ($rounds as $round) {
            if (!is_array($round)) {
                continue;
            }

            $name = $this->toStringOrNull($round['name'] ?? null);
            $startsAt = $this->toStringOrNull($round['starts_at'] ?? null);
            $endsAt = $round['ends_at'] ?? null;

            if ($name === null || $startsAt === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'starts_at' => $startsAt,
                'ends_at' => is_string($endsAt) && trim($endsAt) !== '' ? trim($endsAt) : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param array{
     *   ticket_purchase_url?: mixed,
     *   ticket_price?: mixed,
     *   ticket_currency?: mixed,
     *   ticket_summary?: mixed
     * } $ticketInfo
     * @return array{
     *   ticket_purchase_url: ?string,
     *   ticket_price: ?string,
     *   ticket_currency: ?string,
     *   ticket_summary: ?string
     * }
     */
    private function normalizeTicketInfoForCache(array $ticketInfo): array
    {
        return [
            'ticket_purchase_url' => $this->toStringOrNull($ticketInfo['ticket_purchase_url'] ?? null),
            'ticket_price' => $this->toStringOrNull($ticketInfo['ticket_price'] ?? null),
            'ticket_currency' => $this->toStringOrNull($ticketInfo['ticket_currency'] ?? null),
            'ticket_summary' => $this->toStringOrNull($ticketInfo['ticket_summary'] ?? null),
        ];
    }

    private function cacheId(string $kind, string $value): string
    {
        return hash('sha256', "{$kind}|{$value}");
    }

    private function ensureCacheDirectories(): bool
    {
        return $this->ensureDirectory($this->cacheDir) &&
            $this->ensureDirectory($this->cacheResultsDirectoryPath());
    }

    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return true;
        }

        return @mkdir($directory, 0777, true) || is_dir($directory);
    }

    private function cacheResultFilePath(string $cacheId): string
    {
        return sprintf('%s/%s.json', $this->cacheResultsDirectoryPath(), $cacheId);
    }

    private function cacheResultsDirectoryPath(): string
    {
        return sprintf('%s/%s', $this->cacheDir, self::CACHE_RESULTS_DIR);
    }

    private function manifestFilePath(): string
    {
        return sprintf('%s/%s', $this->cacheDir, self::CACHE_MANIFEST_FILENAME);
    }

    /** @return array<string,mixed> */
    private function readManifest(): array
    {
        $path = $this->manifestFilePath();

        if (!is_file($path)) {
            return $this->emptyManifest();
        }

        $json = @file_get_contents($path);

        if (!is_string($json) || trim($json) === '') {
            return $this->emptyManifest();
        }

        try {
            $manifest = $this->decodeJson($json);
        } catch (InfoSheetChatGptScheduleParserException) {
            return $this->emptyManifest();
        }

        $urls = $manifest['urls'] ?? null;

        if (!is_array($urls)) {
            $urls = [];
        }

        return [
            'format_version' => self::CACHE_FORMAT_VERSION,
            'urls' => $urls,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyManifest(): array
    {
        return [
            'format_version' => self::CACHE_FORMAT_VERSION,
            'urls' => [],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function writeJsonFile(string $path, array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            @file_put_contents($path, "{$json}\n", LOCK_EX);
        } catch (JsonException) {
        }
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string,mixed>
     * @throws InfoSheetChatGptScheduleParserException
     */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf('Unable to parse JSON payload: %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new InfoSheetChatGptScheduleParserException('JSON payload is not an object');
        }

        return $decoded;
    }

    private function readEnvironmentVariable(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return sprintf('%s/%s', $this->projectRoot(), ltrim($path, '/'));
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
