<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi;

use SportClimbing\EventDetails\Infrastructure\Schedule\Exception\InfoSheetChatGptScheduleParserException;

final class OpenAiInfoSheetPdfContentBuilder
{
    private const int PDF_SIGNATURE_LENGTH = 5;

    /**
     * @return array{type:string,filename:string,file_data:string}
     * @throws InfoSheetChatGptScheduleParserException
     */
    public function buildPdfDataInputContent(string $pdfPath): array
    {
        $normalizedPath = trim($pdfPath);
        $this->readPdfDiagnostics($normalizedPath);
        $pdfData = @file_get_contents($normalizedPath);

        if (!is_string($pdfData) || $pdfData === '') {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Unable to read PDF content for inline parsing (%s)',
                    $this->formatContext([
                        'step' => 'parse_schedule',
                        'parse_strategy' => 'full_schema',
                        'pdf_path' => $normalizedPath,
                    ]),
                ),
            );
        }

        return [
            'type' => 'input_file',
            'filename' => $this->asPdfFilename($normalizedPath),
            'file_data' => sprintf('data:application/pdf;base64,%s', base64_encode($pdfData)),
        ];
    }

    public function asPdfFilename(string $pdfPath): string
    {
        $filename = basename($pdfPath);

        if (str_ends_with(strtolower($filename), '.pdf')) {
            return $filename;
        }

        return "{$filename}.pdf";
    }

    /**
     * @return array{
     *   pdf_path:string,
     *   pdf_exists:bool,
     *   pdf_readable:bool,
     *   pdf_size_bytes:?int,
     *   pdf_sha256:?string,
     *   pdf_signature:?string
     * }
     * @throws InfoSheetChatGptScheduleParserException
     */
    public function readPdfDiagnostics(string $pdfPath): array
    {
        $normalizedPath = trim($pdfPath);
        $exists = is_file($normalizedPath);
        $readable = $exists && is_readable($normalizedPath);
        $size = $readable ? @filesize($normalizedPath) : null;
        $hash = $readable ? @hash_file('sha256', $normalizedPath) : null;

        $signature = null;

        if ($readable) {
            $header = @file_get_contents($normalizedPath, false, null, 0, self::PDF_SIGNATURE_LENGTH);

            if (is_string($header) && $header !== '') {
                $signature = trim($header) !== '' ? $header : null;
            }
        }

        $diagnostics = [
            'pdf_path' => $normalizedPath,
            'pdf_exists' => $exists,
            'pdf_readable' => $readable,
            'pdf_size_bytes' => is_int($size) ? $size : null,
            'pdf_sha256' => is_string($hash) && trim($hash) !== '' ? $hash : null,
            'pdf_signature' => $signature,
        ];

        if (!$exists || !$readable || !is_int($size) || $size <= 0) {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Infosheet PDF precheck failed (%s)',
                    $this->formatContext([
                        'step' => 'upload_precheck',
                        ...$diagnostics,
                    ]),
                ),
            );
        }

        return $diagnostics;
    }

    /** @param array<string,mixed> $context */
    private function formatContext(array $context): string
    {
        $parts = [];

        foreach ($this->normalizeContext($context) as $key => $value) {
            if ($value === null) {
                $parts[] = "{$key}=null";

                continue;
            }

            if (is_bool($value)) {
                $parts[] = sprintf('%s=%s', $key, $value ? 'true' : 'false');

                continue;
            }

            $parts[] = sprintf('%s=%s', $key, (string) $value);
        }

        return implode(', ', $parts);
    }

    /** @param array<string,mixed> $context */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            $key = trim($key);

            if (is_string($value)) {
                $value = trim($value);
                $normalized[$key] = $value === '' ? null : $value;

                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
