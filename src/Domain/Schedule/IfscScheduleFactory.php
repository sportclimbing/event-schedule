<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Schedule;

use DateTimeImmutable;

final class IfscScheduleFactory
{
    public function create(
        string $name,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): IfscSchedule
    {
        return new IfscSchedule(
            name: $name,
            startsAt: $startsAt,
            endsAt: $endsAt,
        );
    }
}
