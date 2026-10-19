<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\IFSC;

final readonly class IfscApiSession
{
    public function __construct(
        public string $sessionId,
        public string $csrfToken,
        public string $internalToken,
    ) {
    }
}
