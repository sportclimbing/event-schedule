<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\ReleaseNotes;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\JsonScheduleEventsLoader;

final class JsonScheduleEventsLoaderTest extends TestCase
{
    public function testLoadReturnsEventsArrayForValidPayload(): void
    {
        $workDir = sprintf('%s/ifsc-release-notes-loader-%s', sys_get_temp_dir(), uniqid('', true));
        $path = "{$workDir}/events.json";
        $loader = new JsonScheduleEventsLoader();

        if (!is_dir($workDir)) {
            mkdir($workDir, 0777, true);
        }

        file_put_contents(
            $path,
            (string) json_encode([
                'events' => [
                    ['event_id' => 1, 'event_name' => 'A'],
                    'invalid-entry',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $events = $loader->load($path, true);

        self::assertCount(1, $events);
        self::assertSame(1, $events[0]['event_id']);

        @unlink($path);
        @rmdir($workDir);
    }

    public function testLoadThrowsWhenRequiredFileDoesNotExist(): void
    {
        $loader = new JsonScheduleEventsLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $loader->load('/tmp/ifsc-missing-release-notes-file.json', true);
    }
}
