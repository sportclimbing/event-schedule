<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Schedule;

final readonly class InfoSheetParseResult
{
    /**
     * @param IfscSchedule[] $schedules
     */
    public function __construct(
        public array $schedules,
        public InfoSheetTicketInfo $ticketInfo,
    ) {
    }
}

