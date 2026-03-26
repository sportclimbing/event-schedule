<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Service;

use DateTimeInterface;
use SportClimbing\EventDetails\Domain\Event\Entity\League;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Domain\Event\Port\EventInfoProviderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\EventScheduleCacheInterface;
use SportClimbing\EventDetails\Domain\Event\Port\InfoSheetPdfDownloaderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\RecentLeagueProviderInterface;
use SportClimbing\EventDetails\Domain\Schedule\IfscSchedule;
use SportClimbing\EventDetails\Domain\Schedule\InfoSheetTicketInfo;
use SportClimbing\EventDetails\Domain\Schedule\Exception\InfoSheetScheduleParserException;
use SportClimbing\EventDetails\Domain\Schedule\Port\InfoSheetScheduleParserInterface;
use Throwable;

final readonly class RecentEventsScheduleSyncService
{
    private const int WORLD_CUPS_LEAGUE_SEASON_ID = 457;
    private const int GAMES_LEAGUE_SEASON_ID = 318;
    private const int PARACLIMBING_LEAGUE_SEASON_ID = 438;

    public function __construct(
        private RecentLeagueProviderInterface $recentLeagueProvider,
        private EventInfoProviderInterface $eventInfoProvider,
        private InfoSheetPdfDownloaderInterface $infoSheetPdfDownloader,
        private InfoSheetScheduleParserInterface $infoSheetScheduleParser,
        private EventScheduleCacheInterface $eventScheduleCache,
    ) {
    }

    /** @return array<array<string,mixed>> */
    public function sync(
        int $seasonYear,
        array $leagueSeasonIds = [457, 318, 438],
        bool $forceRescan = false,
    ): array {
        $availableLeagues = $this->recentLeagueProvider->fetchRecentLeagueIds($seasonYear);
        $resolvedLeagueSeasonIds = $this->resolveLeagueSeasonIdsForSeason(
            requestedLeagueSeasonIds: $leagueSeasonIds,
            availableLeagues: $availableLeagues,
        );
        $leagues = $resolvedLeagueSeasonIds === []
            ? $availableLeagues
            : $this->filterLeaguesBySeasonIds(
                leagues: $availableLeagues,
                allowedLeagueSeasonIds: $resolvedLeagueSeasonIds,
            );
        $eventLeagueSeasonIds = $resolvedLeagueSeasonIds === [] ? $leagueSeasonIds : $resolvedLeagueSeasonIds;
        $events = [];

        foreach ($this->eventInfoProvider->fetchEventsForLeagues($leagues) as $event) {
            if (!in_array($event->leagueSeasonId, $eventLeagueSeasonIds, true)) {
                continue;
            }

            [$schedules, $scheduleError, $ticketInfo] = $this->fetchSchedulesForEvent($event, $forceRescan);
            $events[] = $this->eventToNode($event, $schedules, $ticketInfo, $scheduleError);
        }

        $this->eventScheduleCache->save($events);

        return $events;
    }

    /**
     * @param int[] $requestedLeagueSeasonIds
     * @param array<int, League|int> $availableLeagues
     * @return int[]
     */
    private function resolveLeagueSeasonIdsForSeason(
        array $requestedLeagueSeasonIds,
        array $availableLeagues,
    ): array {
        $allowedLeagueSeasonIds = [];
        $allowedLeagueCategories = $this->requestedLeagueCategories($requestedLeagueSeasonIds);

        foreach ($availableLeagues as $league) {
            if ($league instanceof League) {
                $leagueCategory = $this->leagueCategoryFromName($league->name);

                if ($leagueCategory !== null && in_array($leagueCategory, $allowedLeagueCategories, true)) {
                    $allowedLeagueSeasonIds[] = $league->id;

                    continue;
                }

                if (in_array($league->id, $requestedLeagueSeasonIds, true)) {
                    $allowedLeagueSeasonIds[] = $league->id;
                }

                continue;
            }

            if (is_int($league) && in_array($league, $requestedLeagueSeasonIds, true)) {
                $allowedLeagueSeasonIds[] = $league;
            }
        }

        return array_values(array_unique($allowedLeagueSeasonIds));
    }

    /**
     * @param array<int, League|int> $leagues
     * @param int[] $allowedLeagueSeasonIds
     * @return array<int, League|int>
     */
    private function filterLeaguesBySeasonIds(array $leagues, array $allowedLeagueSeasonIds): array
    {
        $filteredLeagues = [];

        foreach ($leagues as $league) {
            if ($league instanceof League && in_array($league->id, $allowedLeagueSeasonIds, true)) {
                $filteredLeagues[] = $league;
                continue;
            }

            if (is_int($league) && in_array($league, $allowedLeagueSeasonIds, true)) {
                $filteredLeagues[] = $league;
            }
        }

        return $filteredLeagues;
    }

    /**
     * @param int[] $requestedLeagueSeasonIds
     * @return string[]
     */
    private function requestedLeagueCategories(array $requestedLeagueSeasonIds): array
    {
        $categories = [];

        foreach ($requestedLeagueSeasonIds as $requestedLeagueSeasonId) {
            $category = match ($requestedLeagueSeasonId) {
                self::WORLD_CUPS_LEAGUE_SEASON_ID => 'world-cups',
                self::GAMES_LEAGUE_SEASON_ID => 'games',
                self::PARACLIMBING_LEAGUE_SEASON_ID => 'paraclimbing',
                default => null,
            };

            if ($category !== null) {
                $categories[] = $category;
            }
        }

        return array_values(array_unique($categories));
    }

    private function leagueCategoryFromName(string $leagueName): ?string
    {
        $normalizedLeagueName = strtolower(trim($leagueName));

        if ($normalizedLeagueName === '') {
            return null;
        }

        if (str_contains($normalizedLeagueName, 'world cups and world championships')) {
            return 'world-cups';
        }

        if (str_contains($normalizedLeagueName, 'paraclimbing')) {
            return 'paraclimbing';
        }

        if ($normalizedLeagueName === 'games') {
            return 'games';
        }

        return null;
    }

    /**
     * @return array{0: IfscSchedule[], 1: ?string, 2: ?InfoSheetTicketInfo}
     */
    private function fetchSchedulesForEvent(EventInfo $event, bool $forceRescan = false): array
    {
        if ($event->infosheetUrl === null || trim($event->infosheetUrl) === '') {
            return [[], null, null];
        }

        try {
            $downloadedPdf = $this->infoSheetPdfDownloader->download($event->infosheetUrl);

            if (!$forceRescan) {
                $cachedSchedule = $this->infoSheetScheduleParser->loadCachedSchedule(
                    event: $event,
                    infoSheetUrl: $event->infosheetUrl,
                    infoSheetHeaders: $downloadedPdf->headers,
                );

                if ($cachedSchedule !== null) {
                    return [$cachedSchedule->schedules, null, $cachedSchedule->ticketInfo];
                }
            }

            $parsedResult = $this->infoSheetScheduleParser->parseScheduleFromPdf(
                event: $event,
                pdfPath: $downloadedPdf->path,
                infoSheetUrl: $event->infosheetUrl,
                infoSheetHeaders: $downloadedPdf->headers,
                forceRescan: $forceRescan,
            );

            return [
                $parsedResult->schedules,
                null,
                $parsedResult->ticketInfo,
            ];
        } catch (InfoSheetScheduleParserException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return [[], $exception->getMessage(), null];
        }
    }

    /**
     * @param IfscSchedule[] $schedules
     * @return array<string,mixed>
     */
    private function eventToNode(
        EventInfo $event,
        array $schedules,
        ?InfoSheetTicketInfo $ticketInfo = null,
        ?string $scheduleError = null,
    ): array {
        $node = [
            'event_id' => $event->eventId,
            'event_name' => $event->eventName,
            'league_id' => $event->leagueId,
            'league_name' => $event->leagueName,
            'league_season_id' => $event->leagueSeasonId,
            'local_start_date' => $event->localStartDate,
            'local_end_date' => $event->localEndDate,
            'timezone' => $event->timeZone->getName(),
            'infosheet_url' => $event->infosheetUrl,
            'location' => $this->buildLocationNode($event),
            'disciplines' => $event->disciplines,
            'categories' => $event->categories,
            'tickets' => $this->buildTicketNode($event, $ticketInfo),
            'schedule' => $this->scheduleToNode($schedules),
        ];

        if ($scheduleError !== null && trim($scheduleError) !== '') {
            $node['schedule_error'] = $scheduleError;
        }

        return $node;
    }

    /**
     * @param IfscSchedule[] $schedules
     * @return array<array{name:string,starts_at:string,ends_at:?string}>
     */
    private function scheduleToNode(array $schedules): array
    {
        return array_map(
            static fn (IfscSchedule $schedule): array => [
                'name' => $schedule->name,
                'starts_at' => $schedule->startsAt->format(DateTimeInterface::RFC3339),
                'ends_at' => $schedule->endsAt?->format(DateTimeInterface::RFC3339),
            ],
            $schedules,
        );
    }

    /**
     * @return array{name:string,country:string,country_code:string}
     */
    private function buildLocationNode(EventInfo $event): array
    {
        $locationName = trim($event->location);
        $countryCode = strtoupper(trim($event->country));
        $countryFromLocation = $this->extractCountryFromLocation($locationName);

        if ($countryFromLocation !== null) {
            $locationName = $this->extractLocationName($locationName);
        }

        $countryName = $countryFromLocation ?? $countryCode;

        if ($locationName === '') {
            $locationName = $countryName;
        }

        return [
            'name' => $locationName,
            'country' => $countryName,
            'country_code' => $countryCode,
        ];
    }

    private function extractCountryFromLocation(string $location): ?string
    {
        $parts = $this->locationParts($location);

        if (count($parts) < 2) {
            return null;
        }

        return $parts[array_key_last($parts)];
    }

    private function extractLocationName(string $location): string
    {
        $parts = $this->locationParts($location);

        if (count($parts) < 2) {
            return trim($location);
        }

        array_pop($parts);
        $name = trim(implode(', ', $parts));

        return $name !== '' ? $name : trim($location);
    }

    /** @return string[] */
    private function locationParts(string $location): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $location)),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /** @return array{purchase_url:?string,price:?string,currency:?string,summary:?string} */
    private function buildTicketNode(EventInfo $event, ?InfoSheetTicketInfo $ticketInfo = null): array
    {
        return [
            'purchase_url' => $ticketInfo?->purchaseUrl ?? $event->ticketUrl,
            'price' => $this->normalizeTicketPrice($ticketInfo?->price ?? $event->ticketPrice),
            'currency' => $ticketInfo?->currency ?? $event->ticketCurrency,
            'summary' => $ticketInfo?->summary,
        ];
    }

    private function normalizeTicketPrice(?string $price): ?string
    {
        if ($price === null) {
            return null;
        }

        $normalized = trim($price);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^0+(?:[.,]0+)?$/', $normalized) === 1) {
            return null;
        }

        return $normalized;
    }
}
