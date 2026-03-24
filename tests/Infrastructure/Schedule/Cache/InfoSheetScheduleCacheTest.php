<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\Schedule\Cache;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SportClimbing\EventDetails\Infrastructure\Schedule\Cache\InfoSheetScheduleCache;

final class InfoSheetScheduleCacheTest extends TestCase
{
    private ?string $originalCacheDir = null;

    protected function setUp(): void
    {
        $this->originalCacheDir = getenv('IFSC_INFOSHEET_CACHE_DIR') ?: null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('IFSC_INFOSHEET_CACHE_DIR', $this->originalCacheDir);
    }

    public function testStoreWritesManifestWithUnquotedEtag(): void
    {
        $cacheDir = sprintf('%s/ifsc-infosheet-cache-%s', sys_get_temp_dir(), uniqid('', true));
        $this->setEnv('IFSC_INFOSHEET_CACHE_DIR', $cacheDir);

        $cache = new InfoSheetScheduleCache();
        $url = 'https://ifsc.results.info/events/1/infosheet.pdf';
        $etag = '"103029a1a68c1909983a3b9f52b85f5d"';

        $cache->store(
            infoSheetUrl: $url,
            infoSheetHeaders: ['etag' => [$etag]],
            pdfHash: null,
            rounds: [[
                'name' => 'Final',
                'starts_at' => '2026-06-20 19:00',
                'ends_at' => null,
            ]],
        );

        $manifestPath = "{$cacheDir}/manifest.json";
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $key = hash('sha256', $url);

        self::assertSame('103029a1a68c1909983a3b9f52b85f5d', $manifest['urls'][$key]['etag']);

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
