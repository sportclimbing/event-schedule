<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Domain\ReleaseNotes\Service;

use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Service\ScheduleReleaseNotesDiffService;

final class ScheduleReleaseNotesDiffServiceTest extends TestCase
{
    public function testDiffBuildsAddedRemovedAndChangedEvents(): void
    {
        $service = new ScheduleReleaseNotesDiffService();

        $diff = $service->diff(
            previousEvents: [
                ['event_id' => 1, 'event_name' => 'A', 'schedule' => []],
                ['event_id' => 2, 'event_name' => 'B', 'schedule' => []],
            ],
            currentEvents: [
                ['event_id' => 1, 'event_name' => 'A Updated', 'schedule' => []],
                ['event_id' => 3, 'event_name' => 'C', 'schedule' => []],
            ],
        );

        self::assertSame(2, $diff->previousEventsCount);
        self::assertSame(2, $diff->currentEventsCount);
        self::assertSame(1, $diff->addedCount());
        self::assertSame(1, $diff->removedCount());
        self::assertSame(1, $diff->changedCount());
        self::assertSame('3', $diff->addedEvents[0]->eventId);
        self::assertSame('C', $diff->addedEvents[0]->eventName);
        self::assertSame('2', $diff->removedEvents[0]->eventId);
        self::assertSame('B', $diff->removedEvents[0]->eventName);
        self::assertSame('1', $diff->changedEvents[0]->eventId);
        self::assertSame('A Updated', $diff->changedEvents[0]->eventName);
        self::assertSame('event name', $diff->changedEvents[0]->changes[0]->field);
        self::assertSame('A', $diff->changedEvents[0]->changes[0]->oldValue);
        self::assertSame('A Updated', $diff->changedEvents[0]->changes[0]->newValue);
    }

    public function testDiffReturnsNoChangesForEquivalentPayloads(): void
    {
        $service = new ScheduleReleaseNotesDiffService();
        $events = [
            ['event_id' => 10, 'event_name' => 'Lead Finals', 'schedule' => []],
        ];

        $diff = $service->diff($events, $events);

        self::assertSame(1, $diff->previousEventsCount);
        self::assertSame(1, $diff->currentEventsCount);
        self::assertSame(0, $diff->addedCount());
        self::assertSame(0, $diff->removedCount());
        self::assertSame(0, $diff->changedCount());
    }

    public function testDiffFlattensNestedChangesToFieldPathAndRawValues(): void
    {
        $service = new ScheduleReleaseNotesDiffService();

        $diff = $service->diff(
            previousEvents: [[
                'event_id' => 1,
                'event_name' => 'A',
                'schedule' => [[
                    'name' => 'Final',
                    'starts_at' => '2026-06-20 19:00',
                ]],
            ]],
            currentEvents: [[
                'event_id' => 1,
                'event_name' => 'A',
                'schedule' => [[
                    'name' => 'Final',
                    'starts_at' => '2026-06-20 20:00',
                ]],
            ]],
        );

        self::assertSame(1, $diff->changedCount());
        self::assertCount(1, $diff->changedEvents[0]->changes);
        self::assertSame('schedule[0].starts at', $diff->changedEvents[0]->changes[0]->field);
        self::assertSame('2026-06-20 19:00', $diff->changedEvents[0]->changes[0]->oldValue);
        self::assertSame('2026-06-20 20:00', $diff->changedEvents[0]->changes[0]->newValue);
    }
}
