<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Command;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SportClimbing\EventDetails\Command\GenerateScheduleReleaseNotesCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateScheduleReleaseNotesCommandTest extends TestCase
{
    public function testExecuteSupportsPreviousCurrentAndOutfileOptions(): void
    {
        $workDir = sprintf('%s/ifsc-release-notes-%s', sys_get_temp_dir(), uniqid('', true));
        $previousPath = "{$workDir}/previous.json";
        $currentPath = "{$workDir}/current.json";
        $outputPath = "{$workDir}/release-notes.txt";

        $this->writeJson($previousPath, ['events' => []]);
        $this->writeJson($currentPath, [
            'events' => [
                ['event_id' => 3, 'event_name' => 'Boulder Final', 'schedule' => []],
            ],
        ]);

        $tester = new CommandTester(new GenerateScheduleReleaseNotesCommand());
        $exitCode = $tester->execute([
            '--previous' => $previousPath,
            '--current' => $currentPath,
            '--outfile' => $outputPath,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputPath);
        $output = (string) file_get_contents($outputPath);
        self::assertStringContainsString('| Metric | Value |', $output);
        self::assertStringContainsString(sprintf('| Previous file | `%s` |', $previousPath), $output);
        self::assertStringContainsString(sprintf('| Current file | `%s` |', $currentPath), $output);
        self::assertStringContainsString('| Added events | 1 |', $output);
        self::assertStringContainsString('## Added Events', $output);

        $this->removeDirectory($workDir);
    }

    public function testExecuteOutputsAddedRemovedAndChangedEvents(): void
    {
        $workDir = sprintf('%s/ifsc-release-notes-%s', sys_get_temp_dir(), uniqid('', true));
        $previousPath = "{$workDir}/previous.json";
        $currentPath = "{$workDir}/current.json";

        $this->writeJson($previousPath, [
            'events' => [
                ['event_id' => 1, 'event_name' => 'A', 'schedule' => []],
                ['event_id' => 2, 'event_name' => 'B', 'schedule' => []],
            ],
        ]);
        $this->writeJson($currentPath, [
            'events' => [
                ['event_id' => 1, 'event_name' => 'A Updated', 'schedule' => []],
                ['event_id' => 3, 'event_name' => 'C', 'schedule' => []],
            ],
        ]);

        $tester = new CommandTester(new GenerateScheduleReleaseNotesCommand());
        $exitCode = $tester->execute([
            'previous-path' => $previousPath,
            'current-path' => $currentPath,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('| Added events | 1 |', $display);
        self::assertStringContainsString('| Removed events | 1 |', $display);
        self::assertStringContainsString('| Changed events | 1 |', $display);
        self::assertStringContainsString('## Added Events', $display);
        self::assertStringContainsString('| 3 | C |', $display);
        self::assertStringContainsString('## Removed Events', $display);
        self::assertStringContainsString('| 2 | B |', $display);
        self::assertStringContainsString('## Changed Events', $display);
        self::assertStringContainsString('| Event ID | Event Name | Field | Old value | New value |', $display);
        self::assertStringContainsString('| 1 | A Updated | event name | A | A Updated |', $display);

        $this->removeDirectory($workDir);
    }

    public function testExecuteWritesReportWhenOutputPathIsProvided(): void
    {
        $workDir = sprintf('%s/ifsc-release-notes-%s', sys_get_temp_dir(), uniqid('', true));
        $previousPath = "{$workDir}/previous.json";
        $currentPath = "{$workDir}/current.json";
        $outputPath = "{$workDir}/artifacts/release-notes.txt";

        $this->writeJson($previousPath, ['events' => []]);
        $this->writeJson($currentPath, [
            'events' => [
                ['event_id' => 99, 'event_name' => 'Lead Finals', 'schedule' => []],
            ],
        ]);

        $tester = new CommandTester(new GenerateScheduleReleaseNotesCommand());
        $exitCode = $tester->execute([
            'previous-path' => $previousPath,
            'current-path' => $currentPath,
            'output-path' => $outputPath,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileExists($outputPath);
        self::assertSame((string) file_get_contents($outputPath), $display);

        $this->removeDirectory($workDir);
    }

    public function testExecuteOutputsNestedFieldPathAndRawValuesInsteadOfJsonBlob(): void
    {
        $workDir = sprintf('%s/ifsc-release-notes-%s', sys_get_temp_dir(), uniqid('', true));
        $previousPath = "{$workDir}/previous.json";
        $currentPath = "{$workDir}/current.json";

        $this->writeJson($previousPath, [
            'events' => [[
                'event_id' => 1,
                'event_name' => 'A',
                'schedule' => [[
                    'name' => 'Final',
                    'starts_at' => '2026-06-20 19:00',
                ]],
            ]],
        ]);
        $this->writeJson($currentPath, [
            'events' => [[
                'event_id' => 1,
                'event_name' => 'A',
                'schedule' => [[
                    'name' => 'Final',
                    'starts_at' => '2026-06-20 20:00',
                ]],
            ]],
        ]);

        $tester = new CommandTester(new GenerateScheduleReleaseNotesCommand());
        $exitCode = $tester->execute([
            'previous-path' => $previousPath,
            'current-path' => $currentPath,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString(
            '| 1 | A | schedule[0].starts at | 2026-06-20 19:00 | 2026-06-20 20:00 |',
            $display,
        );
        self::assertStringNotContainsString('{"name":"Final"', $display);

        $this->removeDirectory($workDir);
    }

    public function testExecuteFailsWhenCurrentFileDoesNotExist(): void
    {
        $workDir = sprintf('%s/ifsc-release-notes-%s', sys_get_temp_dir(), uniqid('', true));
        $previousPath = "{$workDir}/previous.json";
        $currentPath = "{$workDir}/missing-current.json";

        $this->writeJson($previousPath, ['events' => []]);

        $tester = new CommandTester(new GenerateScheduleReleaseNotesCommand());
        $exitCode = $tester->execute([
            'previous-path' => $previousPath,
            'current-path' => $currentPath,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('does not exist', $tester->getDisplay());

        $this->removeDirectory($workDir);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            $path,
            (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX,
        );
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
