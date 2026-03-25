<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\Schedule\Pdf;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetPdfDownloadFailedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetPdfDownloadedEvent;
use SportClimbing\EventDetails\Infrastructure\Schedule\Pdf\InfoSheetPdfDownloader;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class InfoSheetPdfDownloaderTest extends TestCase
{
    private ?string $originalCacheDir = null;

    protected function setUp(): void
    {
        $this->originalCacheDir = $_ENV['IFSC_INFOSHEET_PDF_CACHE_DIR'] ?? getenv('IFSC_INFOSHEET_PDF_CACHE_DIR') ?: null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('IFSC_INFOSHEET_PDF_CACHE_DIR', $this->originalCacheDir);
    }

    public function testDownloadDispatchesDownloadedEvent(): void
    {
        $downloadDir = sprintf('%s/ifsc-infosheet-pdf-cache-%s', sys_get_temp_dir(), uniqid('', true));
        $this->setEnv('IFSC_INFOSHEET_PDF_CACHE_DIR', $downloadDir);
        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            InfoSheetPdfDownloadedEvent::class,
            static function (InfoSheetPdfDownloadedEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $downloader = new InfoSheetPdfDownloader(
            httpClient: new Client([
                'handler' => HandlerStack::create(new MockHandler([
                    new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 test content'),
                ])),
            ]),
            eventDispatcher: $dispatcher,
        );

        $downloadedPdf = $downloader->download('https://ifsc.results.info/events/10/infosheet.pdf');

        self::assertFileExists($downloadedPdf->path);
        self::assertCount(1, $events);
        self::assertSame('https://ifsc.results.info/events/10/infosheet.pdf', $events[0]->url);
        self::assertSame($downloadedPdf->path, $events[0]->path);
        self::assertSame(200, $events[0]->statusCode);
        self::assertNotNull($events[0]->sizeBytes);

        $this->removeDirectory($downloadDir);
    }

    public function testDownloadDispatchesFailedEventOnHttpError(): void
    {
        $downloadDir = sprintf('%s/ifsc-infosheet-pdf-cache-%s', sys_get_temp_dir(), uniqid('', true));
        $this->setEnv('IFSC_INFOSHEET_PDF_CACHE_DIR', $downloadDir);
        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            InfoSheetPdfDownloadFailedEvent::class,
            static function (InfoSheetPdfDownloadFailedEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $downloader = new InfoSheetPdfDownloader(
            httpClient: new Client([
                'handler' => HandlerStack::create(new MockHandler([
                    new Response(500, ['Content-Type' => 'text/plain'], 'oops'),
                ])),
            ]),
            eventDispatcher: $dispatcher,
        );

        try {
            $downloader->download('https://ifsc.results.info/events/20/infosheet.pdf');
            self::fail('Expected download to throw for HTTP 500.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('HTTP 500', $exception->getMessage());
            self::assertCount(1, $events);
            self::assertSame('https://ifsc.results.info/events/20/infosheet.pdf', $events[0]->url);
            self::assertSame(500, $events[0]->statusCode);
            self::assertStringContainsString('HTTP 500', $events[0]->reason);
        } finally {
            $this->removeDirectory($downloadDir);
        }
    }

    private function setEnv(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }

    private function restoreEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name]);

            return;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());

                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
