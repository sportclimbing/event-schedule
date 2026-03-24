<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Schedule\Pdf;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use RuntimeException;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\DownloadedPdf;
use SportClimbing\EventDetails\Domain\Event\Port\InfoSheetPdfDownloaderInterface;

final class InfoSheetPdfDownloader implements InfoSheetPdfDownloaderInterface
{
    private const string DEFAULT_DOWNLOAD_DIR = '.cache/infosheet/pdf';

    private string $downloadDir;

    public function __construct(
        private readonly ClientInterface $httpClient,
    ) {
        $configuredDir = $_ENV['IFSC_INFOSHEET_PDF_CACHE_DIR'] ?? getenv('IFSC_INFOSHEET_PDF_CACHE_DIR');
        $resolvedDir = is_string($configuredDir) && trim($configuredDir) !== ''
            ? trim($configuredDir)
            : self::DEFAULT_DOWNLOAD_DIR;

        $this->downloadDir = rtrim($this->resolvePath($resolvedDir), '/');
    }

    public function download(string $url): DownloadedPdf
    {
        $normalizedUrl = trim($url);

        if ($normalizedUrl === '') {
            throw new RuntimeException('Infosheet URL cannot be empty.');
        }

        $this->ensureDownloadDirectory();
        $targetPath = $this->filePathForUrl($normalizedUrl);

        try {
            $response = $this->httpClient->request('GET', $normalizedUrl, [
                RequestOptions::SINK => $targetPath,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::HEADERS => [
                    'Accept' => 'application/pdf,*/*',
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException(
                sprintf('Unable to download infosheet from "%s": %s', $normalizedUrl, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException(
                sprintf(
                    'Unable to download infosheet from "%s": HTTP %d',
                    $normalizedUrl,
                    $response->getStatusCode(),
                ),
            );
        }

        return new DownloadedPdf(
            path: $targetPath,
            headers: $response->getHeaders(),
        );
    }

    private function ensureDownloadDirectory(): void
    {
        if (is_dir($this->downloadDir)) {
            return;
        }

        if (!@mkdir($this->downloadDir, 0777, true) && !is_dir($this->downloadDir)) {
            throw new RuntimeException(sprintf('Unable to create download directory "%s".', $this->downloadDir));
        }
    }

    private function filePathForUrl(string $url): string
    {
        return sprintf('%s/%s.pdf', $this->downloadDir, hash('sha256', $url));
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return sprintf('%s/%s', $this->projectRoot(), ltrim($path, '/'));
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}
