<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Infrastructure\IFSC;

use GuzzleHttp\Client;

final readonly class IfscApiClientFactory
{
    private const string BASE_URI = 'https://ifsc.results.info';

    public function __construct(
        private IfscApiSessionAuthenticator $authenticator,
    ) {
    }

    /**
     * @param array<string, mixed> $httpClientConfig
     */
    public function create(array $httpClientConfig = []): GuzzleIfscApiClient
    {
        $defaultConfig = [
            'base_uri' => self::BASE_URI,
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 15,
        ];

        return new GuzzleIfscApiClient(
            httpClient: new Client(array_replace_recursive($defaultConfig, $httpClientConfig)),
            sessionToken: $this->authenticator->fetchSessionId(),
        );
    }
}
