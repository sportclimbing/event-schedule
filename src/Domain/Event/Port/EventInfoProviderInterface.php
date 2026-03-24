<?php

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Port;

use Generator;
use SportClimbing\EventDetails\Domain\Event\Entity\League;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;

interface EventInfoProviderInterface
{
    /**
     * @param array<int, League|int> $leagues
     * @return Generator<EventInfo>
     */
    public function fetchEventsForLeagues(array $leagues): Generator;
}
