<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Entity;

final readonly class EventCategory
{
    /** @param EventRound[] $rounds */
    public function __construct(
        public array $rounds,
    ) {
    }
}
