<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Command;

use JsonException;
use RuntimeException;
use Throwable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class GenerateScheduleReleaseNotesCommand extends Command
{
    public const string NAME = 'sportclimbing:generate-schedule-release-notes';

    protected function configure(): void
    {
        $this
            ->setDescription('Generate schedule release notes from previous and current JSON payloads.')
            ->addArgument(
                'previous-path',
                InputArgument::OPTIONAL,
                'Path to previous schedule JSON file (missing file is allowed).',
            )
            ->addArgument(
                'current-path',
                InputArgument::OPTIONAL,
                'Path to current schedule JSON file.',
            )
            ->addArgument(
                'output-path',
                InputArgument::OPTIONAL,
                'Optional output text file path.',
            )
            ->addOption(
                'previous',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to previous schedule JSON file (missing file is allowed).',
            )
            ->addOption(
                'current',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to current schedule JSON file.',
            )
            ->addOption(
                'outfile',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional output text file path.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $previousPath = $this->resolveRequiredPath($input, 'previous', 'previous-path');
            $currentPath = $this->resolveRequiredPath($input, 'current', 'current-path');
            $outputPath = $this->resolveOptionalPath($input, 'outfile', 'output-path');

            $previousEvents = $this->loadEvents($previousPath, false);
            $currentEvents = $this->loadEvents($currentPath, true);
            $report = $this->buildReport($previousEvents, $currentEvents, $previousPath, $currentPath);

            if ($outputPath !== null) {
                $this->writeReport($outputPath, $report);
            }

            $output->write($report, false, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function resolveRequiredPath(InputInterface $input, string $optionName, string $argumentName): string
    {
        $value = $input->getOption($optionName);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = $input->getArgument($argumentName);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        throw new RuntimeException(sprintf(
            'Either --%s or the %s argument must be a non-empty file path.',
            $optionName,
            $argumentName,
        ));
    }

    private function resolveOptionalPath(InputInterface $input, string $optionName, string $argumentName): ?string
    {
        $value = $input->getOption($optionName);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = $input->getArgument($argumentName);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadEvents(string $path, bool $required): array
    {
        if (!is_file($path)) {
            if ($required) {
                throw new RuntimeException(sprintf('File "%s" does not exist.', $path));
            }

            return [];
        }

        $json = @file_get_contents($path);

        if (!is_string($json) || trim($json) === '') {
            if ($required) {
                throw new RuntimeException(sprintf('File "%s" is empty.', $path));
            }

            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Invalid JSON in "%s": %s', $path, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Unexpected JSON payload in "%s".', $path));
        }

        $events = $decoded['events'] ?? null;

        if ($events === null) {
            return [];
        }

        if (!is_array($events)) {
            throw new RuntimeException(sprintf('Expected "events" array in "%s".', $path));
        }

        /** @var array<int,array<string,mixed>> $events */
        return array_values(array_filter(
            $events,
            static fn (mixed $event): bool => is_array($event),
        ));
    }

    private function writeReport(string $outputPath, string $report): void
    {
        $directory = dirname($outputPath);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create output directory "%s".', $directory));
        }

        if (@file_put_contents($outputPath, $report) === false) {
            throw new RuntimeException(sprintf('Unable to write report file "%s".', $outputPath));
        }
    }

    /**
     * @param array<int,array<string,mixed>> $previousEvents
     * @param array<int,array<string,mixed>> $currentEvents
     */
    private function buildReport(array $previousEvents, array $currentEvents, string $previousPath, string $currentPath): string
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

        $changedIds = [];

        foreach ($commonIds as $eventId) {
            $oldEvent = $previousById[$eventId];
            $newEvent = $currentById[$eventId];

            if (!$this->eventsEqual($oldEvent, $newEvent)) {
                $changedIds[] = $eventId;
            }
        }

        $lines = [];
        $lines[] = 'Schedule Data Update';
        $lines[] = '====================';
        $lines[] = sprintf('Previous file: %s', $previousPath);
        $lines[] = sprintf('Current file: %s', $currentPath);
        $lines[] = sprintf('Previous events: %d', count($previousEvents));
        $lines[] = sprintf('Current events: %d', count($currentEvents));
        $lines[] = sprintf('Added events: %d', count($addedIds));
        $lines[] = sprintf('Removed events: %d', count($removedIds));
        $lines[] = sprintf('Changed events: %d', count($changedIds));
        $lines[] = '';

        if ($addedIds !== []) {
            $lines[] = 'Added:';

            foreach ($addedIds as $eventId) {
                $lines[] = sprintf('- %s', $this->eventLabel($currentById[$eventId] ?? []));
            }

            $lines[] = '';
        }

        if ($removedIds !== []) {
            $lines[] = 'Removed:';

            foreach ($removedIds as $eventId) {
                $lines[] = sprintf('- %s', $this->eventLabel($previousById[$eventId] ?? []));
            }

            $lines[] = '';
        }

        if ($changedIds !== []) {
            $lines[] = 'Changed:';

            foreach ($changedIds as $eventId) {
                $oldEvent = $previousById[$eventId] ?? [];
                $newEvent = $currentById[$eventId] ?? [];
                $lines[] = sprintf(
                    '- %s (%s)',
                    $this->eventLabel($newEvent),
                    $this->describeChangedFields($oldEvent, $newEvent),
                );
            }

            $lines[] = '';
        }

        if ($addedIds === [] && $removedIds === [] && $changedIds === []) {
            $lines[] = 'No event-level changes detected.';
            $lines[] = '';
        }

        return implode("\n", $lines);
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
    private function eventLabel(array $event): string
    {
        $eventId = $event['event_id'] ?? 'unknown';
        $eventName = $event['event_name'] ?? '';

        $eventIdText = is_scalar($eventId) ? trim((string) $eventId) : 'unknown';
        $eventNameText = is_scalar($eventName) ? trim((string) $eventName) : '';

        return $eventNameText !== '' ? sprintf('%s %s', $eventIdText, $eventNameText) : $eventIdText;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function eventsEqual(array $left, array $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string,mixed> $oldEvent @param array<string,mixed> $newEvent */
    private function describeChangedFields(array $oldEvent, array $newEvent): string
    {
        $keys = array_values(array_unique(array_merge(
            array_keys($oldEvent),
            array_keys($newEvent),
        )));
        sort($keys);

        $changedKeys = [];

        foreach ($keys as $key) {
            $oldValue = $oldEvent[$key] ?? null;
            $newValue = $newEvent[$key] ?? null;

            if (!$this->valuesEqual($oldValue, $newValue)) {
                $changedKeys[] = $key;
            }
        }

        if ($changedKeys === []) {
            return 'content changed';
        }

        $changedKeys = array_map(static fn (string $key): string => str_replace('_', ' ', $key), $changedKeys);
        $limited = array_slice($changedKeys, 0, 6);
        $suffix = count($changedKeys) > count($limited) ? ', ...' : '';

        return sprintf('fields: %s%s', implode(', ', $limited), $suffix);
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
