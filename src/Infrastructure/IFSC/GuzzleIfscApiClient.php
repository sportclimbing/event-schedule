<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\IFSC;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use JsonException;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\EventDetails;
use SportClimbing\EventDetails\Domain\Event\Port\Dto\LeagueEvent;
use SportClimbing\EventDetails\Domain\Event\Port\IfscApiClientInterface;
use SportClimbing\EventDetails\Infrastructure\IFSC\Exception\IfscApiClientException;

final readonly class GuzzleIfscApiClient implements IfscApiClientInterface
{
    public function __construct(
        private ClientInterface $httpClient,
        private string $sessionToken,
    ) {
    }

    /** @return LeagueEvent[] */
    public function fetchLeagueEvents(int $leagueId): array
    {
        $payload = $this->requireObject(
            $this->authenticatedGet(sprintf('/api/v1/season_leagues/%d', $leagueId)),
            'season league response',
        );
        $events = $this->requireArrayProperty($payload, 'events', 'season league response');
        $leagueEvents = [];

        foreach ($events as $index => $event) {
            $eventPayload = $this->requireObject($event, sprintf('season league event at index %s', (string) $index));

            $leagueEvents[] = new LeagueEvent(
                eventId: $this->requireIntProperty($eventPayload, 'event_id', 'season league event'),
                eventName: $this->requireStringProperty($eventPayload, 'event', 'season league event'),
                localStartDate: $this->requireStringProperty($eventPayload, 'local_start_date', 'season league event'),
                localEndDate: $this->requireStringProperty($eventPayload, 'local_end_date', 'season league event'),
                leagueName: $this->firstOptionalStringProperty(
                    $eventPayload,
                    ['league_name', 'league', 'cup_name'],
                    'season league event',
                ),
                infosheetUrl: $this->optionalStringProperty($eventPayload, 'infosheet_url', 'season league event'),
                ticketUrl: $this->firstOptionalStringProperty(
                    $eventPayload,
                    ['ticket_url', 'ticket_purchase_url'],
                    'season league event',
                ),
                ticketPrice: $this->optionalStringOrNumericProperty(
                    $eventPayload,
                    'ticket_price',
                    'season league event',
                ),
                ticketCurrency: $this->firstOptionalStringProperty(
                    $eventPayload,
                    ['ticket_currency', 'currency'],
                    'season league event',
                ),
            );
        }

        return $leagueEvents;
    }

    public function fetchEventDetails(int $eventId): EventDetails
    {
        $payload = $this->requireObject(
            $this->authenticatedGet(sprintf('/api/v1/events/%d', $eventId)),
            'event details response',
        );
        $disciplines = [];

        foreach ($this->requireArrayProperty($payload, 'disciplines', 'event details response') as $index => $discipline) {
            $disciplinePayload = $this->requireObject(
                $discipline,
                sprintf('event discipline at index %s', (string) $index),
            );

            $disciplines[] = $this->requireStringProperty($disciplinePayload, 'kind', 'event discipline');
        }

        return new EventDetails(
            id: $this->requireIntProperty($payload, 'id', 'event details response'),
            leagueId: $this->requireIntProperty($payload, 'league_id', 'event details response'),
            leagueSeasonId: $this->requireIntProperty($payload, 'league_season_id', 'event details response'),
            location: $this->requireStringProperty($payload, 'location', 'event details response'),
            country: $this->requireStringProperty($payload, 'country', 'event details response'),
            timeZone: $this->requireTimeZone($payload),
            disciplineKinds: $disciplines,
        );
    }

    public function authenticatedGet(string $url): object|array
    {
        return $this->request($url, [
            RequestOptions::HEADERS => [
                // Apparently, this is required to pass the authorization check
                'referer' => IfscApiSessionAuthenticator::IFSC_RESULTS_INFO_PAGE,
            ],
            RequestOptions::COOKIES => CookieJar::fromArray(
                cookies: [IfscApiSessionAuthenticator::IFSC_SESSION_COOKIE_NAME => $this->sessionToken],
                domain: 'ifsc.results.info',
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @return object|array<mixed>
     */
    private function request(string $uri, array $options = []): object|array
    {
        try {
            $response = $this->httpClient->request('GET', $uri, $options);
        } catch (GuzzleException $exception) {
            throw new IfscApiClientException(
                sprintf('IFSC API request failed for "%s": %s', $uri, $exception->getMessage()),
                0,
                $exception,
            );
        }

        try {
            $payload = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new IfscApiClientException(
                sprintf('Invalid JSON returned by IFSC API for "%s".', $uri),
                0,
                $exception,
            );
        }

        if (!is_array($payload) && !is_object($payload)) {
            throw new IfscApiClientException(sprintf('Unexpected response structure for "%s".', $uri));
        }

        return $payload;
    }

    private function requireObject(mixed $payload, string $context): object
    {
        if (!is_object($payload)) {
            throw new IfscApiClientException(sprintf('Expected object payload in %s.', $context));
        }

        return $payload;
    }

    private function requireIntProperty(object $payload, string $key, string $context): int
    {
        if (!isset($payload->{$key}) || !is_int($payload->{$key})) {
            throw new IfscApiClientException(sprintf('Expected "%s" to be int in %s.', $key, $context));
        }

        return $payload->{$key};
    }

    private function requireStringProperty(object $payload, string $key, string $context): string
    {
        if (!isset($payload->{$key}) || !is_string($payload->{$key})) {
            throw new IfscApiClientException(sprintf('Expected "%s" to be string in %s.', $key, $context));
        }

        return $payload->{$key};
    }

    private function optionalStringProperty(object $payload, string $key, string $context): ?string
    {
        if (!property_exists($payload, $key) || $payload->{$key} === null) {
            return null;
        }

        if (!is_string($payload->{$key})) {
            throw new IfscApiClientException(sprintf('Expected "%s" to be string|null in %s.', $key, $context));
        }

        return $payload->{$key};
    }

    /**
     * @param string[] $keys
     */
    private function firstOptionalStringProperty(object $payload, array $keys, string $context): ?string
    {
        foreach ($keys as $key) {
            $value = $this->optionalStringProperty($payload, $key, $context);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function optionalStringOrNumericProperty(object $payload, string $key, string $context): ?string
    {
        if (!property_exists($payload, $key) || $payload->{$key} === null) {
            return null;
        }

        if (!is_string($payload->{$key}) && !is_int($payload->{$key}) && !is_float($payload->{$key})) {
            throw new IfscApiClientException(sprintf('Expected "%s" to be string|int|float|null in %s.', $key, $context));
        }

        return (string) $payload->{$key};
    }

    /** @return array<mixed> */
    private function requireArrayProperty(object $payload, string $key, string $context): array
    {
        if (!isset($payload->{$key}) || !is_array($payload->{$key})) {
            throw new IfscApiClientException(sprintf('Expected "%s" to be array in %s.', $key, $context));
        }

        return $payload->{$key};
    }

    private function requireTimeZone(object $payload): string
    {
        if (!isset($payload->timezone) || !is_object($payload->timezone)) {
            throw new IfscApiClientException('Expected "timezone" to be object in event details response.');
        }

        return $this->requireStringProperty($payload->timezone, 'value', 'event details timezone');
    }
}
