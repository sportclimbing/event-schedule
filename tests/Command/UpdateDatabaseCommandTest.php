<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Command;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SportClimbing\EventDetails\Command\UpdateDatabaseCommand;
use SportClimbing\EventDetails\Domain\Event\Service\RecentEventsScheduleSyncService;
use SportClimbing\EventDetails\Domain\Schedule\Exception\InfoSheetScheduleParserException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UpdateDatabaseCommandTest extends TestCase
{
    public function testExecuteWithOutfileWritesJsonToFileInsteadOfStdout(): void
    {
        $events = [[
            'event_id' => 77,
            'event_name' => 'File Output Event',
            'schedule' => [],
        ]];
        $syncService = new FakeSyncServiceForCommand($events);
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);
        $outputDirectory = sprintf('%s/ifsc-outfile-%s', sys_get_temp_dir(), uniqid('', true));
        $outputPath = "{$outputDirectory}/events.json";

        $exitCode = $tester->execute(['--season' => '2027', '--outfile' => $outputPath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputPath);
        self::assertSame('', $tester->getDisplay());
        self::assertSame(
            ['events' => $events],
            json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR),
        );

        $this->removeDirectory($outputDirectory);
    }

    public function testExecuteWithEmptyOutfileReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027', '--outfile' => '   ']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('non-empty file path', $tester->getDisplay());
    }

    public function testExecuteOutputsJsonPayloadWithEventsKey(): void
    {
        $events = [[
            'event_id' => 1,
            'event_name' => 'Test Event',
            'schedule' => [],
        ]];
        $syncService = new FakeSyncServiceForCommand($events);
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('Generate Schedule', $display);
        self::assertSame(
            ['events' => $events],
            json_decode($display, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testExecuteWithSeasonOptionUsesForceRescanFalseByDefault(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($syncService->called);
        self::assertFalse($syncService->lastForceRescan);
        self::assertSame(2027, $syncService->lastSeasonYear);
        self::assertSame([457, 318, 438], $syncService->lastLeagueSeasonIds);
    }

    public function testExecuteWithForceRescanOptionPassesTrueToSyncService(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027', '--force-rescan' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($syncService->called);
        self::assertTrue($syncService->lastForceRescan);
        self::assertSame(2027, $syncService->lastSeasonYear);
        self::assertSame([457, 318, 438], $syncService->lastLeagueSeasonIds);
    }

    public function testExecuteWithLeagueFlagsPassesSelectedLeagueSeasonIdsOnly(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027', '--games' => true, '--paraclimbing' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertTrue($syncService->called);
        self::assertSame([318, 438], $syncService->lastLeagueSeasonIds);
    }

    public function testExecuteWithInvalidSeasonReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => 'foo']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('numeric year', $tester->getDisplay());
    }

    public function testExecuteWithoutSeasonReturnsFailure(): void
    {
        $syncService = new FakeSyncServiceForCommand();
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertFalse($syncService->called);
        self::assertStringContainsString('--season option is required', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureWhenSyncServiceThrowsParserException(): void
    {
        $syncService = new FakeSyncServiceForCommand(
            exceptionToThrow: new InfoSheetScheduleParserException('OpenAI HTTP 500'),
        );
        $command = new UpdateDatabaseCommand(new FakeContainerForCommand($syncService));
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--season' => '2027']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertTrue($syncService->called);
        self::assertStringContainsString('OpenAI HTTP 500', $tester->getDisplay());
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
}

final readonly class FakeContainerForCommand implements ContainerInterface
{
    public function __construct(
        private FakeSyncServiceForCommand $syncService,
    ) {
    }

    public function get(string $id): mixed
    {
        if ($id === RecentEventsScheduleSyncService::class) {
            return $this->syncService;
        }

        throw new RuntimeException(sprintf('Unknown service id: %s', $id));
    }

    public function has(string $id): bool
    {
        return $id === RecentEventsScheduleSyncService::class;
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
