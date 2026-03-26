<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\Container;

use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\Event\Port\EventInfoProviderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\EventScheduleCacheInterface;
use SportClimbing\EventDetails\Domain\Event\Port\InfoSheetPdfDownloaderInterface;
use SportClimbing\EventDetails\Domain\Event\Port\IfscApiClientInterface;
use SportClimbing\EventDetails\Domain\Event\Port\RecentLeagueProviderInterface;
use SportClimbing\EventDetails\Domain\Event\Service\IfscEventInfoProvider;
use SportClimbing\EventDetails\Domain\Event\Service\RecentEventsScheduleSyncService;
use SportClimbing\EventDetails\Domain\ReleaseNotes\Service\ScheduleReleaseNotesDiffService;
use SportClimbing\EventDetails\Domain\Schedule\Port\InfoSheetScheduleParserInterface;
use SportClimbing\EventDetails\Infrastructure\Container\SymfonyServiceContainerFactory;
use SportClimbing\EventDetails\Infrastructure\Event\Cache\EventScheduleJsonCache;
use SportClimbing\EventDetails\Infrastructure\IFSC\GuzzleIfscApiClient;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscApiClientFactory;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscRecentLeagueProvider;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscApiSessionAuthenticator;
use SportClimbing\EventDetails\Infrastructure\Observability\Listener\StdoutObservabilityListener;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\JsonScheduleEventsLoader;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\MarkdownScheduleReleaseNotesRenderer;
use SportClimbing\EventDetails\Infrastructure\ReleaseNotes\TextReportFileWriter;
use SportClimbing\EventDetails\Infrastructure\Schedule\InfoSheetChatGptScheduleParser;
use SportClimbing\EventDetails\Infrastructure\Schedule\Pdf\InfoSheetPdfDownloader;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SymfonyServiceContainerFactoryTest extends TestCase
{
    public function testBuildLoadsDomainAndInfrastructureServiceDefinitions(): void
    {
        $container = (new SymfonyServiceContainerFactory())->build();

        self::assertTrue($container->hasDefinition(IfscEventInfoProvider::class));
        self::assertTrue($container->hasDefinition(IfscApiSessionAuthenticator::class));
        self::assertTrue($container->hasDefinition(IfscApiClientFactory::class));
        self::assertTrue($container->hasDefinition(GuzzleIfscApiClient::class));
        self::assertTrue($container->hasDefinition(IfscRecentLeagueProvider::class));
        self::assertTrue($container->hasDefinition(InfoSheetPdfDownloader::class));
        self::assertTrue($container->hasDefinition(EventScheduleJsonCache::class));
        self::assertTrue($container->hasDefinition(InfoSheetChatGptScheduleParser::class));
        self::assertTrue($container->hasDefinition(StdoutObservabilityListener::class));
        self::assertTrue($container->hasDefinition(EventDispatcher::class));
        self::assertTrue($container->hasDefinition(RecentEventsScheduleSyncService::class));
        self::assertTrue($container->hasDefinition(ScheduleReleaseNotesDiffService::class));
        self::assertTrue($container->hasDefinition(JsonScheduleEventsLoader::class));
        self::assertTrue($container->hasDefinition(MarkdownScheduleReleaseNotesRenderer::class));
        self::assertTrue($container->hasDefinition(TextReportFileWriter::class));

        self::assertTrue($container->hasAlias(EventInfoProviderInterface::class));
        self::assertSame(
            IfscEventInfoProvider::class,
            (string) $container->getAlias(EventInfoProviderInterface::class),
        );

        self::assertTrue($container->hasAlias(IfscApiClientInterface::class));
        self::assertSame(
            GuzzleIfscApiClient::class,
            (string) $container->getAlias(IfscApiClientInterface::class),
        );

        self::assertTrue($container->hasAlias(InfoSheetScheduleParserInterface::class));
        self::assertSame(
            InfoSheetChatGptScheduleParser::class,
            (string) $container->getAlias(InfoSheetScheduleParserInterface::class),
        );

        self::assertTrue($container->hasAlias(RecentLeagueProviderInterface::class));
        self::assertSame(
            IfscRecentLeagueProvider::class,
            (string) $container->getAlias(RecentLeagueProviderInterface::class),
        );

        self::assertTrue($container->hasAlias(InfoSheetPdfDownloaderInterface::class));
        self::assertSame(
            InfoSheetPdfDownloader::class,
            (string) $container->getAlias(InfoSheetPdfDownloaderInterface::class),
        );

        self::assertTrue($container->hasAlias(EventScheduleCacheInterface::class));
        self::assertSame(
            EventScheduleJsonCache::class,
            (string) $container->getAlias(EventScheduleCacheInterface::class),
        );

        self::assertTrue($container->hasAlias(EventDispatcherInterface::class));
        self::assertSame(
            EventDispatcher::class,
            (string) $container->getAlias(EventDispatcherInterface::class),
        );
    }

    public function testCreateKeepsEventDispatcherAvailableAtRuntime(): void
    {
        $container = (new SymfonyServiceContainerFactory())->create();

        self::assertTrue($container->has(EventDispatcherInterface::class));
        self::assertTrue($container->has(EventDispatcher::class));
        self::assertInstanceOf(EventDispatcher::class, $container->get(EventDispatcherInterface::class));
    }
}
