<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Port\Dto;

final readonly class EventDetails
{
    /**
     * @param string[] $disciplineKinds
     */
    public function __construct(
        public int $id,
        public int $leagueId,
        public int $leagueSeasonId,
        public string $location,
        public string $country,
        public string $timeZone,
        public array $disciplineKinds,
    ) {
    }
}
