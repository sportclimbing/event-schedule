<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Event\Cache;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use SportClimbing\EventDetails\Domain\Event\Port\EventScheduleCacheInterface;

final class EventScheduleJsonCache implements EventScheduleCacheInterface
{
    private const string DEFAULT_CACHE_FILE = '.cache/events-with-schedules.json';
    private const string DISABLE_CACHE_WRITE_ENV = 'IFSC_EVENTS_SCHEDULE_DISABLE_CACHE_WRITE';

    private string $cacheFilePath;
    private bool $cacheWriteEnabled;

    public function __construct()
    {
        $configuredPath = $_ENV['IFSC_EVENTS_SCHEDULE_CACHE_FILE'] ?? getenv('IFSC_EVENTS_SCHEDULE_CACHE_FILE');
        $resolvedPath = is_string($configuredPath) && trim($configuredPath) !== ''
            ? trim($configuredPath)
            : self::DEFAULT_CACHE_FILE;
        $this->cacheFilePath = $this->resolvePath($resolvedPath);

        $disableCacheWrite = $_ENV[self::DISABLE_CACHE_WRITE_ENV] ?? getenv(self::DISABLE_CACHE_WRITE_ENV);

        if ($disableCacheWrite === false || $disableCacheWrite === null) {
            $disableCacheWrite = '1';
        }

        $this->cacheWriteEnabled = !$this->isTruthyEnvValue(
            $disableCacheWrite,
        );
    }

    /** @param array<array<string,mixed>> $events */
    public function save(array $events): void
    {
        if (!$this->cacheWriteEnabled) {
            return;
        }

        $directory = dirname($this->cacheFilePath);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create cache directory "%s".', $directory));
        }

        $payload = [
            'updated_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            'events' => $events,
        ];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode events schedule cache JSON.', 0, $exception);
        }

        if (@file_put_contents($this->cacheFilePath, "{$json}\n", LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write cache file "%s".', $this->cacheFilePath));
        }
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

    private function isTruthyEnvValue(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
