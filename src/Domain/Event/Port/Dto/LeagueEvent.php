<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Port\Dto;

final readonly class LeagueEvent
{
    public function __construct(
        public int $eventId,
        public string $eventName,
        public string $localStartDate,
        public string $localEndDate,
        public ?string $leagueName = null,
        public ?string $infosheetUrl = null,
        public ?string $ticketUrl = null,
        public ?string $ticketPrice = null,
        public ?string $ticketCurrency = null,
    ) {
    }
}
