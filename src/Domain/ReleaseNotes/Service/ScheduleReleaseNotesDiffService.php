<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\ReleaseNotes\Service;

use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ReleaseNotesChangedEvent;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ReleaseNotesEvent;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ReleaseNotesFieldChange;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ScheduleReleaseNotesDiff;

final class ScheduleReleaseNotesDiffService
{
    /**
     * @param array<int,array<string,mixed>> $previousEvents
     * @param array<int,array<string,mixed>> $currentEvents
     */
    public function diff(array $previousEvents, array $currentEvents): ScheduleReleaseNotesDiff
    {
        $previousById = $this->mapByEventId($previousEvents);
        $currentById = $this->mapByEventId($currentEvents);

        $previousIds = array_keys($previousById);
        $currentIds = array_keys($currentById);

        sort($previousIds, SORT_NUMERIC);
        sort($currentIds, SORT_NUMERIC);

        $addedIds = array_values(array_diff($currentIds, $previousIds));
        $removedIds = array_values(array_diff($previousIds, $currentIds));
        $commonIds = array_values(array_intersect($currentIds, $previousIds));

        /** @var ReleaseNotesEvent[] $addedEvents */
        $addedEvents = [];

        foreach ($addedIds as $eventId) {
            $event = $currentById[$eventId] ?? [];
            $addedEvents[] = new ReleaseNotesEvent(
                eventId: $this->eventIdText($event),
                eventName: $this->eventNameText($event),
            );
        }

        /** @var ReleaseNotesEvent[] $removedEvents */
        $removedEvents = [];

        foreach ($removedIds as $eventId) {
            $event = $previousById[$eventId] ?? [];
            $removedEvents[] = new ReleaseNotesEvent(
                eventId: $this->eventIdText($event),
                eventName: $this->eventNameText($event),
            );
        }

        /** @var ReleaseNotesChangedEvent[] $changedEvents */
        $changedEvents = [];

        foreach ($commonIds as $eventId) {
            $oldEvent = $previousById[$eventId];
            $newEvent = $currentById[$eventId];

            if ($this->eventsEqual($oldEvent, $newEvent)) {
                continue;
            }

            $changedEvents[] = new ReleaseNotesChangedEvent(
                eventId: $this->eventIdText($newEvent),
                eventName: $this->eventNameText($newEvent),
                changes: $this->collectChangedFields($oldEvent, $newEvent),
            );
        }

        return new ScheduleReleaseNotesDiff(
            previousEventsCount: count($previousEvents),
            currentEventsCount: count($currentEvents),
            addedEvents: $addedEvents,
            removedEvents: $removedEvents,
            changedEvents: $changedEvents,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private function mapByEventId(array $events): array
    {
        $mapped = [];

        foreach ($events as $event) {
            $eventId = $event['event_id'] ?? null;

            if (is_int($eventId)) {
                $mapped[$eventId] = $event;

                continue;
            }

            if (is_string($eventId) && ctype_digit($eventId)) {
                $mapped[(int) $eventId] = $event;
            }
        }

        return $mapped;
    }

    /** @param array<string,mixed> $event */
    private function eventIdText(array $event): string
    {
        $eventId = $event['event_id'] ?? 'unknown';

        return is_scalar($eventId) ? trim((string) $eventId) : 'unknown';
    }

    /** @param array<string,mixed> $event */
    private function eventNameText(array $event): string
    {
        $eventName = $event['event_name'] ?? '';

        return is_scalar($eventName) ? trim((string) $eventName) : '';
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function eventsEqual(array $left, array $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string,mixed> $oldEvent
     * @param array<string,mixed> $newEvent
     * @return ReleaseNotesFieldChange[]
     */
    private function collectChangedFields(array $oldEvent, array $newEvent): array
    {
        $keys = array_values(array_unique(array_merge(
            array_keys($oldEvent),
            array_keys($newEvent),
        )));
        sort($keys);

        /** @var ReleaseNotesFieldChange[] $changes */
        $changes = [];

        foreach ($keys as $key) {
            $oldValue = $oldEvent[$key] ?? null;
            $newValue = $newEvent[$key] ?? null;

            if (!$this->valuesEqual($oldValue, $newValue)) {
                $changes[] = new ReleaseNotesFieldChange(
                    field: str_replace('_', ' ', $key),
                    oldValue: $oldValue,
                    newValue: $newValue,
                );
            }
        }

        if ($changes !== []) {
            return $changes;
        }

        return [
            new ReleaseNotesFieldChange(
                field: 'content',
                oldValue: $oldEvent,
                newValue: $newEvent,
            ),
        ];
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
