<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Command;

use RuntimeException;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Service\ScheduleReleaseNotesDiffService;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\JsonScheduleEventsLoader;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\MarkdownScheduleReleaseNotesRenderer;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\TextReportFileWriter;
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

    private readonly ScheduleReleaseNotesDiffService $diffService;
    private readonly JsonScheduleEventsLoader $eventsLoader;
    private readonly MarkdownScheduleReleaseNotesRenderer $reportRenderer;
    private readonly TextReportFileWriter $reportFileWriter;

    public function __construct(
        ?ScheduleReleaseNotesDiffService $diffService = null,
        ?JsonScheduleEventsLoader $eventsLoader = null,
        ?MarkdownScheduleReleaseNotesRenderer $reportRenderer = null,
        ?TextReportFileWriter $reportFileWriter = null,
    ) {
        $this->diffService = $diffService ?? new ScheduleReleaseNotesDiffService();
        $this->eventsLoader = $eventsLoader ?? new JsonScheduleEventsLoader();
        $this->reportRenderer = $reportRenderer ?? new MarkdownScheduleReleaseNotesRenderer();
        $this->reportFileWriter = $reportFileWriter ?? new TextReportFileWriter();

        parent::__construct(self::NAME);
    }

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

            $previousEvents = $this->eventsLoader->load($previousPath, false);
            $currentEvents = $this->eventsLoader->load($currentPath, true);
            $diff = $this->diffService->diff($previousEvents, $currentEvents);
            $report = $this->reportRenderer->render($diff, $previousPath, $currentPath);

            if ($outputPath !== null) {
                $this->reportFileWriter->write($outputPath, $report);
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
}
