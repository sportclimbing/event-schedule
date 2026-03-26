<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\ReleaseNotes;

use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ReleaseNotesChangedEvent;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ReleaseNotesEvent;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ReleaseNotesFieldChange;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ScheduleReleaseNotesDiff;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\MarkdownScheduleReleaseNotesRenderer;

final class MarkdownScheduleReleaseNotesRendererTest extends TestCase
{
    public function testRenderProducesMarkdownTablesWithOldAndNewValues(): void
    {
        $renderer = new MarkdownScheduleReleaseNotesRenderer();
        $diff = new ScheduleReleaseNotesDiff(
            previousEventsCount: 2,
            currentEventsCount: 2,
            addedEvents: [new ReleaseNotesEvent('3', 'C')],
            removedEvents: [new ReleaseNotesEvent('2', 'B')],
            changedEvents: [new ReleaseNotesChangedEvent(
                eventId: '1',
                eventName: 'A Updated',
                changes: [
                    new ReleaseNotesFieldChange('event name', 'A', 'A Updated'),
                ],
            )],
            addedRoundsCount: 1,
            removedRoundsCount: 2,
            changedRoundsCount: 3,
        );

        $markdown = $renderer->render($diff, '/tmp/previous.json', '/tmp/current.json');

        self::assertStringContainsString('| Metric | Value |', $markdown);
        self::assertStringContainsString('| Previous file | `/tmp/previous.json` |', $markdown);
        self::assertStringContainsString('| Current file | `/tmp/current.json` |', $markdown);
        self::assertStringContainsString('## Added Events', $markdown);
        self::assertStringContainsString('| 3 | C |', $markdown);
        self::assertStringContainsString('| Added rounds | 1 |', $markdown);
        self::assertStringContainsString('| Removed rounds | 2 |', $markdown);
        self::assertStringContainsString('| Changed rounds | 3 |', $markdown);
        self::assertStringContainsString('## Removed Events', $markdown);
        self::assertStringContainsString('| 2 | B |', $markdown);
        self::assertStringContainsString('## Changed Events', $markdown);
        self::assertStringContainsString('| 1 | A Updated | event name | A | A Updated |', $markdown);
    }

    public function testRenderUsesComplexValuePlaceholderInsteadOfJson(): void
    {
        $renderer = new MarkdownScheduleReleaseNotesRenderer();
        $diff = new ScheduleReleaseNotesDiff(
            previousEventsCount: 1,
            currentEventsCount: 1,
            addedEvents: [],
            removedEvents: [],
            changedEvents: [new ReleaseNotesChangedEvent(
                eventId: '1',
                eventName: 'A',
                changes: [
                    new ReleaseNotesFieldChange('schedule', [['name' => 'Final']], [['name' => 'Semi']]),
                ],
            )],
            addedRoundsCount: 0,
            removedRoundsCount: 0,
            changedRoundsCount: 1,
        );

        $markdown = $renderer->render($diff, '/tmp/previous.json', '/tmp/current.json');

        self::assertStringContainsString('| 1 | A | schedule | [complex value] | [complex value] |', $markdown);
        self::assertStringNotContainsString('{"name"', $markdown);
    }
}
