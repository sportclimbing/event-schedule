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
use Smalot\PdfParser\Parser as PdfParser;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Infrastructure\Schedule\Exception\InfoSheetChatGptScheduleParserException;
use Throwable;

final readonly class OpenAiInfoSheetClient
{
    private const string OPENAI_FILES_URL = 'https://api.openai.com/v1/files';
    private const string OPENAI_RESPONSES_URL = 'https://api.openai.com/v1/responses';
    private const string OPENAI_FILE_DELETE_URL = 'https://api.openai.com/v1/files/%s';
    private const string OPENAI_FILE_PURPOSE = 'user_data';
    private const string DEFAULT_MODEL = 'gpt-5-mini';
    private const int DEFAULT_HTTP_TIMEOUT_SECONDS = 120;
    private const int DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;
    private const int DEFAULT_MAX_RETRIES = 4;
    private const int DEFAULT_RETRY_BACKOFF_MILLISECONDS = 500;
    private const int MAX_RETRY_BACKOFF_MILLISECONDS = 10_000;
    private const int PDF_SIGNATURE_LENGTH = 5;
    private const int MAX_EXTRACTED_TEXT_CHARS = 120_000;

    private string $openAiApiKey;
    private string $model;
    private int $httpTimeoutSeconds;
    private int $connectTimeoutSeconds;
    private int $maxRetries;
    private int $retryBackoffMilliseconds;

    public function __construct(
        private ClientInterface $httpClient,
    ) {
        $this->openAiApiKey = $this->readEnvironmentVariable('OPENAI_API_KEY');
        $model = $this->readEnvironmentVariable('OPENAI_MODEL');
        $this->model = $model !== '' ? $model : self::DEFAULT_MODEL;
        $this->httpTimeoutSeconds = $this->readPositiveIntEnvironmentVariable(
            'OPENAI_HTTP_TIMEOUT',
            self::DEFAULT_HTTP_TIMEOUT_SECONDS,
        );
        $this->connectTimeoutSeconds = $this->readPositiveIntEnvironmentVariable(
            'OPENAI_HTTP_CONNECT_TIMEOUT',
            self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        );
        $this->maxRetries = $this->readNonNegativeIntEnvironmentVariable(
            'OPENAI_HTTP_MAX_RETRIES',
            self::DEFAULT_MAX_RETRIES,
        );
        $this->retryBackoffMilliseconds = $this->readNonNegativeIntEnvironmentVariable(
            'OPENAI_HTTP_RETRY_BACKOFF_MS',
            self::DEFAULT_RETRY_BACKOFF_MILLISECONDS,
        );
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

        $strategies = [
            [
                'name' => 'full_schema',
                'input_source' => 'file_id',
                'schema' => $this->responseSchema(),
                'strict' => false,
                'content_items' => [
                    $this->buildFileIdInputContent($fileId),
                    $this->buildPromptInputContent($this->buildPrompt($event)),
                ],
            ],
        ];

        if (is_string($pdfPath) && trim($pdfPath) !== '') {
            try {
                $strategies[] = [
                    'name' => 'rounds_only_text_fallback',
                    'input_source' => 'pdf_text',
                    'schema' => $this->roundsOnlyResponseSchema(),
                    'strict' => false,
                    'content_items' => [
                        $this->buildPromptInputContent($this->buildRoundsOnlyTextPrompt($event, $pdfPath)),
                    ],
                ];
            } catch (InfoSheetChatGptScheduleParserException) {
                // Ignore text fallback when PDF text extraction fails.
            }

            try {
                $strategies[] = [
                    'name' => 'rounds_only_pdf_data_fallback',
                    'input_source' => 'pdf_data',
                    'schema' => $this->roundsOnlyResponseSchema(),
                    'strict' => false,
                    'content_items' => [
                        $this->buildPdfDataInputContent($pdfPath),
                        $this->buildPromptInputContent($this->buildRoundsOnlyPrompt($event)),
                    ],
                ];
            } catch (InfoSheetChatGptScheduleParserException) {
                // Ignore PDF data fallback when the local file cannot be prepared.
            }
        }

        $strategies[] = [
            'name' => 'rounds_only_fallback',
            'input_source' => 'file_id',
            'schema' => $this->roundsOnlyResponseSchema(),
            'strict' => false,
            'content_items' => [
                $this->buildFileIdInputContent($fileId),
                $this->buildPromptInputContent($this->buildRoundsOnlyPrompt($event)),
            ],
        ];

        $failures = [];
        $lastException = null;

        foreach ($strategies as $index => $strategy) {
            try {
                $schedule = $this->requestSchedulePayload(
                    contentItems: $strategy['content_items'],
                    schema: $strategy['schema'],
                    strict: $strategy['strict'],
                );

                return $this->normalizeSchedulePayload($schedule);
            } catch (Throwable $exception) {
                $failures[] = $this->describeParseFailure(
                    exception: $exception,
                    fileId: $fileId,
                    strategy: $strategy['name'],
                    inputSource: $strategy['input_source'],
                );
                $lastException = $exception;

                $hasMoreStrategies = $index < (count($strategies) - 1);

                if (!$hasMoreStrategies || !$this->shouldAttemptNextParseStrategy($exception)) {
                    break;
                }
            }
        }

        throw new InfoSheetChatGptScheduleParserException(
            sprintf(
                'Unable to parse infosheet with ChatGPT after %d attempt(s). %s',
                count($failures),
                implode(' || ', $failures),
            ),
            0,
            $lastException instanceof Throwable ? $lastException : null,
        );
    }

    /** @throws InfoSheetChatGptScheduleParserException */
    public function uploadInfoSheet(string $pdfPath): string
    {
        if ($this->openAiApiKey === '') {
            throw new InfoSheetChatGptScheduleParserException(
                'Missing OPENAI_API_KEY. Set a valid OpenAI API key with available quota.'
            );
        }

        $pdfDiagnostics = $this->readPdfDiagnostics($pdfPath);

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
                                'filename' => $this->asPdfFilename($pdfPath),
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

    /** @return array<string,mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'rounds' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'starts_at' => ['type' => 'string'],
                            'ends_at' => [
                                'type' => ['string', 'null'],
                            ],
                        ],
                        'required' => ['name', 'starts_at', 'ends_at'],
                    ],
                ],
                'ticket_purchase_url' => [
                    'type' => ['string', 'null'],
                ],
                'ticket_price' => [
                    'type' => ['string', 'null'],
                ],
                'ticket_currency' => [
                    'type' => ['string', 'null'],
                ],
                'ticket_summary' => [
                    'type' => ['string', 'null'],
                ],
            ],
            'required' => ['rounds'],
        ];
    }

    /** @return array<string,mixed> */
    private function roundsOnlyResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'rounds' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'starts_at' => ['type' => 'string'],
                            'ends_at' => [
                                'type' => ['string', 'null'],
                            ],
                        ],
                        'required' => ['name', 'starts_at', 'ends_at'],
                    ],
                ],
            ],
            'required' => ['rounds'],
        ];
    }

    private function buildPrompt(EventInfo $event): string
    {
        return sprintf(
            <<<PROMPT
            Parse the attached IFSC infosheet PDF and extract the competition round schedule.

            Event context:
            - Event: %s
            - Local date range: %s to %s
            - Timezone: %s

            Output rules:
            - Return only official competition rounds (Qualification, Semi-Final, Final, etc.).
            - Exclude non-round activities (registration, technical meeting, training, practice, warm-up, isolation opening/closing, ceremony).
            - Keep round names close to the infosheet wording (but use regular single quotes (') instead of fancy quotes).
            - Every row must include starts_at.
            - Use local venue time in timezone %s.
            - Use YYYY-MM-DD HH:MM format for starts_at and ends_at.
            - Set ends_at to null when no end time is provided.
            - Also extract ticket info when available:
              - ticket_purchase_url: URL where tickets can be purchased.
              - ticket_price: numeric ticket price only (no currency symbol/code), string format.
              - ticket_currency: ticket price currency as ISO code when possible (e.g. EUR, USD, CHF), otherwise symbol.
              - ticket_summary: concise attendee-facing summary with ticket notes (for example if entry is free, where to buy tickets, notable conditions/restrictions, and any practical attendee hints).
            - If no ticket information exists, set ticket_purchase_url, ticket_price, ticket_currency, and ticket_summary to null.
            PROMPT,
            $event->eventName,
            $event->localStartDate,
            $event->localEndDate,
            $event->timeZone->getName(),
            $event->timeZone->getName(),
        );
    }

    private function buildRoundsOnlyPrompt(EventInfo $event): string
    {
        return sprintf(
            <<<PROMPT
            Parse the attached IFSC infosheet PDF and extract the competition round schedule only.

            Event context:
            - Event: %s
            - Local date range: %s to %s
            - Timezone: %s

            Output rules:
            - Return only official competition rounds (Qualification, Semi-Final, Final, etc.).
            - Exclude non-round activities (registration, technical meeting, training, practice, warm-up, isolation opening/closing, ceremony).
            - Keep round names close to the infosheet wording.
            - Every row must include starts_at.
            - Use local venue time in timezone %s.
            - Use YYYY-MM-DD HH:MM format for starts_at and ends_at.
            - Set ends_at to null when no end time is provided.
            PROMPT,
            $event->eventName,
            $event->localStartDate,
            $event->localEndDate,
            $event->timeZone->getName(),
            $event->timeZone->getName(),
        );
    }

    /**
     * Parse fallback using locally extracted PDF text, avoiding file-input handling in the API request.
     */
    private function buildRoundsOnlyTextPrompt(EventInfo $event, string $pdfPath): string
    {
        $extractedText = $this->extractPdfTextForPrompt($pdfPath);

        return sprintf(
            <<<PROMPT
            Parse the extracted text of an IFSC infosheet PDF and extract the competition round schedule only.

            Event context:
            - Event: %s
            - Local date range: %s to %s
            - Timezone: %s

            Output rules:
            - Return only official competition rounds (Qualification, Semi-Final, Final, etc.).
            - Exclude non-round activities (registration, technical meeting, training, practice, warm-up, isolation opening/closing, ceremony).
            - Keep round names close to the infosheet wording.
            - Every row must include starts_at.
            - Use local venue time in timezone %s.
            - Use YYYY-MM-DD HH:MM format for starts_at and ends_at.
            - Set ends_at to null when no end time is provided.
            - If a detail is ambiguous, choose the most conservative interpretation.

            Extracted PDF text:
            --- BEGIN PDF TEXT ---
            %s
            --- END PDF TEXT ---
            PROMPT,
            $event->eventName,
            $event->localStartDate,
            $event->localEndDate,
            $event->timeZone->getName(),
            $event->timeZone->getName(),
            $extractedText,
        );
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

    private function asPdfFilename(string $pdfPath): string
    {
        $filename = basename($pdfPath);

        if (str_ends_with(strtolower($filename), '.pdf')) {
            return $filename;
        }

        return "{$filename}.pdf";
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

    /**
     * @return array{type:string,filename:string,file_data:string}
     * @throws InfoSheetChatGptScheduleParserException
     */
    private function buildPdfDataInputContent(string $pdfPath): array
    {
        $this->readPdfDiagnostics($pdfPath);
        $pdfData = @file_get_contents($pdfPath);

        if (!is_string($pdfData) || $pdfData === '') {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Unable to read PDF content for inline fallback (%s)',
                    $this->formatContext([
                        'step' => 'parse_schedule',
                        'parse_strategy' => 'rounds_only_pdf_data_fallback',
                        'pdf_path' => $pdfPath,
                    ]),
                ),
            );
        }

        return [
            'type' => 'input_file',
            'filename' => $this->asPdfFilename($pdfPath),
            'file_data' => sprintf('data:application/pdf;base64,%s', base64_encode($pdfData)),
        ];
    }

    /**
     * @throws InfoSheetChatGptScheduleParserException
     */
    private function extractPdfTextForPrompt(string $pdfPath): string
    {
        $this->readPdfDiagnostics($pdfPath);

        try {
            $pdf = (new PdfParser())->parseFile($pdfPath);
            $text = $pdf->getText();
        } catch (Throwable $exception) {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Unable to extract text from PDF for parse fallback (%s): %s',
                    $this->formatContext([
                        'step' => 'parse_schedule',
                        'parse_strategy' => 'rounds_only_text_fallback',
                        'pdf_path' => $pdfPath,
                    ]),
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }

        $text = trim(preg_replace('/[ \t]+/', ' ', (string) $text) ?? '');

        if ($text === '') {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Extracted PDF text is empty for parse fallback (%s)',
                    $this->formatContext([
                        'step' => 'parse_schedule',
                        'parse_strategy' => 'rounds_only_text_fallback',
                        'pdf_path' => $pdfPath,
                    ]),
                ),
            );
        }

        if (strlen($text) > self::MAX_EXTRACTED_TEXT_CHARS) {
            $text = substr($text, 0, self::MAX_EXTRACTED_TEXT_CHARS);
        }

        return $text;
    }

    /**
     * @return array{
     *   pdf_path:string,
     *   pdf_exists:bool,
     *   pdf_readable:bool,
     *   pdf_size_bytes:?int,
     *   pdf_sha256:?string,
     *   pdf_signature:?string
     * }
     * @throws InfoSheetChatGptScheduleParserException
     */
    private function readPdfDiagnostics(string $pdfPath): array
    {
        $normalizedPath = trim($pdfPath);
        $exists = is_file($normalizedPath);
        $readable = $exists && is_readable($normalizedPath);
        $size = $readable ? @filesize($normalizedPath) : null;
        $hash = $readable ? @hash_file('sha256', $normalizedPath) : null;

        $signature = null;

        if ($readable) {
            $header = @file_get_contents($normalizedPath, false, null, 0, self::PDF_SIGNATURE_LENGTH);

            if (is_string($header) && $header !== '') {
                $signature = trim($header) !== '' ? $header : null;
            }
        }

        $diagnostics = [
            'pdf_path' => $normalizedPath,
            'pdf_exists' => $exists,
            'pdf_readable' => $readable,
            'pdf_size_bytes' => is_int($size) ? $size : null,
            'pdf_sha256' => is_string($hash) && trim($hash) !== '' ? $hash : null,
            'pdf_signature' => $signature,
        ];

        if (!$exists || !$readable || !is_int($size) || $size <= 0) {
            throw new InfoSheetChatGptScheduleParserException(
                sprintf(
                    'Infosheet PDF precheck failed (%s)',
                    $this->formatContext([
                        'step' => 'upload_precheck',
                        ...$diagnostics,
                    ]),
                ),
            );
        }

        return $diagnostics;
    }

    private function shouldAttemptNextParseStrategy(Throwable $exception): bool
    {
        if ($exception instanceof GuzzleException) {
            return $this->isServerSideRequestException($exception);
        }

        return $exception instanceof InfoSheetChatGptScheduleParserException;
    }

    private function isServerSideRequestException(GuzzleException $exception): bool
    {
        if (!$exception instanceof RequestException) {
            return false;
        }

        $statusCode = $exception->getResponse()?->getStatusCode();

        return $statusCode !== null && $statusCode >= 500 && $statusCode <= 599;
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
                RequestOptions::JSON => [
                    'model' => $this->model,
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
                ],
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

            $parts[] = sprintf('%s=%s', $key, (string) $value);
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
        $attempt = 0;
        $maxAttempts = max(1, $this->maxRetries + 1);

        while (true) {
            try {
                return $this->httpClient->request(
                    method: $method,
                    uri: $uri,
                    options: $options,
                );
            } catch (GuzzleException $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || !$this->isRetriableException($exception)) {
                    throw $exception;
                }

                $delayMilliseconds = $this->retryDelayMilliseconds($attempt, $exception);

                if ($delayMilliseconds > 0) {
                    usleep($delayMilliseconds * 1000);
                }
            }
        }
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

    private function readEnvironmentVariable(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private function readPositiveIntEnvironmentVariable(string $name, int $default): int
    {
        $value = $this->readEnvironmentVariable($name);

        if (!ctype_digit($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }

    private function readNonNegativeIntEnvironmentVariable(string $name, int $default): int
    {
        $value = $this->readEnvironmentVariable($name);

        if (!ctype_digit($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }
}
