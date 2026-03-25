<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\ReleaseNotes\Entity;

final readonly class ReleaseNotesEvent
{
    public function __construct(
        public string $eventId,
        public string $eventName,
    ) {
    }
}
