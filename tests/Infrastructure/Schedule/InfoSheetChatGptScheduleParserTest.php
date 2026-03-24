<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\Schedule;

use DateTimeZone;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Domain\Schedule\IfscScheduleFactory;
use SportClimbing\EventDetails\Infrastructure\Schedule\Cache\InfoSheetScheduleCache;
use SportClimbing\EventDetails\Infrastructure\Schedule\InfoSheetChatGptScheduleParser;
use SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi\OpenAiInfoSheetClient;

final class InfoSheetChatGptScheduleParserTest extends TestCase
{
    private string $cacheDir;
    private ?string $previousCacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousCacheDir = $_ENV['IFSC_INFOSHEET_CACHE_DIR'] ?? getenv('IFSC_INFOSHEET_CACHE_DIR') ?: null;
        $this->cacheDir = sys_get_temp_dir() . '/ifsc-infosheet-cache-' . uniqid('', true);
        $this->setEnv('IFSC_INFOSHEET_CACHE_DIR', $this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('IFSC_INFOSHEET_CACHE_DIR', $this->previousCacheDir);
        $this->removeDirectory($this->cacheDir);

        parent::tearDown();
    }

    public function testLoadCachedScheduleStripsWrappingQuotesFromRoundNames(): void
    {
        $cache = new InfoSheetScheduleCache();
        $headers = ['Last-Modified' => ['Mon, 01 Jan 2024 00:00:00 GMT']];
        $infosheetUrl = 'https://ifsc.results.info/events/1/infosheet';

        $cache->store(
            infoSheetUrl: $infosheetUrl,
            infoSheetHeaders: $headers,
            pdfHash: null,
            rounds: [
                [
                    'name' => '\'Semi-finals men & women\'',
                    'starts_at' => '2026-06-20 19:00',
                    'ends_at' => null,
                ],
                [
                    'name' => '"Final women"',
                    'starts_at' => '2026-06-20 21:00',
                    'ends_at' => null,
                ],
            ],
            ticketInfo: [
                'ticket_summary' => 'Free entrance for all spectators.',
            ],
        );

        $parser = new InfoSheetChatGptScheduleParser(
            openAiInfoSheetClient: new OpenAiInfoSheetClient(new Client()),
            cache: $cache,
            scheduleFactory: new IfscScheduleFactory(),
        );

        $result = $parser->loadCachedSchedule(
            event: $this->eventInfo(),
            infoSheetUrl: $infosheetUrl,
            infoSheetHeaders: $headers,
        );

        self::assertNotNull($result);
        self::assertCount(2, $result->schedules);
        self::assertSame('Semi-finals men & women', $result->schedules[0]->name);
        self::assertSame('Final women', $result->schedules[1]->name);
        self::assertSame('Free entrance for all spectators.', $result->ticketInfo->summary);
    }

    private function eventInfo(): EventInfo
    {
        return new EventInfo(
            eventId: 1,
            eventName: 'IFSC Event',
            leagueId: 457,
            leagueName: 'World Cups and World Championships',
            leagueSeasonId: 2026,
            localStartDate: '2026-06-19',
            localEndDate: '2026-06-20',
            timeZone: new DateTimeZone('UTC'),
            location: 'Bern',
            country: 'SUI',
            disciplines: ['lead'],
            infosheetUrl: null,
        );
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());

                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
