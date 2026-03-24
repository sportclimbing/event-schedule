<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\Schedule\OpenAi;

use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Infrastructure\Schedule\Exception\InfoSheetChatGptScheduleParserException;
use SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi\OpenAiInfoSheetClient;

final class OpenAiInfoSheetClientTest extends TestCase
{
    private ?string $originalApiKey = null;
    private ?string $originalHttpTimeout = null;
    private ?string $originalConnectTimeout = null;
    private ?string $originalMaxRetries = null;
    private ?string $originalRetryBackoffMs = null;

    protected function setUp(): void
    {
        $this->originalApiKey = getenv('OPENAI_API_KEY') ?: null;
        $this->originalHttpTimeout = getenv('OPENAI_HTTP_TIMEOUT') ?: null;
        $this->originalConnectTimeout = getenv('OPENAI_HTTP_CONNECT_TIMEOUT') ?: null;
        $this->originalMaxRetries = getenv('OPENAI_HTTP_MAX_RETRIES') ?: null;
        $this->originalRetryBackoffMs = getenv('OPENAI_HTTP_RETRY_BACKOFF_MS') ?: null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('OPENAI_API_KEY', $this->originalApiKey);
        $this->restoreEnv('OPENAI_HTTP_TIMEOUT', $this->originalHttpTimeout);
        $this->restoreEnv('OPENAI_HTTP_CONNECT_TIMEOUT', $this->originalConnectTimeout);
        $this->restoreEnv('OPENAI_HTTP_MAX_RETRIES', $this->originalMaxRetries);
        $this->restoreEnv('OPENAI_HTTP_RETRY_BACKOFF_MS', $this->originalRetryBackoffMs);
    }

    public function testExtractSchedulePayloadRetriesOnTimeoutAndUsesConfiguredTimeouts(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');
        $this->setEnv('OPENAI_HTTP_TIMEOUT', '60');
        $this->setEnv('OPENAI_HTTP_CONNECT_TIMEOUT', '5');
        $this->setEnv('OPENAI_HTTP_MAX_RETRIES', '1');
        $this->setEnv('OPENAI_HTTP_RETRY_BACKOFF_MS', '0');

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new ConnectException(
                'cURL error 28: Operation timed out after 60000 milliseconds',
                new Request('POST', 'https://api.openai.com/v1/responses'),
            ),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                (string) json_encode([
                    'output' => [[
                        'content' => [[
                            'json' => [
                                'rounds' => [[
                                    'name' => 'Final',
                                    'starts_at' => '2026-04-01 19:00',
                                    'ends_at' => null,
                                ]],
                                'ticket_purchase_url' => 'https://tickets.example.com/ifsc-event',
                                'ticket_price' => '35.00',
                                'ticket_currency' => 'EUR',
                            ],
                        ]],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ),
        ]));
        $handler->push(Middleware::history($history));

        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]));
        $payload = $client->extractSchedulePayload($this->eventInfo(), 'file_123');

        self::assertCount(2, $history);
        self::assertSame(60, $history[0]['options'][RequestOptions::TIMEOUT]);
        self::assertSame(5, $history[0]['options'][RequestOptions::CONNECT_TIMEOUT]);
        self::assertCount(1, $payload['rounds']);
        self::assertSame('Final', $payload['rounds'][0]['name']);
        self::assertSame('https://tickets.example.com/ifsc-event', $payload['ticket_purchase_url']);
        self::assertSame('35.00', $payload['ticket_price']);
        self::assertSame('EUR', $payload['ticket_currency']);
    }

    public function testExtractSchedulePayloadReturnsNullTicketInfoWhenMissing(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');

        $handler = HandlerStack::create(new MockHandler([
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                (string) json_encode([
                    'output' => [[
                        'content' => [[
                            'json' => [
                                'rounds' => [[
                                    'name' => 'Final',
                                    'starts_at' => '2026-04-01 19:00',
                                    'ends_at' => null,
                                ]],
                            ],
                        ]],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ),
        ]));

        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]));
        $payload = $client->extractSchedulePayload($this->eventInfo(), 'file_123');

        self::assertCount(1, $payload['rounds']);
        self::assertNull($payload['ticket_purchase_url']);
        self::assertNull($payload['ticket_price']);
        self::assertNull($payload['ticket_currency']);
    }

    public function testUploadInfoSheetReportsPrecheckFailureForMissingPdf(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');
        $client = new OpenAiInfoSheetClient(new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $missingPath = sprintf('%s/missing-infosheet-%s.pdf', sys_get_temp_dir(), uniqid('', true));

        try {
            $client->uploadInfoSheet($missingPath);
            self::fail('Expected uploadInfoSheet to throw an exception for missing file.');
        } catch (InfoSheetChatGptScheduleParserException $exception) {
            self::assertStringContainsString('upload_precheck', $exception->getMessage());
            self::assertStringContainsString($missingPath, $exception->getMessage());
            self::assertStringContainsString('pdf_exists=false', $exception->getMessage());
        }
    }

    public function testExtractSchedulePayloadIncludesRequestIdAndStepOnServerError(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');
        $this->setEnv('OPENAI_HTTP_MAX_RETRIES', '0');
        $this->setEnv('OPENAI_HTTP_RETRY_BACKOFF_MS', '0');

        $handler = HandlerStack::create(new MockHandler([
            new Response(
                500,
                ['x-request-id' => 'req_test_123'],
                (string) json_encode([
                    'error' => [
                        'message' => 'The server had an error processing your request.',
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
            new Response(
                500,
                ['x-request-id' => 'req_test_456'],
                (string) json_encode([
                    'error' => [
                        'message' => 'The server had an error processing your request.',
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
        ]));

        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]));

        try {
            $client->extractSchedulePayload($this->eventInfo(), 'file_123');
            self::fail('Expected extractSchedulePayload to throw on HTTP 500.');
        } catch (InfoSheetChatGptScheduleParserException $exception) {
            self::assertStringContainsString('after 2 attempt(s)', $exception->getMessage());
            self::assertStringContainsString('step=parse_schedule', $exception->getMessage());
            self::assertStringContainsString('file_id=file_123', $exception->getMessage());
            self::assertStringContainsString('parse_strategy=full_schema', $exception->getMessage());
            self::assertStringContainsString('parse_strategy=rounds_only_fallback', $exception->getMessage());
            self::assertStringContainsString('request_id=req_test_123', $exception->getMessage());
            self::assertStringContainsString('request_id=req_test_456', $exception->getMessage());
        }
    }

    public function testExtractSchedulePayloadFallsBackToRoundsOnlyAfterServerError(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');
        $this->setEnv('OPENAI_HTTP_MAX_RETRIES', '0');
        $this->setEnv('OPENAI_HTTP_RETRY_BACKOFF_MS', '0');

        $handler = HandlerStack::create(new MockHandler([
            new Response(
                500,
                ['x-request-id' => 'req_test_primary'],
                (string) json_encode([
                    'error' => [
                        'message' => 'The server had an error processing your request.',
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                (string) json_encode([
                    'output' => [[
                        'content' => [[
                            'json' => [
                                'rounds' => [[
                                    'name' => 'Final',
                                    'starts_at' => '2026-04-01 19:00',
                                    'ends_at' => null,
                                ]],
                            ],
                        ]],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ),
        ]));

        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]));
        $payload = $client->extractSchedulePayload($this->eventInfo(), 'file_123');

        self::assertCount(1, $payload['rounds']);
        self::assertSame('Final', $payload['rounds'][0]['name']);
        self::assertNull($payload['ticket_purchase_url']);
        self::assertNull($payload['ticket_price']);
        self::assertNull($payload['ticket_currency']);
    }

    private function eventInfo(): EventInfo
    {
        return new EventInfo(
            eventId: 1,
            eventName: 'IFSC World Cup',
            leagueId: 457,
            leagueName: 'World Cups',
            leagueSeasonId: 2026,
            localStartDate: '2026-04-01',
            localEndDate: '2026-04-02',
            timeZone: new DateTimeZone('UTC'),
            location: 'Innsbruck',
            country: 'AUT',
            disciplines: ['lead'],
            infosheetUrl: null,
        );
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
}
