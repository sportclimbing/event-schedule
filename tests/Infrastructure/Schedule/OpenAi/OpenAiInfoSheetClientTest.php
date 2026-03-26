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
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestFailedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestSucceededEvent;
use SportClimbing\EventDetails\Infrastructure\Schedule\Exception\InfoSheetChatGptScheduleParserException;
use SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi\OpenAiInfoSheetClient;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class OpenAiInfoSheetClientTest extends TestCase
{
    private ?string $originalApiKey = null;

    protected function setUp(): void
    {
        $this->originalApiKey = getenv('OPENAI_API_KEY') ?: null;
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('OPENAI_API_KEY', $this->originalApiKey);
    }

    public function testExtractSchedulePayloadRetriesOnTimeoutAndUsesConfiguredTimeouts(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');

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
        $client->applyRuntimeConfiguration(
            httpTimeoutSeconds: 60,
            connectTimeoutSeconds: 5,
            maxRetries: 1,
            retryBackoffMilliseconds: 0,
        );
        $payload = $client->extractSchedulePayload($this->eventInfo(), 'file_123');

        self::assertCount(2, $history);
        self::assertSame(60, $history[0]['options'][RequestOptions::TIMEOUT]);
        self::assertSame(5, $history[0]['options'][RequestOptions::CONNECT_TIMEOUT]);
        $requestPayload = json_decode(
            (string) $history[0]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($requestPayload);
        self::assertArrayNotHasKey('temperature', $requestPayload);
        self::assertEquals(1.0, $requestPayload['top_p'] ?? null);
        self::assertCount(1, $payload['rounds']);
        self::assertSame('Final', $payload['rounds'][0]['name']);
        self::assertSame('https://tickets.example.com/ifsc-event', $payload['ticket_purchase_url']);
        self::assertSame('35.00', $payload['ticket_price']);
        self::assertSame('EUR', $payload['ticket_currency']);
    }

    public function testExtractSchedulePayloadUsesConfiguredSamplingSettings(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');

        $history = [];
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
        $handler->push(Middleware::history($history));

        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]));
        $client->applyRuntimeConfiguration(temperature: 0.25, topP: 0.85);
        $client->extractSchedulePayload($this->eventInfo(), 'file_123');

        self::assertCount(1, $history);
        $requestPayload = json_decode(
            (string) $history[0]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($requestPayload);
        self::assertEquals(0.25, $requestPayload['temperature'] ?? null);
        self::assertEquals(0.85, $requestPayload['top_p'] ?? null);
    }

    public function testExtractSchedulePayloadFailsWhenSamplingParametersAreUnsupportedByModel(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');

        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(
                400,
                ['x-request-id' => 'req_sampling_unsupported'],
                (string) json_encode([
                    'error' => [
                        'message' => "Unsupported parameter: 'temperature' is not supported with this model.",
                    ],
                ], JSON_THROW_ON_ERROR),
            ),
        ]));
        $handler->push(Middleware::history($history));

        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]));
        $client->applyRuntimeConfiguration(temperature: 0.2);

        try {
            $client->extractSchedulePayload($this->eventInfo(), 'file_123');
            self::fail('Expected extractSchedulePayload to fail for unsupported sampling parameters.');
        } catch (InfoSheetChatGptScheduleParserException $exception) {
            self::assertStringContainsString('after 1 attempt(s)', $exception->getMessage());
            self::assertStringContainsString("Unsupported parameter: 'temperature'", $exception->getMessage());
        }

        self::assertCount(1, $history);
        $requestPayload = json_decode(
            (string) $history[0]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($requestPayload);
        self::assertArrayHasKey('temperature', $requestPayload);
        self::assertArrayHasKey('top_p', $requestPayload);
    }

    public function testExtractSchedulePayloadDispatchesOpenAiSuccessEvent(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');
        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            OpenAiApiRequestSucceededEvent::class,
            static function (OpenAiApiRequestSucceededEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

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
        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]), $dispatcher);

        $client->extractSchedulePayload($this->eventInfo(), 'file_123');

        self::assertCount(1, $events);
        self::assertSame('POST', $events[0]->method);
        self::assertSame('https://api.openai.com/v1/responses', $events[0]->uri);
        self::assertSame(1, $events[0]->attempt);
        self::assertSame(200, $events[0]->statusCode);
    }

    public function testExtractSchedulePayloadDispatchesOpenAiFailureEvent(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');
        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            OpenAiApiRequestFailedEvent::class,
            static function (OpenAiApiRequestFailedEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $handler = HandlerStack::create(new MockHandler([
            new Response(500, ['x-request-id' => 'req_1'], (string) json_encode(['error' => ['message' => 'boom']], JSON_THROW_ON_ERROR)),
            new Response(500, ['x-request-id' => 'req_2'], (string) json_encode(['error' => ['message' => 'boom']], JSON_THROW_ON_ERROR)),
        ]));
        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]), $dispatcher);
        $client->applyRuntimeConfiguration(maxRetries: 0);

        try {
            $client->extractSchedulePayload($this->eventInfo(), 'file_123');
            self::fail('Expected extractSchedulePayload to throw on HTTP 500.');
        } catch (InfoSheetChatGptScheduleParserException) {
            self::assertCount(1, $events);
            self::assertSame('POST', $events[0]->method);
            self::assertSame('https://api.openai.com/v1/responses', $events[0]->uri);
            self::assertSame(500, $events[0]->statusCode);
            self::assertFalse($events[0]->willRetry);
        }
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
        $client->applyRuntimeConfiguration(maxRetries: 0, retryBackoffMilliseconds: 0);

        try {
            $client->extractSchedulePayload($this->eventInfo(), 'file_123');
            self::fail('Expected extractSchedulePayload to throw on HTTP 500.');
        } catch (InfoSheetChatGptScheduleParserException $exception) {
            self::assertStringContainsString('after 1 attempt(s)', $exception->getMessage());
            self::assertStringContainsString('step=parse_schedule', $exception->getMessage());
            self::assertStringContainsString('file_id=file_123', $exception->getMessage());
            self::assertStringContainsString('parse_strategy=full_schema', $exception->getMessage());
            self::assertStringContainsString('input_source=file_id', $exception->getMessage());
            self::assertStringNotContainsString('parse_strategy=rounds_only_fallback', $exception->getMessage());
            self::assertStringContainsString('request_id=req_test_123', $exception->getMessage());
            self::assertStringNotContainsString('request_id=req_test_456', $exception->getMessage());
        }
    }

    public function testExtractSchedulePayloadStopsAfterFirstStrategyFailure(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');

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
        $client->applyRuntimeConfiguration(maxRetries: 0, retryBackoffMilliseconds: 0);

        try {
            $client->extractSchedulePayload($this->eventInfo(), 'file_123');
            self::fail('Expected extractSchedulePayload to stop after first strategy failure.');
        } catch (InfoSheetChatGptScheduleParserException $exception) {
            self::assertStringContainsString('after 1 attempt(s)', $exception->getMessage());
            self::assertStringContainsString('parse_strategy=full_schema', $exception->getMessage());
            self::assertStringNotContainsString('parse_strategy=rounds_only_fallback', $exception->getMessage());
            self::assertStringContainsString('request_id=req_test_primary', $exception->getMessage());
        }
    }

    public function testExtractSchedulePayloadWithPdfPathUsesPdfDataInput(): void
    {
        $this->setEnv('OPENAI_API_KEY', 'test-key');

        $history = [];
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
        $handler->push(Middleware::history($history));

        $pdfPath = tempnam(sys_get_temp_dir(), 'ifsc-test-');
        self::assertNotFalse($pdfPath);

        self::assertNotFalse(file_put_contents($pdfPath, "%PDF-1.4\n%test"));

        $client = new OpenAiInfoSheetClient(new Client(['handler' => $handler]));
        $client->applyRuntimeConfiguration(maxRetries: 0, retryBackoffMilliseconds: 0);

        try {
            $client->extractSchedulePayload($this->eventInfo(), 'file_123', $pdfPath);
            self::assertCount(1, $history);

            $requestPayload = json_decode(
                (string) $history[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($requestPayload);
            self::assertIsArray($requestPayload['input'] ?? null);
            self::assertIsArray($requestPayload['input'][0]['content'] ?? null);
            self::assertIsArray($requestPayload['input'][0]['content'][0] ?? null);
            self::assertSame('input_file', $requestPayload['input'][0]['content'][0]['type'] ?? null);
            self::assertArrayHasKey('file_data', $requestPayload['input'][0]['content'][0]);
            self::assertStringStartsWith(
                'data:application/pdf;base64,',
                (string) $requestPayload['input'][0]['content'][0]['file_data'],
            );
            self::assertArrayNotHasKey('file_id', $requestPayload['input'][0]['content'][0]);
        } finally {
            @unlink($pdfPath);
        }
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
            categories: ['men', 'women'],
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
