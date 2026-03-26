<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Entity;

use DateTimeZone;

final readonly class EventInfo
{
    /**
     * @param string[] $disciplines
     * @param string[] $categories
     */
    public function __construct(
        public int $eventId,
        public string $eventName,
        public int $leagueId,
        public string $leagueName,
        public int $leagueSeasonId,
        public string $localStartDate,
        public string $localEndDate,
        public DateTimeZone $timeZone,
        public string $location,
        public string $country,
        public array $disciplines,
        public array $categories,
        public ?string $infosheetUrl = null,
        public ?string $ticketUrl = null,
        public ?string $ticketPrice = null,
        public ?string $ticketCurrency = null,
    ) {
    }
}
