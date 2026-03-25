<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\Event\Cache;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SportClimbing\EventDetails\Infrastructure\Event\Cache\EventScheduleJsonCache;

final class EventScheduleJsonCacheTest extends TestCase
{
    private ?string $originalCacheFile = null;
    private ?string $originalDisableCacheWrite = null;

    protected function setUp(): void
    {
        $this->originalCacheFile = getenv('IFSC_EVENTS_SCHEDULE_CACHE_FILE') ?: null;
        $this->originalDisableCacheWrite = getenv('IFSC_EVENTS_SCHEDULE_DISABLE_CACHE_WRITE') ?: null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('IFSC_EVENTS_SCHEDULE_CACHE_FILE', $this->originalCacheFile);
        $this->restoreEnv('IFSC_EVENTS_SCHEDULE_DISABLE_CACHE_WRITE', $this->originalDisableCacheWrite);
    }

    public function testSaveWritesEventsPayloadWhenCacheIsEnabled(): void
    {
        $cacheDir = sprintf('%s/ifsc-events-cache-%s', sys_get_temp_dir(), uniqid('', true));
        $cacheFile = "{$cacheDir}/events-with-schedules.json";

        $this->setEnv('IFSC_EVENTS_SCHEDULE_CACHE_FILE', $cacheFile);
        $this->setEnv('IFSC_EVENTS_SCHEDULE_DISABLE_CACHE_WRITE', '0');

        $cache = new EventScheduleJsonCache();
        $cache->save([['event_id' => 1, 'event_name' => 'Test Event']]);

        self::assertFileExists($cacheFile);

        $payload = json_decode((string) file_get_contents($cacheFile), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('updated_at', $payload);
        self::assertSame([['event_id' => 1, 'event_name' => 'Test Event']], $payload['events']);

        $this->removeDirectory($cacheDir);
    }

    public function testSaveSkipsWritingWhenCacheWriteIsDisabled(): void
    {
        $cacheDir = sprintf('%s/ifsc-events-cache-%s', sys_get_temp_dir(), uniqid('', true));
        $cacheFile = "{$cacheDir}/events-with-schedules.json";

        $this->setEnv('IFSC_EVENTS_SCHEDULE_CACHE_FILE', $cacheFile);
        $this->setEnv('IFSC_EVENTS_SCHEDULE_DISABLE_CACHE_WRITE', '1');

        $cache = new EventScheduleJsonCache();
        $cache->save([['event_id' => 1, 'event_name' => 'Test Event']]);

        self::assertFileDoesNotExist($cacheFile);
        $this->removeDirectory($cacheDir);
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());

                continue;
            }

            @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
