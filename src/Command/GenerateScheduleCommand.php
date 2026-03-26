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
use SportClimbing\EventDetails\Domain\Event\Entity\ScheduleGenerationFinishedEvent;
use SportClimbing\EventDetails\Domain\Event\Service\RecentEventsScheduleSyncService;
use SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi\OpenAiInfoSheetClient;
use Throwable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class GenerateScheduleCommand extends Command
{
    public const string NAME = 'sportclimbing:generate-schedule';
    private const string DEFAULT_OPENAI_MODEL = 'gpt-5-mini';
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
                'league',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'League filter (repeatable). Allowed values: world-cups, games, paraclimbing.',
            )
            ->addOption(
                'outfile',
                null,
                InputOption::VALUE_REQUIRED,
                'Required output file path for schedule JSON.',
            )
            ->addOption(
                'openai-model',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenAI model (default: gpt-5-mini).',
            )
            ->addOption(
                'openai-temperature',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenAI temperature (0..2, default: 0).',
            )
            ->addOption(
                'openai-top-p',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenAI top_p (0..1, default: 1).',
            )
            ->addOption(
                'openai-http-timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenAI HTTP timeout in seconds (default: 120).',
            )
            ->addOption(
                'openai-http-connect-timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenAI HTTP connect timeout in seconds (default: 10).',
            )
            ->addOption(
                'openai-http-max-retries',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenAI HTTP max retries (default: 4).',
            )
            ->addOption(
                'openai-http-retry-backoff-ms',
                null,
                InputOption::VALUE_REQUIRED,
                'OpenAI HTTP retry backoff in milliseconds (default: 500).',
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

        try {
            $leagueSeasonIds = $this->resolveLeagueSeasonIds($input);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        try {
            $this->configureOpenAiClient($input);
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

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

        try {
            $this->dispatchScheduleGenerationFinishedEvent($outputFilePath);
        } catch (Throwable $exception) {
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

    private function dispatchScheduleGenerationFinishedEvent(string $outputFilePath): void
    {
        if (!$this->container->has(EventDispatcherInterface::class)) {
            return;
        }

        $eventDispatcher = $this->container->get(EventDispatcherInterface::class);

        if (!$eventDispatcher instanceof EventDispatcherInterface) {
            throw new RuntimeException(sprintf(
                'Container service "%s" is not an EventDispatcherInterface.',
                EventDispatcherInterface::class,
            ));
        }

        $eventDispatcher->dispatch(new ScheduleGenerationFinishedEvent($outputFilePath));
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

    /**
     * @return int[]
     */
    private function resolveLeagueSeasonIds(InputInterface $input): array
    {
        $leagueOptionValues = $input->getOption('league');

        if (!is_array($leagueOptionValues) || $leagueOptionValues === []) {
            return [
                self::WORLD_CUPS_LEAGUE_SEASON_ID,
                self::GAMES_LEAGUE_SEASON_ID,
                self::PARACLIMBING_LEAGUE_SEASON_ID,
            ];
        }

        $knownLeagues = [
            'world-cups' => self::WORLD_CUPS_LEAGUE_SEASON_ID,
            'games' => self::GAMES_LEAGUE_SEASON_ID,
            'paraclimbing' => self::PARACLIMBING_LEAGUE_SEASON_ID,
        ];
        $selectedLeagueSeasonIds = [];

        foreach ($leagueOptionValues as $leagueOptionValue) {
            if (!is_string($leagueOptionValue)) {
                throw new RuntimeException('Each --league value must be a string.');
            }

            $normalizedLeague = strtolower(trim($leagueOptionValue));

            if ($normalizedLeague === '' || !array_key_exists($normalizedLeague, $knownLeagues)) {
                throw new RuntimeException(
                    'Invalid --league value. Allowed values: world-cups, games, paraclimbing.',
                );
            }

            $leagueSeasonId = $knownLeagues[$normalizedLeague];

            if (!in_array($leagueSeasonId, $selectedLeagueSeasonIds, true)) {
                $selectedLeagueSeasonIds[] = $leagueSeasonId;
            }
        }

        return $selectedLeagueSeasonIds;
    }

    private function configureOpenAiClient(InputInterface $input): void
    {
        $model = $this->resolveOptionalStringOption($input, 'openai-model');
        $temperature = $this->resolveOptionalFloatOption($input, 'openai-temperature', 0.0, 2.0);
        $topP = $this->resolveOptionalFloatOption($input, 'openai-top-p', 0.0, 1.0);
        $httpTimeout = $this->resolveOptionalIntegerOption($input, 'openai-http-timeout', 1);
        $httpConnectTimeout = $this->resolveOptionalIntegerOption($input, 'openai-http-connect-timeout', 1);
        $httpMaxRetries = $this->resolveOptionalIntegerOption($input, 'openai-http-max-retries', 0);
        $httpRetryBackoffMs = $this->resolveOptionalIntegerOption($input, 'openai-http-retry-backoff-ms', 0);
        $this->validateOpenAiOptionCompatibility(
            model: $model,
            temperature: $temperature,
        );

        if (
            $model === null
            && $temperature === null
            && $topP === null
            && $httpTimeout === null
            && $httpConnectTimeout === null
            && $httpMaxRetries === null
            && $httpRetryBackoffMs === null
        ) {
            return;
        }

        if (!$this->container->has(OpenAiInfoSheetClient::class)) {
            return;
        }

        $openAiClient = $this->container->get(OpenAiInfoSheetClient::class);

        if (!$openAiClient instanceof OpenAiInfoSheetClient) {
            throw new RuntimeException(
                sprintf('Container service "%s" is not an OpenAiInfoSheetClient.', OpenAiInfoSheetClient::class),
            );
        }

        $openAiClient->applyRuntimeConfiguration(
            model: $model,
            temperature: $temperature,
            topP: $topP,
            httpTimeoutSeconds: $httpTimeout,
            connectTimeoutSeconds: $httpConnectTimeout,
            maxRetries: $httpMaxRetries,
            retryBackoffMilliseconds: $httpRetryBackoffMs,
        );
    }

    private function resolveOptionalStringOption(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The --%s option must be a string.', $option));
        }

        $normalized = trim($value);

        if ($normalized === '') {
            throw new RuntimeException(sprintf('The --%s option must not be empty when provided.', $option));
        }

        return $normalized;
    }

    private function validateOpenAiOptionCompatibility(?string $model, ?float $temperature): void
    {
        if ($temperature === null) {
            return;
        }

        $effectiveModel = strtolower(trim($model ?? self::DEFAULT_OPENAI_MODEL));

        if ($effectiveModel === self::DEFAULT_OPENAI_MODEL) {
            throw new RuntimeException(
                'The --openai-temperature option is not supported with model gpt-5-mini. '
                . 'Use a different --openai-model or omit --openai-temperature.',
            );
        }
    }

    private function resolveOptionalFloatOption(
        InputInterface $input,
        string $option,
        float $min,
        float $max,
    ): ?float {
        $rawValue = $this->resolveOptionalNumericRawValue($input, $option);

        if ($rawValue === null) {
            return null;
        }

        if (!is_numeric($rawValue)) {
            throw new RuntimeException(
                sprintf('The --%s option must be numeric (between %s and %s).', $option, $min, $max),
            );
        }

        $floatValue = (float) $rawValue;

        if (!is_finite($floatValue) || $floatValue < $min || $floatValue > $max) {
            throw new RuntimeException(
                sprintf('The --%s option must be between %s and %s.', $option, $min, $max),
            );
        }

        return $floatValue;
    }

    private function resolveOptionalIntegerOption(
        InputInterface $input,
        string $option,
        int $min,
    ): ?int {
        $rawValue = $this->resolveOptionalNumericRawValue($input, $option);

        if ($rawValue === null) {
            return null;
        }

        if (preg_match('/^-?\d+$/', $rawValue) !== 1) {
            throw new RuntimeException(
                sprintf('The --%s option must be an integer greater than or equal to %d.', $option, $min),
            );
        }

        $intValue = (int) $rawValue;

        if ($intValue < $min) {
            throw new RuntimeException(
                sprintf('The --%s option must be an integer greater than or equal to %d.', $option, $min),
            );
        }

        return $intValue;
    }

    private function resolveOptionalNumericRawValue(InputInterface $input, string $option): ?string
    {
        $value = $input->getOption($option);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('The --%s option must be a value.', $option));
        }

        $normalized = trim($value);

        if ($normalized === '') {
            throw new RuntimeException(sprintf('The --%s option must not be empty when provided.', $option));
        }

        return $normalized;
    }
}
