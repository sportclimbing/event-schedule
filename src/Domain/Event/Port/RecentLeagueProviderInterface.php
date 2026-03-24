<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Port;

use SportClimbing\EventDetails\Domain\Event\Entity\League;

interface RecentLeagueProviderInterface
{
    /** @return array<int, League|int> */
    public function fetchRecentLeagueIds(): array;
}
