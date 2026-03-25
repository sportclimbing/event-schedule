<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\ReleaseNotes;

use JsonException;
use RuntimeException;

final class JsonScheduleEventsLoader
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function load(string $path, bool $required): array
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
}
