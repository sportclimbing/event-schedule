<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\Observability\Listener;

use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\Event\Entity\ScheduleGenerationFinishedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetScheduleCacheHitEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetPdfDownloadFailedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\InfoSheetPdfDownloadedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestFailedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestSucceededEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Listener\StdoutObservabilityListener;

final class StdoutObservabilityListenerTest extends TestCase
{
    public function testListenerWritesMessagesToConfiguredStreamPath(): void
    {
        $outputPath = sprintf('%s/ifsc-observability-%s.log', sys_get_temp_dir(), uniqid('', true));
        $listener = new StdoutObservabilityListener($outputPath);
        $projectPath = sprintf('%s/.cache/infosheet.pdf', (string) getcwd());
        $projectCachePath = sprintf('%s/.cache/cache.json', (string) getcwd());

        $listener->onInfoSheetPdfDownloaded(new InfoSheetPdfDownloadedEvent(
            url: 'https://ifsc.results.info/events/1/infosheet.pdf',
            path: $projectPath,
            statusCode: 200,
            sizeBytes: 1234,
        ));
        $listener->onInfoSheetPdfDownloadFailed(new InfoSheetPdfDownloadFailedEvent(
            url: 'https://ifsc.results.info/events/2/infosheet.pdf',
            statusCode: 500,
            reason: 'HTTP 500',
        ));
        $listener->onOpenAiApiRequestSucceeded(new OpenAiApiRequestSucceededEvent(
            method: 'POST',
            uri: 'https://api.openai.com/v1/responses',
            attempt: 1,
            maxAttempts: 2,
            statusCode: 200,
            durationMilliseconds: 250,
        ));
        $listener->onOpenAiApiRequestFailed(new OpenAiApiRequestFailedEvent(
            method: 'POST',
            uri: 'https://api.openai.com/v1/responses',
            attempt: 2,
            maxAttempts: 2,
            statusCode: 500,
            durationMilliseconds: 980,
            willRetry: false,
            reason: 'HTTP 500',
        ));
        $listener->onInfoSheetScheduleCacheHit(new InfoSheetScheduleCacheHitEvent(
            cacheId: 'abc123',
            path: $projectCachePath,
        ));
        $listener->onScheduleGenerationFinished(new ScheduleGenerationFinishedEvent(
            outputFilePath: '/tmp/events.json',
        ));

        $output = (string) file_get_contents($outputPath);

        self::assertStringContainsString('infosheet pdf downloaded', $output);
        self::assertStringContainsString('path=.cache/infosheet.pdf', $output);
        self::assertStringContainsString('infosheet pdf download failed', $output);
        self::assertStringContainsString('openai request succeeded', $output);
        self::assertStringContainsString('openai request failed', $output);
        self::assertStringContainsString('infosheet cache file found and used', $output);
        self::assertStringContainsString('path=.cache/cache.json', $output);
        self::assertStringContainsString('schedule generation finished', $output);

        @unlink($outputPath);
    }
}
