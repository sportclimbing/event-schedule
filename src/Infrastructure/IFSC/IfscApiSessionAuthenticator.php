<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\IFSC;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use SportClimbing\EventDetails\Infrastructure\IFSC\Exception\IfscApiClientException;

final readonly class IfscApiSessionAuthenticator
{
    public const string IFSC_SESSION_COOKIE_NAME = '_ifsc_resultservice_session';

    public const string IFSC_RESULTS_INFO_PAGE = 'https://ifsc.results.info/';

    public const string IFSC_ENTRYPOINT_URL = 'https://ifsc.results.info/entrypoint';

    public function __construct(
        private ClientInterface $httpClient,
    ) {
    }

    /** @throws IfscApiClientException */
    public function fetchSession(): IfscApiSession
    {
        $homePage = $this->get(self::IFSC_RESULTS_INFO_PAGE, [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
        $initialSessionId = $this->findSessionId($homePage)
            ?? throw new IfscApiClientException('Could not retrieve session cookie');
        $csrfToken = $this->extractCsrfToken($homePage)
            ?? throw new IfscApiClientException('Could not retrieve CSRF token');

        $entrypoint = $this->get(self::IFSC_ENTRYPOINT_URL, [
            'Accept' => 'application/json',
            'X-CSRF-Token' => $csrfToken,
            'Referer' => self::IFSC_RESULTS_INFO_PAGE,
            'Cookie' => sprintf('%s=%s', self::IFSC_SESSION_COOKIE_NAME, $initialSessionId),
        ]);

        return new IfscApiSession(
            sessionId: $this->findSessionId($entrypoint) ?? $initialSessionId,
            csrfToken: $csrfToken,
            internalToken: $this->extractInternalToken($entrypoint)
                ?? throw new IfscApiClientException('Could not retrieve internal API token'),
        );
    }

    /**
     * @param array<string, string> $headers
     * @throws IfscApiClientException
     */
    private function get(string $url, array $headers): ResponseInterface
    {
        try {
            return $this->httpClient->request('GET', $url, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::HEADERS => $headers,
            ]);
        } catch (GuzzleException $exception) {
            throw new IfscApiClientException('Could not retrieve session cookie', 0, $exception);
        }
    }

    private function findSessionId(ResponseInterface $response): ?string
    {
        /** @var array<string, string[]> $headers */
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);

        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            $parsedCookie = $this->parseCookie($cookie);

            if (isset($parsedCookie[self::IFSC_SESSION_COOKIE_NAME])) {
                return $this->extractSessionId($parsedCookie);
            }
        }

        return null;
    }

    private function extractCsrfToken(ResponseInterface $response): ?string
    {
        if (preg_match('/<meta\s+name="csrf-token"\s+content="([^"]+)"/i', (string) $response->getBody(), $matches)) {
            return html_entity_decode($matches[1]);
        }

        return null;
    }

    private function extractInternalToken(ResponseInterface $response): ?string
    {
        $payload = json_decode((string) $response->getBody(), true);
        $token = is_array($payload) ? ($payload['entrypoint']['internal_api_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return array<string,string> */
    private function parseCookie(string $cookie): array
    {
        parse_str($cookie, $result);

        return $result;
    }

    /** @param array<string,string> $parsedCookie */
    private function extractSessionId(array $parsedCookie): string
    {
        return sscanf($parsedCookie[self::IFSC_SESSION_COOKIE_NAME], '%[^;]s')[0];
    }
}
