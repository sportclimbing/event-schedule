<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\ReleaseNotes;

use RuntimeException;

final class TextReportFileWriter
{
    public function write(string $outputPath, string $report): void
    {
        $directory = dirname($outputPath);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create output directory "%s".', $directory));
        }

        if (@file_put_contents($outputPath, $report) === false) {
            throw new RuntimeException(sprintf('Unable to write report file "%s".', $outputPath));
        }
    }
}
