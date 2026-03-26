<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Command;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SportClimbing\EventDetails\Command\GenerateScheduleCommand;
use SportClimbing\EventDetails\Domain\Event\Entity\ScheduleGenerationFinishedEvent;
use SportClimbing\EventDetails\Domain\Event\Service\RecentEventsScheduleSyncService;
use SportClimbing\EventDetails\Domain\Schedule\Exception\InfoSheetScheduleParserException;
use SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi\OpenAiInfoSheetClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class GenerateScheduleCommandTest extends TestCase
{
    public function testExecuteWithOutfileWritesJsonToFileInsteadOfStdout(): void
    {
        $events = [[
            'event_id' => 77,
            'event_name' => 'File Output Event',
            'schedule' => [],
        ]];
        $syncService = new FakeSyncServiceForCommand($events);
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute(['--season' => '2027', '--outfile' => $outputPath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputPath);
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            ['events' => $events],
            json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR),
        );

        $this->removeDirectory(dirname($outputPath));
    }

    public function testExecuteDispatchesScheduleGenerationFinishedEventWithOutputPath(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $eventDispatcher = new FakeEventDispatcherForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand(
            syncService: $syncService,
            eventDispatcher: $eventDispatcher,
        ));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute(['--season' => '2027', '--outfile' => $outputPath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(1, $eventDispatcher->dispatchedEvents);
        self::assertInstanceOf(ScheduleGenerationFinishedEvent::class, $eventDispatcher->dispatchedEvents[0]);

        /** @var ScheduleGenerationFinishedEvent $event */
        $event = $eventDispatcher->dispatchedEvents[0];
        self::assertSame($outputPath, $event->outputFilePath);

        $this->removeDirectory(dirname($outputPath));
    }

    public function testExecuteWithEmptyOutfileReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027', '--outfile' => '   ']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('non-empty file path', $tester->getDisplay());
    }

    public function testExecuteWithoutOutfileReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('--outfile option is required', $tester->getDisplay());
    }

    public function testExecuteWithSeasonOptionUsesForceRescanFalseByDefault(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute(['--season' => '2027', '--outfile' => $outputPath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($syncService->called);
        self::assertFalse($syncService->lastForceRescan);
        self::assertSame(2027, $syncService->lastSeasonYear);
        self::assertSame([457, 318, 438], $syncService->lastLeagueSeasonIds);

        $this->removeDirectory(dirname($outputPath));
    }

    public function testExecuteWithForceRescanOptionPassesTrueToSyncService(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute([
            '--season' => '2027',
            '--force-rescan' => true,
            '--outfile' => $outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($syncService->called);
        self::assertTrue($syncService->lastForceRescan);
        self::assertSame(2027, $syncService->lastSeasonYear);
        self::assertSame([457, 318, 438], $syncService->lastLeagueSeasonIds);

        $this->removeDirectory(dirname($outputPath));
    }

    public function testExecuteWithLeagueOptionsPassesSelectedLeagueSeasonIdsOnly(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute([
            '--season' => '2027',
            '--league' => ['games', 'paraclimbing'],
            '--outfile' => $outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($syncService->called);
        self::assertSame([318, 438], $syncService->lastLeagueSeasonIds);

        $this->removeDirectory(dirname($outputPath));
    }

    public function testExecuteWithInvalidLeagueOptionReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute([
            '--season' => '2027',
            '--league' => ['bouldering'],
            '--outfile' => $outputPath,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('Invalid --league value', $tester->getDisplay());
    }

    public function testExecuteWithInvalidSeasonReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute(['--season' => 'foo', '--outfile' => $outputPath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('numeric year', $tester->getDisplay());
    }

    public function testExecuteWithoutSeasonReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute(['--outfile' => $outputPath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('--season option is required', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureWhenSyncServiceThrowsParserException(): void
    {
        $syncService = new FakeSyncServiceForCommand(
            exceptionToThrow: new InfoSheetScheduleParserException('OpenAI HTTP 500'),
        );
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute(['--season' => '2027', '--outfile' => $outputPath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertTrue($syncService->called);
        self::assertStringContainsString('OpenAI HTTP 500', $tester->getDisplay());
    }

    public function testExecuteWithInvalidOpenAiTemperatureReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute([
            '--season' => '2027',
            '--outfile' => $outputPath,
            '--openai-temperature' => '2.1',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('--openai-temperature', $tester->getDisplay());
    }

    public function testExecuteWithOpenAiTemperatureAndDefaultModelReturnsFailureEarly(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute([
            '--season' => '2027',
            '--outfile' => $outputPath,
            '--openai-temperature' => '0',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('not supported with model gpt-5-mini', $tester->getDisplay());
    }

    public function testExecuteWithOpenAiCliOptionsConfiguresClient(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $openAiClient = new OpenAiInfoSheetClient(new Client());
        $command = new GenerateScheduleCommand(new FakeContainerForCommand($syncService, $openAiClient));
        $tester = new CommandTester($command);
        $outputPath = $this->outputPath();

        $exitCode = $tester->execute([
            '--season' => '2027',
            '--outfile' => $outputPath,
            '--openai-model' => 'gpt-5',
            '--openai-temperature' => '0.4',
            '--openai-top-p' => '0.9',
            '--openai-http-timeout' => '30',
            '--openai-http-connect-timeout' => '3',
            '--openai-http-max-retries' => '1',
            '--openai-http-retry-backoff-ms' => '250',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('gpt-5', $this->readPrivateProperty($openAiClient, 'model'));
        self::assertSame(0.4, $this->readPrivateProperty($openAiClient, 'temperature'));
        self::assertSame(0.9, $this->readPrivateProperty($openAiClient, 'topP'));
        self::assertSame(30, $this->readPrivateProperty($openAiClient, 'httpTimeoutSeconds'));
        self::assertSame(3, $this->readPrivateProperty($openAiClient, 'connectTimeoutSeconds'));
        self::assertSame(1, $this->readPrivateProperty($openAiClient, 'maxRetries'));
        self::assertSame(250, $this->readPrivateProperty($openAiClient, 'retryBackoffMilliseconds'));

        $this->removeDirectory(dirname($outputPath));
    }

    private function outputPath(): string
    {
        $outputDirectory = sprintf('%s/ifsc-outfile-%s', sys_get_temp_dir(), uniqid('', true));

        return "{$outputDirectory}/events.json";
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());

                continue;
            }

            @unlink($entry->getPathname());
        }

        @rmdir($path);
    }

    private function readPrivateProperty(object $object, string $property): mixed
    {
        $reader = function (string $propertyName): mixed {
            return $this->{$propertyName};
        };

        /** @var callable(string):mixed $boundReader */
        $boundReader = $reader->bindTo($object, $object);

        return $boundReader($property);
    }
}

final readonly class FakeContainerForCommand implements ContainerInterface
{
    public function __construct(
        private FakeSyncServiceForCommand $syncService,
        private ?OpenAiInfoSheetClient $openAiInfoSheetClient = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function get(string $id): mixed
    {
        if ($id === RecentEventsScheduleSyncService::class) {
            return $this->syncService;
        }

        if ($id === OpenAiInfoSheetClient::class && $this->openAiInfoSheetClient instanceof OpenAiInfoSheetClient) {
            return $this->openAiInfoSheetClient;
        }

        if ($id === EventDispatcherInterface::class && $this->eventDispatcher instanceof EventDispatcherInterface) {
            return $this->eventDispatcher;
        }

        throw new RuntimeException(sprintf('Unknown service id: %s', $id));
    }

    public function has(string $id): bool
    {
        if ($id === RecentEventsScheduleSyncService::class) {
            return true;
        }

        if ($id === OpenAiInfoSheetClient::class && $this->openAiInfoSheetClient instanceof OpenAiInfoSheetClient) {
            return true;
        }

        return $id === EventDispatcherInterface::class && $this->eventDispatcher instanceof EventDispatcherInterface;
    }
}

final class FakeEventDispatcherForCommand implements EventDispatcherInterface
{
    /** @var object[] */
    public array $dispatchedEvents = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->dispatchedEvents[] = $event;

        return $event;
    }
}

final class FakeSyncServiceForCommand
{
    /** @param array<array<string,mixed>> $eventsToReturn */
    public function __construct(
        private array $eventsToReturn = [],
        private ?\Throwable $exceptionToThrow = null,
    ) {
    }

    public bool $called = false;
    public bool $lastForceRescan = false;
    public ?int $lastSeasonYear = null;
    /** @var int[] */
    public array $lastLeagueSeasonIds = [];

    /** @return array<array<string,mixed>> */
    public function sync(
        int $seasonYear,
        array $leagueSeasonIds = [457, 318, 438],
        bool $forceRescan = false,
    ): array
    {
        $this->called = true;
        $this->lastForceRescan = $forceRescan;
        $this->lastSeasonYear = $seasonYear;
        $this->lastLeagueSeasonIds = $leagueSeasonIds;

        if ($this->exceptionToThrow !== null) {
            throw $this->exceptionToThrow;
        }

        return $this->eventsToReturn;
    }
}
