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
use SportClimbing\EventDetails\Infrastructure\IFSC\Exception\IfscApiClientException;
use SportClimbing\EventDetails\Infrastructure\IFSC\GuzzleIfscApiClient;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscApiSessionAuthenticator;

final class GuzzleIfscApiClientTest extends TestCase
{
    public function testFetchLeagueEventsReturnsMappedLeagueEvents(): void
    {
        $client = new GuzzleIfscApiClient(
            httpClient: $this->buildHttpClient([
                new Response(
                    200,
                    [],
                    json_encode([
                        'events' => [
                            [
                                'event_id' => 501,
                                'event' => 'IFSC World Cup Chamonix',
                                'local_start_date' => '2026-07-12',
                                'local_end_date' => '2026-07-13',
                                'league_name' => 'World Cups and World Championships',
                                'infosheet_url' => 'https://ifsc.results.info/events/501/infosheet',
                                'ticket_purchase_url' => 'https://tickets.example.com/chamonix',
                                'ticket_price' => 35,
                                'ticket_currency' => 'EUR',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ),
            ]),
            sessionToken: 'session-token',
        );

        $events = $client->fetchLeagueEvents(12);

        self::assertCount(1, $events);
        self::assertSame(501, $events[0]->eventId);
        self::assertSame('IFSC World Cup Chamonix', $events[0]->eventName);
        self::assertSame('World Cups and World Championships', $events[0]->leagueName);
        self::assertSame('https://ifsc.results.info/events/501/infosheet', $events[0]->infosheetUrl);
        self::assertSame('https://tickets.example.com/chamonix', $events[0]->ticketUrl);
        self::assertSame('35', $events[0]->ticketPrice);
        self::assertSame('EUR', $events[0]->ticketCurrency);
    }

    public function testFetchEventDetailsReturnsMappedEventDetails(): void
    {
        $client = new GuzzleIfscApiClient(
            httpClient: $this->buildHttpClient([
                new Response(
                    200,
                    [],
                    json_encode([
                        'id' => 501,
                        'league_id' => 12,
                        'league_season_id' => 2026,
                        'location' => 'Chamonix, FRA',
                        'country' => 'FRA',
                        'timezone' => ['value' => 'Europe/Paris'],
                        'disciplines' => [
                            ['kind' => 'Lead'],
                            ['kind' => 'Speed'],
                        ],
                        'd_cats' => [
                            [
                                'category_name' => 'Men',
                                'category_rounds' => [
                                    ['name' => 'Qualification', 'kind' => 'Lead', 'category' => 'M'],
                                    ['name' => 'Final', 'kind' => 'Lead', 'category' => 'M'],
                                ],
                            ],
                            [
                                'category_name' => 'Women',
                                'category_rounds' => [],
                            ],
                            [
                                'category_name' => 'Women',
                                'category_rounds' => [],
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ),
            ]),
            sessionToken: 'session-token',
        );

        $details = $client->fetchEventDetails(501);

        self::assertSame(501, $details->id);
        self::assertSame('Europe/Paris', $details->timeZone);
        self::assertSame(['Lead', 'Speed'], $details->disciplineKinds);
        self::assertSame(['men', 'women'], $details->categories);
        self::assertSame('Chamonix, FRA', $details->location);
        self::assertSame('FRA', $details->country);
    }

    public function testFetchEventDetailsSupportsLegacyPayloadWithMissingCountryAndTimezone(): void
    {
        $client = new GuzzleIfscApiClient(
            httpClient: $this->buildHttpClient([
                new Response(
                    200,
                    [],
                    json_encode([
                        'id' => 868,
                        'name' => 'Paraclimbing Cup Laval (Boulder) 2014 - Laval (FRA) 2014',
                        'league_id' => 3,
                        'league_season_id' => 318,
                        'location' => 'Lava_Paraclimbing_14',
                        'country' => null,
                        'starts_at' => '2014-06-26 00:00:00 UTC',
                        'timezone' => null,
                        'disciplines' => [
                            ['kind' => 'Boulder'],
                        ],
                        'd_cats' => [],
                    ], JSON_THROW_ON_ERROR),
                ),
            ]),
            sessionToken: 'session-token',
        );

        $details = $client->fetchEventDetails(868);

        self::assertSame('UTC', $details->timeZone);
        self::assertSame('FRA', $details->country);
        self::assertSame(['Boulder'], $details->disciplineKinds);
        self::assertSame([], $details->categories);
    }

    public function testFetchLeagueEventsThrowsOnInvalidJson(): void
    {
        $client = new GuzzleIfscApiClient(
            httpClient: $this->buildHttpClient([
                new Response(200, [], '{"events":'),
            ]),
            sessionToken: 'session-token',
        );

        $this->expectException(IfscApiClientException::class);

        $client->fetchLeagueEvents(12);
    }

    public function testFetchLeagueEventsSendsAuthCookieAndRefererHeader(): void
    {
        $historyContainer = [];

        $client = new GuzzleIfscApiClient(
            httpClient: $this->buildHttpClient(
                responses: [
                    new Response(
                        200,
                        [],
                        json_encode([
                            'events' => [],
                        ], JSON_THROW_ON_ERROR),
                    ),
                ],
                historyContainer: $historyContainer,
            ),
            sessionToken: 'session-token-123',
        );

        $client->fetchLeagueEvents(12);

        self::assertCount(1, $historyContainer);

        $request = $historyContainer[0]['request'];
        self::assertSame(
            IfscApiSessionAuthenticator::IFSC_RESULTS_INFO_PAGE,
            $request->getHeaderLine('referer'),
        );
        self::assertStringContainsString(
            IfscApiSessionAuthenticator::IFSC_SESSION_COOKIE_NAME . '=session-token-123',
            $request->getHeaderLine('cookie'),
        );
    }

    /**
     * @param Response[] $responses
     * @param array<int, array<string, mixed>>|null $historyContainer
     */
    private function buildHttpClient(array $responses, ?array &$historyContainer = null): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));

        if ($historyContainer !== null) {
            $handler->push(Middleware::history($historyContainer));
        }

        return new Client([
            'handler' => $handler,
            'base_uri' => 'https://ifsc.results.info',
        ]);
    }
}
