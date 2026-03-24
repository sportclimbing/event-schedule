<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nico Oelgart <nico@ifsc.stream>
 */
namespace SportClimbing\EventDetails\Tests\Infrastructure\IFSC;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Infrastructure\IFSC\Exception\IfscApiClientException;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscApiSessionAuthenticator;

final class IfscApiSessionAuthenticatorTest extends TestCase
{
    public function testFetchSessionIdReturnsSessionCookieValue(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                new Response(200, [
                    'Set-Cookie' => [
                        'some_cookie=abc; path=/; HttpOnly',
                        '_ifsc_resultservice_session=session-id-123; path=/; HttpOnly',
                    ],
                ]),
            ]),
        );

        self::assertSame('session-id-123', $authenticator->fetchSessionId());
    }

    public function testFetchSessionIdThrowsWhenSessionCookieIsMissing(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                new Response(200, [
                    'Set-Cookie' => ['foo=bar; path=/'],
                ]),
            ]),
        );

        $this->expectException(IfscApiClientException::class);
        $this->expectExceptionMessage('Could not retrieve session cookie');

        $authenticator->fetchSessionId();
    }

    public function testFetchSessionIdCanReadCookieFromNonSuccessfulResponse(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                new Response(400, [
                    'Set-Cookie' => [
                        '_ifsc_resultservice_session=session-id-from-400; path=/; HttpOnly',
                    ],
                ]),
            ]),
        );

        self::assertSame('session-id-from-400', $authenticator->fetchSessionId());
    }

    public function testFetchSessionIdThrowsWhenRequestFails(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                new ConnectException(
                    'Connection failed',
                    new Request('GET', IfscApiSessionAuthenticator::IFSC_RESULTS_INFO_PAGE),
                ),
            ]),
        );

        $this->expectException(IfscApiClientException::class);
        $this->expectExceptionMessage('Could not retrieve session cookie');

        $authenticator->fetchSessionId();
    }

    /**
     * @param array<Response|ConnectException> $responses
     */
    private function buildHttpClient(array $responses): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));

        return new Client([
            'handler' => $handler,
        ]);
    }
}
