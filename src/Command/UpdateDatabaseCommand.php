<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Command;

use JsonException;
use Psr\Container\ContainerInterface;
use RuntimeException;
use SportClimbing\EventDetails\Domain\Event\Service\RecentEventsScheduleSyncService;
use Throwable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateDatabaseCommand extends Command
{
    public const string NAME = 'sportclimbing:generate-schedule';
    private const int WORLD_CUPS_LEAGUE_SEASON_ID = 457;
    private const int GAMES_LEAGUE_SEASON_ID = 318;
    private const int PARACLIMBING_LEAGUE_SEASON_ID = 438;

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate Schedule')
            ->addOption(
                'season',
                null,
                InputOption::VALUE_REQUIRED,
                'Season year (for example: 2026).',
            )
            ->addOption(
                'force-rescan',
                null,
                InputOption::VALUE_NONE,
                'Force infosheet re-parse and ignore cached schedule data.',
            )
            ->addOption(
                'world-cups',
                null,
                InputOption::VALUE_NONE,
                'Include World Cups league season events.',
            )
            ->addOption(
                'games',
                null,
                InputOption::VALUE_NONE,
                'Include Games league season events.',
            )
            ->addOption(
                'paraclimbing',
                null,
                InputOption::VALUE_NONE,
                'Include Paraclimbing league season events.',
            )
            ->addOption(
                'outfile',
                null,
                InputOption::VALUE_REQUIRED,
                'Required output file path for schedule JSON.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $seasonYear = $this->resolveSeasonYear($input, $io);

        if ($seasonYear === null) {
            return Command::FAILURE;
        }

        try {
            $outputFilePath = $this->resolveOutputFilePath($input);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $forceRescan = (bool) $input->getOption('force-rescan');
        $leagueSeasonIds = $this->resolveLeagueSeasonIds($input);

        try {
            /** @var RecentEventsScheduleSyncService $syncService */
            $syncService = $this->container->get(RecentEventsScheduleSyncService::class);
            $events = $syncService->sync(
                seasonYear: $seasonYear,
                leagueSeasonIds: $leagueSeasonIds,
                forceRescan: $forceRescan,
            );
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            $json = json_encode($this->buildOutputPayload($events), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException) {
            $io->error('Unable to encode schedule JSON output.');

            return Command::FAILURE;
        }

        try {
            $this->writeJsonToFile($outputFilePath, $json);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<array<string,mixed>> $events
     * @return array{events:array<array<string,mixed>>}
     */
    private function buildOutputPayload(array $events): array
    {
        return ['events' => $events];
    }

    private function resolveOutputFilePath(InputInterface $input): string
    {
        $outputFilePath = $input->getOption('outfile');

        if (!is_string($outputFilePath) || trim($outputFilePath) === '') {
            throw new RuntimeException('The --outfile option is required and must be a non-empty file path.');
        }

        return trim($outputFilePath);
    }

    private function writeJsonToFile(string $outputFilePath, string $json): void
    {
        $directory = dirname($outputFilePath);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create output directory "%s".', $directory));
        }

        if (@file_put_contents($outputFilePath, "{$json}\n", LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write output file "%s".', $outputFilePath));
        }
    }

    private function resolveSeasonYear(InputInterface $input, SymfonyStyle $io): ?int
    {
        $season = $input->getOption('season');

        if (!is_string($season) || trim($season) === '') {
            $io->error('The --season option is required and must be a numeric year (for example: 2026).');

            return null;
        }

        $season = trim($season);

        if (!ctype_digit($season)) {
            $io->error('The --season option must be a numeric year (for example: 2026).');

            return null;
        }

        return (int) $season;
    }

    /** @return int[] */
    private function resolveLeagueSeasonIds(InputInterface $input): array
    {
        $selectedLeagueSeasonIds = [];

        if ($input->getOption('world-cups')) {
            $selectedLeagueSeasonIds[] = self::WORLD_CUPS_LEAGUE_SEASON_ID;
        }

        if ($input->getOption('games')) {
            $selectedLeagueSeasonIds[] = self::GAMES_LEAGUE_SEASON_ID;
        }

        if ($input->getOption('paraclimbing')) {
            $selectedLeagueSeasonIds[] = self::PARACLIMBING_LEAGUE_SEASON_ID;
        }

        if ($selectedLeagueSeasonIds) {
            return $selectedLeagueSeasonIds;
        }

        return [
            self::WORLD_CUPS_LEAGUE_SEASON_ID,
            self::GAMES_LEAGUE_SEASON_ID,
            self::PARACLIMBING_LEAGUE_SEASON_ID,
        ];
    }
}
