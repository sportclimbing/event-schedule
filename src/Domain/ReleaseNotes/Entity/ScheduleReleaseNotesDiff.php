<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\ReleaseNotes\Entity;

final readonly class ScheduleReleaseNotesDiff
{
    /**
     * @param ReleaseNotesEvent[] $addedEvents
     * @param ReleaseNotesEvent[] $removedEvents
     * @param ReleaseNotesChangedEvent[] $changedEvents
     */
    public function __construct(
        public int $previousEventsCount,
        public int $currentEventsCount,
        public array $addedEvents,
        public array $removedEvents,
        public array $changedEvents,
        public int $addedRoundsCount,
        public int $removedRoundsCount,
        public int $changedRoundsCount,
    ) {
    }

    public function addedCount(): int
    {
        return count($this->addedEvents);
    }

    public function removedCount(): int
    {
        return count($this->removedEvents);
    }

    public function changedCount(): int
    {
        return count($this->changedEvents);
    }
}
