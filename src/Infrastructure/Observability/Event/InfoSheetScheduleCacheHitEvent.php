<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Observability\Event;

final readonly class InfoSheetScheduleCacheHitEvent
{
    public function __construct(
        public string $cacheId,
        public string $path,
    ) {
    }
}
