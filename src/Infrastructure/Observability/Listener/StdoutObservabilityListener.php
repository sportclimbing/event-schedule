<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Observability\Listener;

use SportClimbing\EventDetails\Domain\Event\Entity\ScheduleGenerationFinishedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetScheduleCacheHitEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetPdfDownloadFailedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetPdfDownloadedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestFailedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestSucceededEvent;

final readonly class StdoutObservabilityListener
{
    public function __construct(
        private string $streamPath = 'php://stdout',
    ) {
    }

    public function onInfoSheetPdfDownloaded(InfoSheetPdfDownloadedEvent $event): void
    {
        $this->writeLine(sprintf(
            '[+] infosheet pdf downloaded url=%s path=%s status=%d size_bytes=%s',
            $event->url,
            $event->path,
            $event->statusCode,
            $event->sizeBytes === null ? 'unknown' : (string) $event->sizeBytes,
        ));
    }

    public function onInfoSheetPdfDownloadFailed(InfoSheetPdfDownloadFailedEvent $event): void
    {
        $this->writeLine(sprintf(
            '[-] infosheet pdf download failed url=%s status=%s reason=%s',
            $event->url,
            $event->statusCode === null ? 'unknown' : (string) $event->statusCode,
            $event->reason,
        ));
    }

    public function onOpenAiApiRequestSucceeded(OpenAiApiRequestSucceededEvent $event): void
    {
        $this->writeLine(sprintf(
            '[+] openai request succeeded method=%s uri=%s attempt=%d/%d status=%d duration_ms=%d',
            $event->method,
            $event->uri,
            $event->attempt,
            $event->maxAttempts,
            $event->statusCode,
            $event->durationMilliseconds,
        ));
    }

    public function onOpenAiApiRequestFailed(OpenAiApiRequestFailedEvent $event): void
    {
        $this->writeLine(sprintf(
            '[-] openai request failed method=%s uri=%s attempt=%d/%d status=%s duration_ms=%d will_retry=%s reason=%s',
            $event->method,
            $event->uri,
            $event->attempt,
            $event->maxAttempts,
            $event->statusCode === null ? 'unknown' : (string) $event->statusCode,
            $event->durationMilliseconds,
            $event->willRetry ? 'true' : 'false',
            $event->reason,
        ));
    }

    public function onInfoSheetScheduleCacheHit(InfoSheetScheduleCacheHitEvent $event): void
    {
        $this->writeLine(sprintf(
            '[+] infosheet cache file found and used cache_id=%s path=%s',
            $event->cacheId,
            $event->path,
        ));
    }

    public function onScheduleGenerationFinished(ScheduleGenerationFinishedEvent $event): void
    {
        $this->writeLine(sprintf(
            '[+] schedule generation finished output_file=%s',
            $event->outputFilePath,
        ));
    }

    private function writeLine(string $message): void
    {
        $stream = @fopen($this->streamPath, 'ab');

        if ($stream === false) {
            return;
        }

        fwrite($stream, $message . PHP_EOL);
        fclose($stream);
    }
}
