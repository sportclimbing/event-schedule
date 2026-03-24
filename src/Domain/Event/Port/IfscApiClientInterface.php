<?php

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Port;

use SportClimbing\EventDetails\Domain\Event\Port\Dto\EventDetails;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\LeagueEvent;

interface IfscApiClientInterface
{
    /** @return LeagueEvent[] */
    public function fetchLeagueEvents(int $leagueId): array;

    public function fetchEventDetails(int $eventId): EventDetails;

    public function authenticatedGet(string $url): object|array;
}
