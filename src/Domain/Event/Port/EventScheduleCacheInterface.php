<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Port;

interface EventScheduleCacheInterface
{
    /** @param array<array<string,mixed>> $events */
    public function save(array $events): void;
}
