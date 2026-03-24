<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\IFSC;

use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\Event\Entity\League;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\EventDetails;
use SportClimbing\EventDetails\Domain\Event\Port\IfscApiClientInterface;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscRecentLeagueProvider;

final class IfscRecentLeagueProviderTest extends TestCase
{
    public function testFetchRecentLeagueIdsParsesLeagueIdsFromSeasonPayload(): void
    {
        $apiClient = new FakeIfscApiClientForLeagues((object) [
            'leagues' => [
                (object) ['name' => 'World Cups and World Championships', 'url' => '/api/v1/season_leagues/457'],
                (object) ['name' => 'IFSC Youth', 'url' => '/api/v1/season_leagues/458'],
                (object) ['name' => 'duplicate should keep latest', 'url' => '/api/v1/season_leagues/457'],
                (object) ['url' => '/api/v1/season_leagues/not-an-int'],
            ],
        ]);

        $provider = new IfscRecentLeagueProvider($apiClient);

        self::assertEquals([
            new League(457, 'duplicate should keep latest'),
            new League(458, 'IFSC Youth'),
        ], $provider->fetchRecentLeagueIds());
        self::assertSame('/api/v1/seasons/38', $apiClient->lastUrl);
    }
}

final class FakeIfscApiClientForLeagues implements IfscApiClientInterface
{
    public string $lastUrl = '';

    public function __construct(
        private readonly object $payload,
    ) {
    }

    public function fetchLeagueEvents(int $leagueId): array
    {
        return [];
    }

    public function fetchEventDetails(int $eventId): EventDetails
    {
        return new EventDetails(
            id: 1,
            leagueId: 1,
            leagueSeasonId: 1,
            location: '',
            country: '',
            timeZone: 'UTC',
            disciplineKinds: [],
        );
    }

    public function authenticatedGet(string $url): object|array
    {
        $this->lastUrl = $url;

        return $this->payload;
    }
}
