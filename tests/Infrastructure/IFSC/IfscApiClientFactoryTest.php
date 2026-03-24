<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\IFSC;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Infrastructure\IFSC\GuzzleIfscApiClient;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscApiClientFactory;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscApiSessionAuthenticator;

final class IfscApiClientFactoryTest extends TestCase
{
    public function testCreateBuildsWorkingApiClient(): void
    {
        $historyContainer = [];

        $handler = HandlerStack::create(
            new MockHandler([
                new Response(
                    200,
                    [],
                    json_encode([
                        'events' => [
                            [
                                'event_id' => 42,
                                'event' => 'IFSC World Cup',
                                'local_start_date' => '2026-08-10',
                                'local_end_date' => '2026-08-11',
                                'league_name' => 'World Cups and World Championships',
                                'infosheet_url' => 'https://ifsc.results.info/events/42/infosheet',
                                'ticket_url' => 'https://tickets.example.com/world-cup',
                                'ticket_price' => '12.50',
                                'ticket_currency' => 'USD',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ),
            ]),
        );
        $handler->push(Middleware::history($historyContainer));

        $authenticator = new IfscApiSessionAuthenticator(
            new Client([
                'handler' => HandlerStack::create(
                    new MockHandler([
                        new Response(200, [
                            'Set-Cookie' => [
                                IfscApiSessionAuthenticator::IFSC_SESSION_COOKIE_NAME . '=factory-session-token; path=/',
                            ],
                        ]),
                    ]),
                ),
            ]),
        );

        $factory = new IfscApiClientFactory($authenticator);
        $client = $factory->create(['handler' => $handler]);
        $events = $client->fetchLeagueEvents(7);

        self::assertInstanceOf(GuzzleIfscApiClient::class, $client);
        self::assertCount(1, $events);
        self::assertSame(42, $events[0]->eventId);
        self::assertSame('World Cups and World Championships', $events[0]->leagueName);
        self::assertSame('https://ifsc.results.info/events/42/infosheet', $events[0]->infosheetUrl);
        self::assertSame('https://tickets.example.com/world-cup', $events[0]->ticketUrl);
        self::assertSame('12.50', $events[0]->ticketPrice);
        self::assertSame('USD', $events[0]->ticketCurrency);
        self::assertCount(1, $historyContainer);
        self::assertStringContainsString(
            IfscApiSessionAuthenticator::IFSC_SESSION_COOKIE_NAME . '=factory-session-token',
            $historyContainer[0]['request']->getHeaderLine('cookie'),
        );
    }
}
