<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Observability\Event;

final readonly class OpenAiApiRequestFailedEvent
{
    public function __construct(
        public string $method,
        public string $uri,
        public int $attempt,
        public int $maxAttempts,
        public ?int $statusCode,
        public int $durationMilliseconds,
        public bool $willRetry,
        public string $reason,
    ) {
    }
}
