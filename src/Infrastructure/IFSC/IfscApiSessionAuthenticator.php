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
use SportClimbing\EventDetails\Infrastructure\IFSC\Exception\IfscApiClientException;

final readonly class IfscApiSessionAuthenticator
{
    public const string IFSC_SESSION_COOKIE_NAME = '_ifsc_resultservice_session';

    public const string IFSC_RESULTS_INFO_PAGE = 'https://ifsc.results.info/';

    public function __construct(
        private ClientInterface $httpClient,
    ) {
    }

    /** @throws IfscApiClientException */
    public function fetchSessionId(): string
    {
        try {
            foreach ($this->getCookies() as $cookie) {
                $parsedCookie = $this->parseCookie($cookie);

                if (isset($parsedCookie[self::IFSC_SESSION_COOKIE_NAME])) {
                    return $this->extractSessionId($parsedCookie);
                }
            }
        } catch (IfscApiClientException) {
        }

        throw new IfscApiClientException('Could not retrieve session cookie');
    }

    /**
     * @return string[]
     * @throws IfscApiClientException
     */
    private function getCookies(): array
    {
        try {
            $response = $this->httpClient->request('GET', self::IFSC_RESULTS_INFO_PAGE, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new IfscApiClientException('Could not retrieve session cookie', 0, $exception);
        }

        /** @var array<string, string[]> $headers */
        $headers = array_change_key_case($response->getHeaders(), CASE_LOWER);

        return $headers['set-cookie'] ?? [];
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
