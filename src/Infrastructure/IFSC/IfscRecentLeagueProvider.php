<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\IFSC;

use SportClimbing\EventDetails\Domain\Event\Entity\League;
use SportClimbing\EventDetails\Domain\Event\Port\IfscApiClientInterface;
use SportClimbing\EventDetails\Domain\Event\Port\RecentLeagueProviderInterface;
use SportClimbing\EventDetails\Infrastructure\IFSC\Exception\IfscApiClientException;

final readonly class IfscRecentLeagueProvider implements RecentLeagueProviderInterface
{
    private const int DEFAULT_SEASON_ID = 38;

    public function __construct(
        private IfscApiClientInterface $apiClient,
    ) {
    }

    /** @return array<int, League|int> */
    public function fetchRecentLeagueIds(): array
    {
        $seasonId = $this->readSeasonId();
        $payload = $this->apiClient->authenticatedGet(sprintf('/api/v1/seasons/%d', $seasonId));

        if (!is_object($payload) || !isset($payload->leagues) || !is_array($payload->leagues)) {
            throw new IfscApiClientException('Unexpected IFSC season payload. Missing "leagues" array.');
        }

        /** @var array<int, League> $leaguesById */
        $leaguesById = [];

        foreach ($payload->leagues as $league) {
            if (!is_object($league) || !isset($league->url) || !is_string($league->url)) {
                continue;
            }

            $path = trim($league->url, '/');
            $basename = basename($path);

            if (ctype_digit($basename)) {
                $leagueId = (int) $basename;
                $leagueName = $this->normalizeLeagueName($league->name ?? null, $leagueId);
                $leaguesById[$leagueId] = new League($leagueId, $leagueName);
            }
        }

        return array_values($leaguesById);
    }

    private function normalizeLeagueName(mixed $name, int $leagueId): string
    {
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        return (string) $leagueId;
    }

    private function readSeasonId(): int
    {
        $value = $_ENV['IFSC_RECENT_SEASON_ID'] ?? getenv('IFSC_RECENT_SEASON_ID');

        if (is_string($value) && ctype_digit($value)) {
            return max(1, (int) $value);
        }

        return self::DEFAULT_SEASON_ID;
    }
}
