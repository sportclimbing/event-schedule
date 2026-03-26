<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Service;

use DateTimeZone;
use Generator;
use InvalidArgumentException;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Domain\Event\Entity\League;
use SportClimbing\EventDetails\Domain\Event\Exception\EventInfoProviderException;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\EventDetails;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\LeagueEvent;
use SportClimbing\EventDetails\Domain\Event\Port\EventInfoProviderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\IfscApiClientInterface;
use Throwable;

final readonly class IfscEventInfoProvider implements EventInfoProviderInterface
{
    public function __construct(
        private IfscApiClientInterface $apiClient,
    ) {
    }

    /**
     * @param array<int, League|int> $leagues
     * @return Generator<int, EventInfo, mixed, void>
     */
    public function fetchEventsForLeagues(array $leagues): Generator
    {
        foreach ($leagues as $leagueInput) {
            $league = $this->normalizeLeague($leagueInput);

            $leagueEvents = $this->fetchLeagueEvents($league);

            foreach ($leagueEvents as $leagueEvent) {
                yield $this->buildEventInfo($league, $leagueEvent);
            }
        }
    }

    private function normalizeLeague(mixed $league): League
    {
        if ($league instanceof League) {
            return $league;
        }

        if (is_int($league)) {
            return new League($league, (string) $league);
        }

        throw new InvalidArgumentException('fetchEventsForLeagues expects League or int entries.');
    }

    /** @return LeagueEvent[] */
    private function fetchLeagueEvents(League $league): array
    {
        try {
            return $this->apiClient->fetchLeagueEvents($league->id);
        } catch (Throwable $exception) {
            throw new EventInfoProviderException(
                sprintf(
                    'Unable to retrieve events for league %s (%d): %s',
                    $league->name,
                    $league->id,
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }
    }

    private function buildEventInfo(League $league, LeagueEvent $leagueEvent): EventInfo
    {
        try {
            $eventDetails = $this->apiClient->fetchEventDetails($leagueEvent->eventId);
        } catch (Throwable $exception) {
            throw new EventInfoProviderException(
                sprintf(
                    'Unable to retrieve details for event %d: %s',
                    $leagueEvent->eventId,
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }

        return new EventInfo(
            eventId: $eventDetails->id,
            eventName: $leagueEvent->eventName,
            leagueId: $eventDetails->leagueId,
            leagueName: $this->resolveLeagueName($league, $leagueEvent),
            leagueSeasonId: $eventDetails->leagueSeasonId,
            localStartDate: $leagueEvent->localStartDate,
            localEndDate: $leagueEvent->localEndDate,
            timeZone: $this->buildTimeZone($eventDetails),
            location: $this->normalizeLocation($eventDetails->location),
            country: $eventDetails->country,
            disciplines: $this->buildDisciplines($eventDetails->disciplineKinds),
            categories: $this->buildCategories($eventDetails->categories),
            infosheetUrl: $leagueEvent->infosheetUrl,
            ticketUrl: $leagueEvent->ticketUrl,
            ticketPrice: $leagueEvent->ticketPrice,
            ticketCurrency: $leagueEvent->ticketCurrency,
        );
    }

    private function resolveLeagueName(League $league, LeagueEvent $leagueEvent): string
    {
        $leagueName = trim($league->name);

        if ($leagueName !== '' && !ctype_digit($leagueName)) {
            return $leagueName;
        }

        if ($leagueEvent->leagueName !== null && trim($leagueEvent->leagueName) !== '') {
            return trim($leagueEvent->leagueName);
        }

        return (string) $league->id;
    }

    private function buildTimeZone(EventDetails $eventDetails): DateTimeZone
    {
        try {
            return new DateTimeZone($eventDetails->timeZone);
        } catch (Throwable $exception) {
            throw new EventInfoProviderException(
                sprintf('Invalid timezone: %s', $eventDetails->timeZone),
                0,
                $exception,
            );
        }
    }

    private function normalizeLocation(string $location): string
    {
        return trim($location);
    }

    /**
     * @param string[] $disciplineKinds
     * @return string[]
     */
    private function buildDisciplines(array $disciplineKinds): array
    {
        $disciplines = [];

        foreach ($disciplineKinds as $disciplineKind) {
            foreach (explode('&', $disciplineKind) as $kind) {
                $normalizedKind = strtolower(trim($kind));

                if ($normalizedKind !== '') {
                    $disciplines[] = $normalizedKind;
                }
            }
        }

        return array_values(array_unique($disciplines));
    }

    /**
     * @param string[] $categories
     * @return string[]
     */
    private function buildCategories(array $categories): array
    {
        $normalizedCategories = [];

        foreach ($categories as $category) {
            $normalizedCategory = strtolower(trim($category));

            if ($normalizedCategory !== '') {
                $normalizedCategories[] = $normalizedCategory;
            }
        }

        return array_values(array_unique($normalizedCategories));
    }
}
