<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\Schedule;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use SportClimbing\EventDetails\Domain\Event\Entity\EventInfo;
use SportClimbing\EventDetails\Domain\Schedule\InfoSheetParseResult;
use SportClimbing\EventDetails\Domain\Schedule\InfoSheetTicketInfo;
use SportClimbing\EventDetails\Domain\Schedule\IfscSchedule;
use SportClimbing\EventDetails\Domain\Schedule\IfscScheduleFactory;
use SportClimbing\EventDetails\Domain\Schedule\Port\InfoSheetScheduleParserInterface;
use SportClimbing\EventDetails\Infrastructure\Schedule\Cache\InfoSheetScheduleCache;
use SportClimbing\EventDetails\Infrastructure\Schedule\Exception\InfoSheetChatGptScheduleParserException;
use SportClimbing\EventDetails\Infrastructure\Schedule\OpenAi\OpenAiInfoSheetClient;

final readonly class InfoSheetChatGptScheduleParser implements InfoSheetScheduleParserInterface
{
    private const string DATE_TIME_FORMAT = 'Y-m-d H:i';

    public function __construct(
        private OpenAiInfoSheetClient $openAiInfoSheetClient,
        private InfoSheetScheduleCache $cache,
        private IfscScheduleFactory $scheduleFactory,
    ) {
    }

    public function parseScheduleFromPdf(
        EventInfo $event,
        string $pdfPath,
        string $infoSheetUrl = '',
        array $infoSheetHeaders = [],
        bool $forceRescan = false,
    ): InfoSheetParseResult {
        $pdfHash = $this->cache->hashFile($pdfPath);

        if (!$forceRescan) {
            $cachedPayload = $this->cache->loadFromHeadersAndHash($infoSheetHeaders, $pdfHash);

            if ($cachedPayload !== null) {
                return new InfoSheetParseResult(
                    schedules: $this->hydrateSchedules($cachedPayload['rounds'], $event->timeZone),
                    ticketInfo: $this->hydrateTicketInfo($cachedPayload),
                );
            }
        }

        try {
            $fileId = $this->openAiInfoSheetClient->uploadInfoSheet($pdfPath);
        } catch (InfoSheetChatGptScheduleParserException $exception) {
            throw $this->enrichStepException(
                step: 'upload_infosheet',
                event: $event,
                pdfPath: $pdfPath,
                infoSheetUrl: $infoSheetUrl,
                pdfHash: $pdfHash,
                previous: $exception,
            );
        }

        try {
            try {
                $schedulePayload = $this->openAiInfoSheetClient->extractSchedulePayload(
                    event: $event,
                    fileId: $fileId,
                    pdfPath: $pdfPath,
                );
            } catch (InfoSheetChatGptScheduleParserException $exception) {
                throw $this->enrichStepException(
                    step: 'parse_infosheet_response',
                    event: $event,
                    pdfPath: $pdfPath,
                    infoSheetUrl: $infoSheetUrl,
                    pdfHash: $pdfHash,
                    previous: $exception,
                    openAiFileId: $fileId,
                );
            }

            $rounds = $schedulePayload['rounds'];
            $this->cache->store(
                infoSheetUrl: $infoSheetUrl,
                infoSheetHeaders: $infoSheetHeaders,
                pdfHash: $pdfHash,
                rounds: $rounds,
                ticketInfo: [
                    'ticket_purchase_url' => $schedulePayload['ticket_purchase_url'] ?? null,
                    'ticket_price' => $schedulePayload['ticket_price'] ?? null,
                    'ticket_currency' => $schedulePayload['ticket_currency'] ?? null,
                    'ticket_summary' => $schedulePayload['ticket_summary'] ?? null,
                ],
            );

            return new InfoSheetParseResult(
                schedules: $this->hydrateSchedules($rounds, $event->timeZone),
                ticketInfo: $this->hydrateTicketInfo($schedulePayload),
            );
        } finally {
            $this->openAiInfoSheetClient->deleteUploadedFile($fileId);
        }
    }

    public function loadCachedSchedule(
        EventInfo $event,
        string $infoSheetUrl,
        array $infoSheetHeaders = [],
    ): ?InfoSheetParseResult {
        $payload = $this->cache->loadFromHeadersAndHash($infoSheetHeaders, null);

        if ($payload !== null) {
            return new InfoSheetParseResult(
                schedules: $this->hydrateSchedules($payload['rounds'], $event->timeZone),
                ticketInfo: $this->hydrateTicketInfo($payload),
            );
        }

        $stalePayload = $this->cache->loadFromUrlIfStale($infoSheetUrl, $infoSheetHeaders);

        if ($stalePayload === null) {
            return null;
        }

        return new InfoSheetParseResult(
            schedules: $this->hydrateSchedules($stalePayload['rounds'], $event->timeZone),
            ticketInfo: $this->hydrateTicketInfo($stalePayload),
        );
    }

    /**
     * @param array<array{name:mixed,starts_at:mixed,ends_at:mixed}> $rounds
     * @return IfscSchedule[]
     */
    private function hydrateSchedules(array $rounds, DateTimeZone $timeZone): array
    {
        /** @var IfscSchedule[] $schedules */
        $schedules = [];
        /** @var array<string,bool> $seen */
        $seen = [];

        foreach ($rounds as $round) {
            $name = $this->toStringOrNull($round['name'] ?? null);
            $startsAt = $this->parseDateTime($round['starts_at'] ?? null, $timeZone);

            if ($name === null || $startsAt === null) {
                continue;
            }

            $schedule = $this->scheduleFactory->create(
                name: $name,
                startsAt: $startsAt,
                endsAt: $this->parseDateTime($round['ends_at'] ?? null, $timeZone),
            );

            $id = sprintf(
                '%s|%s|%s',
                strtolower($schedule->name),
                $schedule->startsAt->format(DateTimeInterface::RFC3339),
                $schedule->endsAt?->format(DateTimeInterface::RFC3339) ?? '',
            );

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $schedules[] = $schedule;
        }

        return $schedules;
    }

    private function parseDateTime(mixed $value, DateTimeZone $timeZone): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $fromFormat = DateTimeImmutable::createFromFormat(self::DATE_TIME_FORMAT, $value, $timeZone);

        if ($fromFormat instanceof DateTimeImmutable) {
            return $fromFormat;
        }

        try {
            return (new DateTimeImmutable($value, $timeZone))->setTimezone($timeZone);
        } catch (Exception) {
            return null;
        }
    }

    private function toStringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value !== '') {
            $value = $this->trimWrappingQuotes($value);
        }

        return $value === '' ? null : $value;
    }

    private function trimWrappingQuotes(string $value): string
    {
        while (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first !== '\'' && $first !== '"') || $first !== $last) {
                break;
            }

            $inner = trim(substr($value, 1, -1));

            if ($inner === '') {
                break;
            }

            $value = $inner;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function hydrateTicketInfo(array $payload): InfoSheetTicketInfo
    {
        return new InfoSheetTicketInfo(
            purchaseUrl: $this->toStringOrNull($payload['ticket_purchase_url'] ?? null),
            price: $this->toStringOrNull($payload['ticket_price'] ?? null),
            currency: $this->toStringOrNull($payload['ticket_currency'] ?? null),
            summary: $this->toStringOrNull($payload['ticket_summary'] ?? null),
        );
    }

    private function enrichStepException(
        string $step,
        EventInfo $event,
        string $pdfPath,
        string $infoSheetUrl,
        ?string $pdfHash,
        InfoSheetChatGptScheduleParserException $previous,
        ?string $openAiFileId = null,
    ): InfoSheetChatGptScheduleParserException {
        $context = [
            'step' => $step,
            'event_id' => $event->eventId,
            'event_name' => $event->eventName,
            'infosheet_url' => trim($infoSheetUrl) !== '' ? $infoSheetUrl : null,
            'pdf_path' => $pdfPath,
            'pdf_sha256' => $pdfHash,
            'openai_file_id' => $openAiFileId,
        ];

        return new InfoSheetChatGptScheduleParserException(
            sprintf(
                'Infosheet schedule sync failed (%s): %s',
                $this->formatContext($context),
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }

    /** @param array<string,mixed> $context */
    private function formatContext(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }

            $key = trim($key);

            if ($value === null) {
                $parts[] = "{$key}=null";

                continue;
            }

            if (is_bool($value)) {
                $parts[] = sprintf('%s=%s', $key, $value ? 'true' : 'false');

                continue;
            }

            if (is_scalar($value)) {
                $parts[] = sprintf('%s=%s', $key, trim((string) $value));
            }
        }

        return implode(', ', $parts);
    }
}
