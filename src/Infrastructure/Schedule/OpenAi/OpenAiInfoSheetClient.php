<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestFailedEvent;
use SportClimbing\EventDetails\Infrastructure\Observability\Event\OpenAiApiRequestSucceededEvent;
use SportClimbing\EventDetails\Infrastructure\Schedule\Exception\InfoSheetChatGptScheduleParserException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

final class OpenAiInfoSheetClient
{
    private const string OPENAI_FILES_URL = 'https://api.openai.com/v1/files';
    private const string OPENAI_RESPONSES_URL = 'https://api.openai.com/v1/responses';
    private const string OPENAI_FILE_DELETE_URL = 'https://api.openai.com/v1/files/%s';
    private const string OPENAI_FILE_PURPOSE = 'user_data';
    private const string DEFAULT_MODEL = 'gpt-5-mini';
    private const float DEFAULT_TEMPERATURE = 0.0;
    private const float DEFAULT_TOP_P = 1.0;
    private const int DEFAULT_HTTP_TIMEOUT_SECONDS = 120;
    private const int DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;
    private const int DEFAULT_MAX_RETRIES = 4;
    private const int DEFAULT_RETRY_BACKOFF_MILLISECONDS = 500;
    private const int MAX_RETRY_BACKOFF_MILLISECONDS = 10_000;

    private string $openAiApiKey;
    private string $model;
    private float $temperature;
    private bool $temperatureConfigured;
    private float $topP;
    private int $httpTimeoutSeconds;
    private int $connectTimeoutSeconds;
    private int $maxRetries;
    private int $retryBackoffMilliseconds;
    private EventDispatcherInterface $eventDispatcher;
    private OpenAiInfoSheetPromptBuilder $promptBuilder;
    private OpenAiInfoSheetResponseSchemaFactory $responseSchemaFactory;
    private OpenAiInfoSheetPdfContentBuilder $pdfContentBuilder;

    public function __construct(
        private readonly ClientInterface $httpClient,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?OpenAiInfoSheetPromptBuilder $promptBuilder = null,
        ?OpenAiInfoSheetResponseSchemaFactory $responseSchemaFactory = null,
        ?OpenAiInfoSheetPdfContentBuilder $pdfContentBuilder = null,
    ) {
        $this->eventDispatcher = $eventDispatcher ?? new EventDispatcher();
        $this->promptBuilder = $promptBuilder ?? new OpenAiInfoSheetPromptBuilder();
        $this->responseSchemaFactory = $responseSchemaFactory ?? new OpenAiInfoSheetResponseSchemaFactory();
        $this->pdfContentBuilder = $pdfContentBuilder ?? new OpenAiInfoSheetPdfContentBuilder();
        $this->openAiApiKey = $this->readEnvironmentVariable('OPENAI_API_KEY');
        $this->model = self::DEFAULT_MODEL;
        $this->temperature = self::DEFAULT_TEMPERATURE;
        $this->temperatureConfigured = false;
        $this->topP = self::DEFAULT_TOP_P;
        $this->httpTimeoutSeconds = self::DEFAULT_HTTP_TIMEOUT_SECONDS;
        $this->connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS;
        $this->maxRetries = self::DEFAULT_MAX_RETRIES;
        $this->retryBackoffMilliseconds = self::DEFAULT_RETRY_BACKOFF_MILLISECONDS;
    }

    public function applyRuntimeConfiguration(
        ?string $model = null,
        ?float $temperature = null,
        ?float $topP = null,
        ?int $httpTimeoutSeconds = null,
        ?int $connectTimeoutSeconds = null,
        ?int $maxRetries = null,
        ?int $retryBackoffMilliseconds = null,
    ): void {
        if (is_string($model) && trim($model) !== '') {
            $this->model = trim($model);
        }

        if (is_float($temperature)) {
            $this->temperature = $this->clampFloat((float) $temperature, 0.0, 2.0);
            $this->temperatureConfigured = true;
        }

        if (is_float($topP)) {
            $this->topP = $this->clampFloat((float) $topP, 0.0, 1.0);
        }

        if (is_int($httpTimeoutSeconds)) {
            $this->httpTimeoutSeconds = max(1, $httpTimeoutSeconds);
        }

        if (is_int($connectTimeoutSeconds)) {
            $this->connectTimeoutSeconds = max(1, $connectTimeoutSeconds);
        }

        if (is_int($maxRetries)) {
            $this->maxRetries = max(0, $maxRetries);
        }

        if (is_int($retryBackoffMilliseconds)) {
            $this->retryBackoffMilliseconds = max(0, $retryBackoffMilliseconds);
        }
    }

    /**
     * @return array{
     *   rounds: array<array{name:mixed,starts_at:mixed,ends_at:mixed}>,
     *   ticket_purchase_url: ?string,
     *   ticket_price: ?string,
     *   ticket_currency: ?string,
     *   ticket_summary: ?string
     * }
     */
    public function extractSchedulePayload(EventInfo $event, string $fileId, ?string $pdfPath = null): array
    {
        if ($this->openAiApiKey === '') {
            throw new InfoSheetChatGptScheduleParserException(
                'Missing OPENAI_API_KEY. Set a valid OpenAI API key with available quota.'
            );
        }

        $contentInputItem = $this->buildFileIdInputContent($fileId);
        $inputSource = 'file_id';
        $normalizedPdfPath = is_string($pdfPath) ? trim($pdfPath) : '';

        if ($normalizedPdfPath !== '' && is_file($normalizedPdfPath)) {
            $contentInputItem = $this->pdfContentBuilder->buildPdfDataInputContent($normalizedPdfPath);
            $inputSource = 'pdf_data';
        }

        try {
            $schedule = $this->requestSchedulePayload(
                contentItems: [
                    $contentInputItem,
                    $this->buildPromptInputContent($this->promptBuilder->buildSchedulePrompt($event)),
                ],
                schema: $this->responseSchemaFactory->buildScheduleSchema(),
                strict: false,
            );

            return $this->normalizeSchedulePayload($schedule);
        } catch (Throwable $exception) {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Unable to parse infosheet with ChatGPT after 1 attempt(s). %s',
                    $this->describeParseFailure(
                        exception: $exception,
                        fileId: $fileId,
                        strategy: 'full_schema',
                        inputSource: $inputSource,
                    ),
                ),
                0,
                $exception,
            );
        }
    }

    /** @throws InfoSheetChatGptScheduleParserException */
    public function uploadInfoSheet(string $pdfPath): string
    {
        if ($this->openAiApiKey === '') {
            throw new InfoSheetChatGptScheduleParserException(
                'Missing OPENAI_API_KEY. Set a valid OpenAI API key with available quota.'
            );
        }

        $pdfDiagnostics = $this->pdfContentBuilder->readPdfDiagnostics($pdfPath);

        $stream = fopen($pdfPath, 'rb');

        if ($stream === false) {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Unable to open infosheet PDF (%s)',
                    $this->formatContext([
                        'step' => 'upload_precheck',
                        ...$pdfDiagnostics,
                    ]),
                ),
            );
        }

        try {
            try {
                $response = $this->requestWithRetry(
                    method: 'POST',
                    uri: self::OPENAI_FILES_URL,
                    options: [
                        RequestOptions::HEADERS => $this->authHeaders(),
                        RequestOptions::MULTIPART => [
                            [
                                'name' => 'purpose',
                                'contents' => self::OPENAI_FILE_PURPOSE,
                            ],
                            [
                                'name' => 'file',
                                'contents' => $stream,
                                'filename' => $this->pdfContentBuilder->asPdfFilename($pdfPath),
                                'headers' => ['Content-Type' => 'application/pdf'],
                            ],
                        ],
                    ],
                );
            } catch (GuzzleException $exception) {
                throw new InfoSheetChatGptScheduleParserException(
                    $this->buildOpenAiExceptionMessage(
                        operation: 'Unable to upload infosheet PDF',
                        exception: $exception,
                        context: [
                            'step' => 'upload',
                            ...$pdfDiagnostics,
                            'max_attempts' => $this->maxRetries + 1,
                        ],
                    ),
                    0,
                    $exception,
                );
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $payload = $this->decodeJson((string) $response->getBody());
        $fileId = $payload['id'] ?? null;

        if (!is_string($fileId) || trim($fileId) === '') {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'OpenAI did not return a file id (%s)',
                    $this->formatContext([
                        'step' => 'upload',
                        ...$pdfDiagnostics,
                    ]),
                ),
            );
        }

        return $fileId;
    }

    public function deleteUploadedFile(string $fileId): void
    {
        if ($this->openAiApiKey === '') {
            return;
        }

        try {
            $this->requestWithRetry(
                method: 'DELETE',
                uri: sprintf(self::OPENAI_FILE_DELETE_URL, $fileId),
                options: [
                    RequestOptions::HEADERS => $this->authHeaders(),
                ],
            );
        } catch (GuzzleException) {
        }
    }

    /** @return array<string,string> */
    private function authHeaders(): array
    {
        return [
            'Authorization' => "Bearer {$this->openAiApiKey}",
        ];
    }

    /** @return array<string,string> */
    private function jsonHeaders(): array
    {
        return [
            ...$this->authHeaders(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * @return array<string,mixed>
     * @throws InfoSheetChatGptScheduleParserException
     */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf('Unable to parse ChatGPT JSON response: %s', $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new InfoSheetChatGptScheduleParserException('ChatGPT response is not a JSON object');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $response */
    private function extractOutputText(array $response): ?string
    {
        $output = $response['output'] ?? null;

        if (!is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = $item['content'] ?? null;

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $text = $contentItem['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>|null
     */
    private function extractOutputJson(array $response): ?array
    {
        $output = $response['output'] ?? null;

        if (!is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = $item['content'] ?? null;

            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $json = $contentItem['json'] ?? null;

                if (is_array($json)) {
                    return $json;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function buildOpenAiExceptionMessage(string $operation, GuzzleException $exception, array $context = []): string
    {
        $context = $this->normalizeContext($context);

        if (!$exception instanceof RequestException) {
            $message = "{$operation}: {$exception->getMessage()}";

            if ($context !== []) {
                $message .= sprintf(' (%s)', $this->formatContext($context));
            }

            return $message;
        }

        $statusCode = $exception->getResponse()?->getStatusCode();
        $apiError = $this->extractOpenAiErrorMessage($exception);
        $requestId = $this->extractOpenAiRequestId($exception);

        if ($statusCode === 429 && $this->isQuotaError($apiError)) {
            $message = "{$operation}: OpenAI quota exceeded (HTTP 429). Check billing/project quota or use an API key with available quota.";
        } elseif ($statusCode !== null && $apiError !== null) {
            $message = "{$operation}: HTTP {$statusCode} - {$apiError}";
        } elseif ($statusCode !== null) {
            $message = "{$operation}: HTTP {$statusCode} - {$exception->getMessage()}";
        } else {
            $message = "{$operation}: {$exception->getMessage()}";
        }

        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        if ($context !== []) {
            $message .= sprintf(' (%s)', $this->formatContext($context));
        }

        return $message;
    }

    private function extractOpenAiErrorMessage(RequestException $exception): ?string
    {
        $response = $exception->getResponse();

        if ($response === null) {
            return null;
        }

        try {
            $payload = $this->decodeJson((string) $response->getBody());
        } catch (InfoSheetChatGptScheduleParserException) {
            return null;
        }

        $error = $payload['error'] ?? null;

        if (!is_array($error)) {
            return null;
        }

        $message = $error['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    private function extractOpenAiRequestId(RequestException $exception): ?string
    {
        $response = $exception->getResponse();

        if ($response !== null) {
            foreach (['x-request-id', 'request-id'] as $headerName) {
                $value = trim($response->getHeaderLine($headerName));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        $message = $exception->getMessage();

        if (preg_match('/\breq_[a-zA-Z0-9]+\b/', $message, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function isQuotaError(?string $message): bool
    {
        if ($message === null) {
            return false;
        }

        $message = strtolower($message);

        return str_contains($message, 'insufficient_quota')
            || str_contains($message, 'exceeded your current quota')
            || str_contains($message, 'billing');
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeTicketUrl(mixed $value): ?string
    {
        $url = $this->normalizeOptionalString($value);

        if ($url === null) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /**
     * @param array<string,mixed> $schedule
     * @return array{
     *   rounds: array<array{name:mixed,starts_at:mixed,ends_at:mixed}>,
     *   ticket_purchase_url: ?string,
     *   ticket_price: ?string,
     *   ticket_currency: ?string,
     *   ticket_summary: ?string
     * }
     * @throws InfoSheetChatGptScheduleParserException
     */
    private function normalizeSchedulePayload(array $schedule): array
    {
        $rounds = $schedule['rounds'] ?? null;

        if (!is_array($rounds)) {
            throw new InfoSheetChatGptScheduleParserException('ChatGPT schedule payload is missing rounds');
        }

        /** @var array<array{name:mixed,starts_at:mixed,ends_at:mixed}> $rounds */
        return [
            'rounds' => $rounds,
            'ticket_purchase_url' => $this->normalizeTicketUrl($schedule['ticket_purchase_url'] ?? null),
            'ticket_price' => $this->normalizeOptionalString($schedule['ticket_price'] ?? null),
            'ticket_currency' => $this->normalizeOptionalString($schedule['ticket_currency'] ?? null),
            'ticket_summary' => $this->normalizeOptionalString($schedule['ticket_summary'] ?? null),
        ];
    }

    /** @return array{type:string,text:string} */
    private function buildPromptInputContent(string $prompt): array
    {
        return [
            'type' => 'input_text',
            'text' => $prompt,
        ];
    }

    /** @return array{type:string,file_id:string} */
    private function buildFileIdInputContent(string $fileId): array
    {
        return [
            'type' => 'input_file',
            'file_id' => $fileId,
        ];
    }

    private function describeParseFailure(
        Throwable $exception,
        string $fileId,
        string $strategy,
        string $inputSource,
    ): string
    {
        $context = [
            'step' => 'parse_schedule',
            'file_id' => $fileId,
            'model' => $this->model,
            'max_attempts' => $this->maxRetries + 1,
            'parse_strategy' => $strategy,
            'input_source' => $inputSource,
        ];

        if ($exception instanceof GuzzleException) {
            return $this->buildOpenAiExceptionMessage(
                operation: 'Unable to parse infosheet with ChatGPT',
                exception: $exception,
                context: $context,
            );
        }

        return sprintf(
            'Unable to parse infosheet with ChatGPT: %s (%s)',
            $exception->getMessage(),
            $this->formatContext($context),
        );
    }

    /**
     * @param array<array<string,mixed>> $contentItems
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     * @throws GuzzleException
     * @throws InfoSheetChatGptScheduleParserException
     */
    private function requestSchedulePayload(
        array $contentItems,
        array $schema,
        bool $strict,
    ): array {
        $response = $this->requestWithRetry(
            method: 'POST',
            uri: self::OPENAI_RESPONSES_URL,
            options: [
                RequestOptions::HEADERS => $this->jsonHeaders(),
                RequestOptions::JSON => $this->buildResponseRequestPayload(
                    contentItems: $contentItems,
                    schema: $schema,
                    strict: $strict,
                ),
            ],
        );

        $payload = $this->decodeJson((string) $response->getBody());
        $schedule = $this->extractOutputJson($payload);

        if ($schedule === null) {
            $content = $payload['output_text'] ?? $this->extractOutputText($payload);

            if (!is_string($content) || trim($content) === '') {
                throw new InfoSheetChatGptScheduleParserException('ChatGPT returned an empty schedule response');
            }

            $schedule = $this->decodeJson($content);
        }

        return $schedule;
    }

    /**
     * @param array<array<string,mixed>> $contentItems
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private function buildResponseRequestPayload(
        array $contentItems,
        array $schema,
        bool $strict,
    ): array {
        $payload = [
            'model' => $this->model,
            'top_p' => $this->topP,
            'input' => [[
                'role' => 'user',
                'content' => $contentItems,
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'ifsc_infosheet_schedule',
                    'schema' => $schema,
                    'strict' => $strict,
                ],
            ],
        ];

        if ($this->temperatureConfigured) {
            $payload['temperature'] = $this->temperature;
        }

        return $payload;
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

            $parts[] = sprintf('%s=%s', $key, $value);
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $options
     * @throws GuzzleException
     */
    private function requestWithRetry(string $method, string $uri, array $options): ResponseInterface
    {
        $options = $this->withTimeoutOptions($options);
        $maxAttempts = max(1, $this->maxRetries + 1);

        for ($attempt = 1; ; $attempt++) {
            $startedAt = microtime(true);

            try {
                $response = $this->httpClient->request(
                    method: $method,
                    uri: $uri,
                    options: $options,
                );

                $this->dispatchEvent(new OpenAiApiRequestSucceededEvent(
                    method: $method,
                    uri: $uri,
                    attempt: $attempt,
                    maxAttempts: $maxAttempts,
                    statusCode: $response->getStatusCode(),
                    durationMilliseconds: $this->elapsedMilliseconds($startedAt),
                ));

                return $response;
            } catch (GuzzleException $exception) {
                $willRetry = $attempt < $maxAttempts && $this->isRetriableException($exception);

                $this->dispatchEvent(new OpenAiApiRequestFailedEvent(
                    method: $method,
                    uri: $uri,
                    attempt: $attempt,
                    maxAttempts: $maxAttempts,
                    statusCode: $exception instanceof RequestException ? $exception->getResponse()?->getStatusCode() : null,
                    durationMilliseconds: $this->elapsedMilliseconds($startedAt),
                    willRetry: $willRetry,
                    reason: $exception->getMessage(),
                ));

                if (!$willRetry) {
                    throw $exception;
                }

                $delayMilliseconds = $this->retryDelayMilliseconds($attempt, $exception);

                if ($delayMilliseconds > 0) {
                    usleep($delayMilliseconds * 1000);
                }
            }
        }
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function withTimeoutOptions(array $options): array
    {
        if (!array_key_exists(RequestOptions::TIMEOUT, $options)) {
            $options[RequestOptions::TIMEOUT] = $this->httpTimeoutSeconds;
        }

        if (!array_key_exists(RequestOptions::CONNECT_TIMEOUT, $options)) {
            $options[RequestOptions::CONNECT_TIMEOUT] = $this->connectTimeoutSeconds;
        }

        return $options;
    }

    private function isRetriableException(GuzzleException $exception): bool
    {
        if ($exception instanceof ConnectException) {
            return true;
        }

        if (!$exception instanceof RequestException) {
            return false;
        }

        $statusCode = $exception->getResponse()?->getStatusCode();

        if ($statusCode === 429 || ($statusCode !== null && $statusCode >= 500 && $statusCode <= 599)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'curl error 28')
            || str_contains($message, 'operation timed out')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout');
    }

    private function retryDelayMilliseconds(int $attempt, GuzzleException $exception): int
    {
        $retryAfter = $this->retryAfterHeaderMilliseconds($exception);

        if ($retryAfter !== null) {
            return min(self::MAX_RETRY_BACKOFF_MILLISECONDS, $retryAfter);
        }

        if ($this->retryBackoffMilliseconds <= 0) {
            return 0;
        }

        $multiplier = 2 ** max(0, $attempt - 1);
        $baseDelay = $this->retryBackoffMilliseconds * $multiplier;
        $jitter = random_int(0, max(1, (int) floor($this->retryBackoffMilliseconds / 2)));

        return min(self::MAX_RETRY_BACKOFF_MILLISECONDS, $baseDelay + $jitter);
    }

    private function retryAfterHeaderMilliseconds(GuzzleException $exception): ?int
    {
        if (!$exception instanceof RequestException || $exception->getResponse() === null) {
            return null;
        }

        $retryAfter = trim($exception->getResponse()->getHeaderLine('Retry-After'));

        if ($retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter * 1000;
        }

        $retryAtTimestamp = strtotime($retryAfter);

        if ($retryAtTimestamp === false) {
            return null;
        }

        $seconds = max(0, $retryAtTimestamp - time());

        return $seconds * 1000;
    }

    private function dispatchEvent(object $event): void
    {
        try {
            $this->eventDispatcher->dispatch($event);
        } catch (Throwable) {
        }
    }

    private function readEnvironmentVariable(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private function clampFloat(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) {
            return $min;
        }

        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}
