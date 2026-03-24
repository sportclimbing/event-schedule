<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Entity;

final readonly class EventRound
{
    public function __construct(
        public string $discipline,
        public string $kind,
        public string $category,
    ) {
    }
}
