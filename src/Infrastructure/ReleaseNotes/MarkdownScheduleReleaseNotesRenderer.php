<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\ReleaseNotes;

use SportClimbing\EventDetails\Domain\ReleaseNotes\Entity\ScheduleReleaseNotesDiff;

final class MarkdownScheduleReleaseNotesRenderer
{
    public function render(ScheduleReleaseNotesDiff $diff, string $previousPath, string $currentPath): string
    {
        $lines = [];
        $lines[] = '# Schedule Data Update';
        $lines[] = '';
        $lines[] = '| Metric | Value |';
        $lines[] = '| --- | --- |';
        $lines[] = sprintf('| Previous file | `%s` |', $this->escapeInlineCode($previousPath));
        $lines[] = sprintf('| Current file | `%s` |', $this->escapeInlineCode($currentPath));
        $lines[] = sprintf('| Previous events | %d |', $diff->previousEventsCount);
        $lines[] = sprintf('| Current events | %d |', $diff->currentEventsCount);
        $lines[] = sprintf('| Added events | %d |', $diff->addedCount());
        $lines[] = sprintf('| Removed events | %d |', $diff->removedCount());
        $lines[] = sprintf('| Changed events | %d |', $diff->changedCount());

        if ($diff->addedEvents !== []) {
            $lines[] = '';
            $lines[] = '## Added Events';
            $lines[] = '';
            $lines[] = '| Event ID | Event Name |';
            $lines[] = '| --- | --- |';

            foreach ($diff->addedEvents as $event) {
                $lines[] = sprintf(
                    '| %s | %s |',
                    $this->escapeMarkdownCell($event->eventId),
                    $this->escapeMarkdownCell($event->eventName),
                );
            }
        }

        if ($diff->removedEvents !== []) {
            $lines[] = '';
            $lines[] = '## Removed Events';
            $lines[] = '';
            $lines[] = '| Event ID | Event Name |';
            $lines[] = '| --- | --- |';

            foreach ($diff->removedEvents as $event) {
                $lines[] = sprintf(
                    '| %s | %s |',
                    $this->escapeMarkdownCell($event->eventId),
                    $this->escapeMarkdownCell($event->eventName),
                );
            }
        }

        if ($diff->changedEvents !== []) {
            $lines[] = '';
            $lines[] = '## Changed Events';
            $lines[] = '';
            $lines[] = '| Event ID | Event Name | Field | Old value | New value |';
            $lines[] = '| --- | --- | --- | --- | --- |';

            foreach ($diff->changedEvents as $event) {
                foreach ($event->changes as $change) {
                    $lines[] = sprintf(
                        '| %s | %s | %s | %s | %s |',
                        $this->escapeMarkdownCell($event->eventId),
                        $this->escapeMarkdownCell($event->eventName),
                        $this->escapeMarkdownCell($change->field),
                        $this->escapeMarkdownCell($this->formatChangeValue($change->oldValue)),
                        $this->escapeMarkdownCell($this->formatChangeValue($change->newValue)),
                    );
                }
            }
        }

        if ($diff->addedCount() === 0 && $diff->removedCount() === 0 && $diff->changedCount() === 0) {
            $lines[] = '';
            $lines[] = 'No event-level changes detected.';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function formatChangeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return $value === '' ? '""' : $value;
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : '[unserializable]';
        }

        return '[unsupported]';
    }

    private function escapeMarkdownCell(string $value): string
    {
        $value = str_replace(["\r\n", "\n", "\r"], '<br>', $value);
        $value = str_replace('|', '\|', $value);

        return $value === '' ? '&nbsp;' : $value;
    }

    private function escapeInlineCode(string $value): string
    {
        return str_replace('`', '\`', $value);
    }
}
