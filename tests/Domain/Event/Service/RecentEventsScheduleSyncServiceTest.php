<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Domain\Event\Service;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Domain\Event\Entity\League;
use SportClimbing\EventDetails\Domain\Event\Port\EventInfoProviderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\EventScheduleCacheInterface;
use SportClimbing\EventDetails\Domain\Event\Port\InfoSheetPdfDownloaderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\RecentLeagueProviderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\DownloadedPdf;
use SportClimbing\EventDetails\Domain\Event\Service\RecentEventsScheduleSyncService;
use SportClimbing\EventDetails\Domain\Schedule\Exception\InfoSheetScheduleParserException;
use SportClimbing\EventDetails\Domain\Schedule\IfscSchedule;
use SportClimbing\EventDetails\Domain\Schedule\InfoSheetParseResult;
use SportClimbing\EventDetails\Domain\Schedule\InfoSheetTicketInfo;
use SportClimbing\EventDetails\Domain\Schedule\Port\InfoSheetScheduleParserInterface;

final class RecentEventsScheduleSyncServiceTest extends TestCase
{
    public function testSyncUsesCachedScheduleWhenPdfHasNotChanged(): void
    {
        $leagueProvider = new FakeRecentLeagueProvider([457]);
        $eventInfoProvider = new FakeEventInfoProvider([
            $this->eventInfo('https://ifsc.results.info/events/1/infosheet'),
        ]);
        $pdfDownloader = new FakeInfoSheetPdfDownloader();
        $scheduleParser = new FakeInfoSheetScheduleParser(
            cachedSchedulesByUrl: [
                'https://ifsc.results.info/events/1/infosheet' => [
                    new IfscSchedule(
                        name: 'Final',
                        startsAt: new DateTimeImmutable('2026-06-20 19:00:00', new DateTimeZone('UTC')),
                        endsAt: null,
                    ),
                ],
            ],
            parsedSchedulesByUrl: [],
        );
        $cacheWriter = new RecordingEventScheduleCache();

        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: $leagueProvider,
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: $pdfDownloader,
            infoSheetScheduleParser: $scheduleParser,
            eventScheduleCache: $cacheWriter,
        );

        $result = $service->sync(2026);

        self::assertSame([457], $eventInfoProvider->lastLeagues);
        self::assertSame(1, $scheduleParser->loadCalls);
        self::assertSame(0, $scheduleParser->parseCalls);
        self::assertCount(1, $result);
        self::assertSame([
            'name' => 'Innsbruck',
            'country' => 'Austria',
            'country_code' => 'AUT',
        ], $result[0]['location']);
        self::assertArrayNotHasKey('country', $result[0]);
        self::assertSame([
            'purchase_url' => null,
            'price' => null,
            'currency' => null,
            'summary' => null,
        ], $result[0]['tickets']);
        self::assertSame(['men', 'women'], $result[0]['categories']);
        self::assertArrayNotHasKey('ticket_url', $result[0]);
        self::assertArrayNotHasKey('ticket_price', $result[0]);
        self::assertSame('Final', $result[0]['schedule'][0]['name']);
        self::assertSame($result, $cacheWriter->savedEvents);
    }

    public function testSyncParsesScheduleWhenNoCachedScheduleExists(): void
    {
        $eventInfoProvider = new FakeEventInfoProvider([
            $this->eventInfo('https://ifsc.results.info/events/2/infosheet'),
        ]);
        $scheduleParser = new FakeInfoSheetScheduleParser(
            cachedSchedulesByUrl: [],
            parsedSchedulesByUrl: [
                'https://ifsc.results.info/events/2/infosheet' => [
                    new IfscSchedule(
                        name: 'Semi-final',
                        startsAt: new DateTimeImmutable('2026-06-19 17:00:00', new DateTimeZone('UTC')),
                        endsAt: null,
                    ),
                ],
            ],
            parsedTicketInfoByUrl: [
                'https://ifsc.results.info/events/2/infosheet' => new InfoSheetTicketInfo(
                    purchaseUrl: 'https://tickets.example.com/event-2',
                    price: '35',
                    currency: 'USD',
                    summary: 'Entry is paid. Buy tickets online before arrival.',
                ),
            ],
        );
        $cacheWriter = new RecordingEventScheduleCache();

        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: $scheduleParser,
            eventScheduleCache: $cacheWriter,
        );

        $result = $service->sync(2026);

        self::assertSame(1, $scheduleParser->loadCalls);
        self::assertSame(1, $scheduleParser->parseCalls);
        self::assertSame('Semi-final', $result[0]['schedule'][0]['name']);
        self::assertSame('Entry is paid. Buy tickets online before arrival.', $result[0]['tickets']['summary']);
        self::assertSame($result, $cacheWriter->savedEvents);
    }

    public function testSyncIncludesSelectedLeagueSeasonIdsWhenRecentLeagueProviderOmitsThem(): void
    {
        $eventInfoProvider = new FakeEventInfoProvider([]);
        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: new FakeInfoSheetScheduleParser(
                cachedSchedulesByUrl: [],
                parsedSchedulesByUrl: [],
            ),
            eventScheduleCache: new RecordingEventScheduleCache(),
        );

        $service->sync(seasonYear: 2026, leagueSeasonIds: [318, 438]);

        self::assertSame([457], $eventInfoProvider->lastLeagues);
    }

    public function testSyncMapsRequestedLeagueCategoriesToCurrentSeasonLeagueIdsFromLeagueNames(): void
    {
        $eventInfoProvider = new FakeEventInfoProvider([]);
        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([
                new League(457, 'World Cups and World Championships'),
                new League(463, 'Games'),
                new League(469, 'IFSC Paraclimbing'),
                new League(458, 'IFSC Youth'),
            ]),
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: new FakeInfoSheetScheduleParser(
                cachedSchedulesByUrl: [],
                parsedSchedulesByUrl: [],
            ),
            eventScheduleCache: new RecordingEventScheduleCache(),
        );

        $service->sync(seasonYear: 2026);

        self::assertEquals([
            new League(457, 'World Cups and World Championships'),
            new League(463, 'Games'),
            new League(469, 'IFSC Paraclimbing'),
        ], $eventInfoProvider->lastLeagues);
    }

    public function testSyncConvertsZeroTicketPriceToNull(): void
    {
        $eventInfoProvider = new FakeEventInfoProvider([
            $this->eventInfo('https://ifsc.results.info/events/20/infosheet'),
        ]);
        $scheduleParser = new FakeInfoSheetScheduleParser(
            cachedSchedulesByUrl: [],
            parsedSchedulesByUrl: [
                'https://ifsc.results.info/events/20/infosheet' => [],
            ],
            parsedTicketInfoByUrl: [
                'https://ifsc.results.info/events/20/infosheet' => new InfoSheetTicketInfo(
                    purchaseUrl: 'https://tickets.example.com/event-20',
                    price: '0',
                    currency: 'USD',
                    summary: 'Free entrance.',
                ),
            ],
        );
        $cacheWriter = new RecordingEventScheduleCache();

        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: $scheduleParser,
            eventScheduleCache: $cacheWriter,
        );

        $result = $service->sync(2026);

        self::assertNull($result[0]['tickets']['price']);
        self::assertSame('USD', $result[0]['tickets']['currency']);
    }

    public function testSyncWithForceRescanIgnoresCachedSchedule(): void
    {
        $eventInfoProvider = new FakeEventInfoProvider([
            $this->eventInfo('https://ifsc.results.info/events/3/infosheet'),
        ]);
        $scheduleParser = new FakeInfoSheetScheduleParser(
            cachedSchedulesByUrl: [
                'https://ifsc.results.info/events/3/infosheet' => [
                    new IfscSchedule(
                        name: 'Cached Final',
                        startsAt: new DateTimeImmutable('2026-06-21 19:00:00', new DateTimeZone('UTC')),
                        endsAt: null,
                    ),
                ],
            ],
            parsedSchedulesByUrl: [
                'https://ifsc.results.info/events/3/infosheet' => [
                    new IfscSchedule(
                        name: 'Fresh Final',
                        startsAt: new DateTimeImmutable('2026-06-21 20:00:00', new DateTimeZone('UTC')),
                        endsAt: null,
                    ),
                ],
            ],
        );
        $cacheWriter = new RecordingEventScheduleCache();

        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: $scheduleParser,
            eventScheduleCache: $cacheWriter,
        );

        $result = $service->sync(seasonYear: 2026, forceRescan: true);

        self::assertSame(0, $scheduleParser->loadCalls);
        self::assertSame(1, $scheduleParser->parseCalls);
        self::assertSame([true], $scheduleParser->parseForceRescanFlags);
        self::assertSame('Fresh Final', $result[0]['schedule'][0]['name']);
        self::assertSame($result, $cacheWriter->savedEvents);
    }

    public function testSyncSkipsInfosheetParsingWhenUrlIsMissing(): void
    {
        $pdfDownloader = new FakeInfoSheetPdfDownloader();
        $scheduleParser = new FakeInfoSheetScheduleParser(
            cachedSchedulesByUrl: [],
            parsedSchedulesByUrl: [],
        );
        $cacheWriter = new RecordingEventScheduleCache();

        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: new FakeEventInfoProvider([
                $this->eventInfo(null),
            ]),
            infoSheetPdfDownloader: $pdfDownloader,
            infoSheetScheduleParser: $scheduleParser,
            eventScheduleCache: $cacheWriter,
        );

        $result = $service->sync(2026);

        self::assertSame(0, $pdfDownloader->downloadCalls);
        self::assertSame(0, $scheduleParser->loadCalls);
        self::assertSame(0, $scheduleParser->parseCalls);
        self::assertSame([], $result[0]['schedule']);
    }

    public function testSyncKeepsEventAndStoresScheduleErrorWhenParsingFails(): void
    {
        $eventInfoProvider = new FakeEventInfoProvider([
            $this->eventInfo('https://ifsc.results.info/events/4/infosheet'),
        ]);
        $scheduleParser = new FakeInfoSheetScheduleParser(
            cachedSchedulesByUrl: [],
            parsedSchedulesByUrl: [],
            parseExceptionsByUrl: [
                'https://ifsc.results.info/events/4/infosheet' => new \RuntimeException('OpenAI HTTP 500'),
            ],
        );
        $cacheWriter = new RecordingEventScheduleCache();

        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: $scheduleParser,
            eventScheduleCache: $cacheWriter,
        );

        $result = $service->sync(2026);

        self::assertCount(1, $result);
        self::assertSame([], $result[0]['schedule']);
        self::assertSame('OpenAI HTTP 500', $result[0]['schedule_error']);
        self::assertSame($result, $cacheWriter->savedEvents);
    }

    public function testSyncThrowsWhenScheduleParserReportsChatGptFailure(): void
    {
        $eventInfoProvider = new FakeEventInfoProvider([
            $this->eventInfo('https://ifsc.results.info/events/40/infosheet'),
        ]);
        $scheduleParser = new FakeInfoSheetScheduleParser(
            cachedSchedulesByUrl: [],
            parsedSchedulesByUrl: [],
            parseExceptionsByUrl: [
                'https://ifsc.results.info/events/40/infosheet' => new InfoSheetScheduleParserException('OpenAI HTTP 500'),
            ],
        );
        $cacheWriter = new RecordingEventScheduleCache();

        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: $eventInfoProvider,
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: $scheduleParser,
            eventScheduleCache: $cacheWriter,
        );

        $this->expectException(InfoSheetScheduleParserException::class);
        $this->expectExceptionMessage('OpenAI HTTP 500');
        $service->sync(2026);
    }

    public function testSyncBuildsLocationObjectFromLocationCountrySuffix(): void
    {
        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: new FakeEventInfoProvider([
                $this->eventInfo(null, 'Bern, Switzerland', 'SUI'),
            ]),
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: new FakeInfoSheetScheduleParser(
                cachedSchedulesByUrl: [],
                parsedSchedulesByUrl: [],
            ),
            eventScheduleCache: new RecordingEventScheduleCache(),
        );

        $result = $service->sync(2026);

        self::assertSame([
            'name' => 'Bern',
            'country' => 'Switzerland',
            'country_code' => 'SUI',
        ], $result[0]['location']);
    }

    public function testSyncFiltersByLeagueSeasonId(): void
    {
        $cacheWriter = new RecordingEventScheduleCache();
        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: new FakeRecentLeagueProvider([457]),
            eventInfoProvider: new FakeEventInfoProvider([
                $this->eventInfo(null, leagueSeasonId: 457),
                $this->eventInfo(null, leagueSeasonId: 2026),
            ]),
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: new FakeInfoSheetScheduleParser(
                cachedSchedulesByUrl: [],
                parsedSchedulesByUrl: [],
            ),
            eventScheduleCache: $cacheWriter,
        );

        $result = $service->sync(seasonYear: 2026, leagueSeasonIds: [2026]);

        self::assertCount(1, $result);
        self::assertSame(2026, $result[0]['league_season_id']);
        self::assertSame($result, $cacheWriter->savedEvents);
    }

    public function testSyncPassesSeasonYearToRecentLeagueProvider(): void
    {
        $leagueProvider = new FakeRecentLeagueProvider([457]);
        $service = new RecentEventsScheduleSyncService(
            recentLeagueProvider: $leagueProvider,
            eventInfoProvider: new FakeEventInfoProvider([]),
            infoSheetPdfDownloader: new FakeInfoSheetPdfDownloader(),
            infoSheetScheduleParser: new FakeInfoSheetScheduleParser(
                cachedSchedulesByUrl: [],
                parsedSchedulesByUrl: [],
            ),
            eventScheduleCache: new RecordingEventScheduleCache(),
        );

        $service->sync(seasonYear: 2027);

        self::assertSame(2027, $leagueProvider->lastSeasonYear);
    }

    private function eventInfo(
        ?string $infosheetUrl,
        string $location = 'Innsbruck, Austria',
        string $country = 'AUT',
        int $leagueSeasonId = 457,
    ): EventInfo
    {
        return new EventInfo(
            eventId: 1,
            eventName: 'IFSC Event',
            leagueId: 457,
            leagueName: 'World Cups',
            leagueSeasonId: $leagueSeasonId,
            localStartDate: '2026-06-19',
            localEndDate: '2026-06-20',
            timeZone: new DateTimeZone('UTC'),
            location: $location,
            country: $country,
            disciplines: ['lead'],
            categories: ['men', 'women'],
            infosheetUrl: $infosheetUrl,
        );
    }
}

final class FakeRecentLeagueProvider implements RecentLeagueProviderInterface
{
    public ?int $lastSeasonYear = null;

    /** @param array<int, \SportClimbing\EventDetails\Domain\Event\Entity\League|int> $leagueIds */
    public function __construct(
        private array $leagueIds,
    ) {
    }

    public function fetchRecentLeagueIds(?int $seasonYear = null): array
    {
        $this->lastSeasonYear = $seasonYear;

        return $this->leagueIds;
    }
}

final class FakeEventInfoProvider implements EventInfoProviderInterface
{
    /** @var array<int, \SportClimbing\EventDetails\Domain\Event\Entity\League|int> */
    public array $lastLeagues = [];

    /** @param EventInfo[] $events */
    public function __construct(
        private array $events,
    ) {
    }

    public function fetchEventsForLeagues(array $leagues): Generator
    {
        $this->lastLeagues = $leagues;

        foreach ($this->events as $event) {
            yield $event;
        }
    }
}

final class FakeInfoSheetPdfDownloader implements InfoSheetPdfDownloaderInterface
{
    public int $downloadCalls = 0;

    public function download(string $url): DownloadedPdf
    {
        $this->downloadCalls++;

        return new DownloadedPdf(
            path: '/tmp/infosheet.pdf',
            headers: ['etag' => ['abc']],
        );
    }
}

final class FakeInfoSheetScheduleParser implements InfoSheetScheduleParserInterface
{
    public int $loadCalls = 0;
    public int $parseCalls = 0;
    /** @var bool[] */
    public array $parseForceRescanFlags = [];

    /**
     * @param array<string, IfscSchedule[]> $cachedSchedulesByUrl
     * @param array<string, IfscSchedule[]> $parsedSchedulesByUrl
     * @param array<string, InfoSheetTicketInfo> $cachedTicketInfoByUrl
     * @param array<string, InfoSheetTicketInfo> $parsedTicketInfoByUrl
     * @param array<string, \Throwable> $parseExceptionsByUrl
     */
    public function __construct(
        private array $cachedSchedulesByUrl,
        private array $parsedSchedulesByUrl,
        private array $cachedTicketInfoByUrl = [],
        private array $parsedTicketInfoByUrl = [],
        private array $parseExceptionsByUrl = [],
    ) {
    }

    public function parseScheduleFromPdf(
        EventInfo $event,
        string $pdfPath,
        string $infoSheetUrl = '',
        array $infoSheetHeaders = [],
        bool $forceRescan = false,
    ): InfoSheetParseResult {
        $this->parseCalls++;
        $this->parseForceRescanFlags[] = $forceRescan;

        if (isset($this->parseExceptionsByUrl[$infoSheetUrl])) {
            throw $this->parseExceptionsByUrl[$infoSheetUrl];
        }

        return new InfoSheetParseResult(
            schedules: $this->parsedSchedulesByUrl[$infoSheetUrl] ?? [],
            ticketInfo: $this->parsedTicketInfoByUrl[$infoSheetUrl] ?? new InfoSheetTicketInfo(),
        );
    }

    public function loadCachedSchedule(
        EventInfo $event,
        string $infoSheetUrl,
        array $infoSheetHeaders = [],
    ): ?InfoSheetParseResult
    {
        $this->loadCalls++;

        if (!isset($this->cachedSchedulesByUrl[$infoSheetUrl])) {
            return null;
        }

        return new InfoSheetParseResult(
            schedules: $this->cachedSchedulesByUrl[$infoSheetUrl],
            ticketInfo: $this->cachedTicketInfoByUrl[$infoSheetUrl] ?? new InfoSheetTicketInfo(),
        );
    }
}

final class RecordingEventScheduleCache implements EventScheduleCacheInterface
{
    /** @var array<array<string,mixed>> */
    public array $savedEvents = [];

    public function save(array $events): void
    {
        $this->savedEvents = $events;
    }
}
