<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Domain\Event\Port\Dto;

final readonly class DownloadedPdf
{
    /** @param array<mixed> $headers */
    public function __construct(
        public string $path,
        public array $headers,
    ) {
    }
}
