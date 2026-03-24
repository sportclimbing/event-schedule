<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Domain\Event\Service;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SportClimbing\EventDetails\Domain\Event\Entity\League;
use SportClimbing\EventDetails\Domain\Event\Exception\EventInfoProviderException;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\EventDetails;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\LeagueEvent;
use SportClimbing\EventDetails\Domain\Event\Port\IfscApiClientInterface;
use SportClimbing\EventDetails\Domain\Event\Service\IfscEventInfoProvider;

final class IfscEventInfoProviderTest extends TestCase
{
    public function testFetchEventsForLeaguesBuildsEventInfoFromApiData(): void
    {
        $provider = new IfscEventInfoProvider(
            new FakeIfscApiClient(
                leagueEvents: [
                    new LeagueEvent(
                        eventId: 1001,
                        eventName: 'IFSC World Cup Innsbruck',
                        localStartDate: '2026-06-24',
                        localEndDate: '2026-06-26',
                        leagueName: 'World Cups and World Championships',
                        infosheetUrl: 'https://ifsc.results.info/events/1001/infosheet',
                        ticketUrl: 'https://tickets.example.com/innsbruck',
                        ticketPrice: '45.00',
                        ticketCurrency: 'EUR',
                    ),
                ],
                eventDetails: new EventDetails(
                    id: 1001,
                    leagueId: 22,
                    leagueSeasonId: 126,
                    location: 'Innsbruck, Austria',
                    country: 'AUT',
                    timeZone: 'Europe/Vienna',
                    disciplineKinds: ['Lead & Boulder', 'Speed'],
                ),
            ),
        );

        $events = iterator_to_array(
            $provider->fetchEventsForLeagues([new League(22, 'World Cup')]),
        );

        self::assertCount(1, $events);

        $event = $events[0];

        self::assertSame(1001, $event->eventId);
        self::assertSame('IFSC World Cup Innsbruck', $event->eventName);
        self::assertSame('World Cup', $event->leagueName);
        self::assertSame('Innsbruck, Austria', $event->location);
        self::assertSame('Europe/Vienna', $event->timeZone->getName());
        self::assertSame(['lead', 'boulder', 'speed'], $event->disciplines);
        self::assertSame('https://ifsc.results.info/events/1001/infosheet', $event->infosheetUrl);
        self::assertSame('https://tickets.example.com/innsbruck', $event->ticketUrl);
        self::assertSame('45.00', $event->ticketPrice);
        self::assertSame('EUR', $event->ticketCurrency);
    }

    public function testFetchEventsForLeaguesUsesLeagueNameFromApiWhenInputIsLeagueId(): void
    {
        $provider = new IfscEventInfoProvider(
            new FakeIfscApiClient(
                leagueEvents: [
                    new LeagueEvent(
                        eventId: 1003,
                        eventName: 'IFSC World Cup Salt Lake City',
                        localStartDate: '2026-05-01',
                        localEndDate: '2026-05-03',
                        leagueName: 'World Cups and World Championships',
                    ),
                ],
                eventDetails: new EventDetails(
                    id: 1003,
                    leagueId: 457,
                    leagueSeasonId: 126,
                    location: 'Salt Lake City, USA',
                    country: 'USA',
                    timeZone: 'America/Denver',
                    disciplineKinds: ['Boulder'],
                ),
            ),
        );

        $events = iterator_to_array($provider->fetchEventsForLeagues([457]));

        self::assertCount(1, $events);
        self::assertSame('World Cups and World Championships', $events[0]->leagueName);
    }

    public function testFetchEventsForLeaguesThrowsExceptionForInvalidTimeZone(): void
    {
        $provider = new IfscEventInfoProvider(
            new FakeIfscApiClient(
                leagueEvents: [
                    new LeagueEvent(
                        eventId: 1002,
                        eventName: 'IFSC World Cup',
                        localStartDate: '2026-06-24',
                        localEndDate: '2026-06-26',
                    ),
                ],
                eventDetails: new EventDetails(
                    id: 1002,
                    leagueId: 22,
                    leagueSeasonId: 126,
                    location: 'Bern, SUI',
                    country: 'SUI',
                    timeZone: 'Invalid/Timezone',
                    disciplineKinds: ['Lead'],
                ),
            ),
        );

        $this->expectException(EventInfoProviderException::class);

        iterator_to_array($provider->fetchEventsForLeagues([new League(22, 'World Cup')]));
    }

    public function testFetchEventsForLeaguesWrapsClientErrors(): void
    {
        $provider = new IfscEventInfoProvider(
            new FailingIfscApiClient(),
        );

        $this->expectException(EventInfoProviderException::class);
        $this->expectExceptionMessage('Unable to retrieve events for league');

        iterator_to_array($provider->fetchEventsForLeagues([new League(88, 'Youth')]));
    }
}

final readonly class FakeIfscApiClient implements IfscApiClientInterface
{
    /** @param LeagueEvent[] $leagueEvents */
    public function __construct(
        private array $leagueEvents,
        private EventDetails $eventDetails,
    ) {
    }

    public function fetchLeagueEvents(int $leagueId): array
    {
        return $this->leagueEvents;
    }

    public function fetchEventDetails(int $eventId): EventDetails
    {
        return $this->eventDetails;
    }

    public function authenticatedGet(string $url): object|array
    {
        return [];
    }
}

final class FailingIfscApiClient implements IfscApiClientInterface
{
    public function fetchLeagueEvents(int $leagueId): array
    {
        throw new RuntimeException('network down');
    }

    public function fetchEventDetails(int $eventId): EventDetails
    {
        throw new RuntimeException('network down');
    }

    public function authenticatedGet(string $url): object|array
    {
        throw new RuntimeException('network down');
    }
}
