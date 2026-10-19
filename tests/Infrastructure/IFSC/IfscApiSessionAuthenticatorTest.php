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
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SportClimbing\EventDetails\Infrastructure\IFSC\Exception\IfscApiClientException;
use SportClimbing\EventDetails\Infrastructure\IFSC\IfscApiSessionAuthenticator;

final class IfscApiSessionAuthenticatorTest extends TestCase
{
    public function testFetchSessionReturnsSessionCsrfAndInternalToken(): void
    {
        $history = [];
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                $this->homePageResponse('session-id-123', 'csrf-abc'),
                $this->entrypointResponse('internal-xyz'),
            ], $history),
        );

        $session = $authenticator->fetchSession();

        self::assertSame('session-id-123', $session->sessionId);
        self::assertSame('csrf-abc', $session->csrfToken);
        self::assertSame('internal-xyz', $session->internalToken);

        $entrypointRequest = $history[1]['request'];
        self::assertSame(IfscApiSessionAuthenticator::IFSC_ENTRYPOINT_URL, (string) $entrypointRequest->getUri());
        self::assertSame('csrf-abc', $entrypointRequest->getHeaderLine('x-csrf-token'));
        self::assertSame(
            IfscApiSessionAuthenticator::IFSC_SESSION_COOKIE_NAME . '=session-id-123',
            $entrypointRequest->getHeaderLine('cookie'),
        );
    }

    public function testFetchSessionUsesRotatedSessionCookieFromEntrypoint(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                $this->homePageResponse('session-id-123', 'csrf-abc'),
                $this->entrypointResponse('internal-xyz', 'rotated-session'),
            ]),
        );

        self::assertSame('rotated-session', $authenticator->fetchSession()->sessionId);
    }

    public function testFetchSessionThrowsWhenSessionCookieIsMissing(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                new Response(200, ['Set-Cookie' => ['foo=bar; path=/']], '<meta name="csrf-token" content="csrf" />'),
            ]),
        );

        $this->expectException(IfscApiClientException::class);
        $this->expectExceptionMessage('Could not retrieve session cookie');

        $authenticator->fetchSession();
    }

    public function testFetchSessionThrowsWhenCsrfTokenIsMissing(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                new Response(200, ['Set-Cookie' => ['_ifsc_resultservice_session=abc; path=/']], '<html></html>'),
            ]),
        );

        $this->expectException(IfscApiClientException::class);
        $this->expectExceptionMessage('Could not retrieve CSRF token');

        $authenticator->fetchSession();
    }

    public function testFetchSessionThrowsWhenInternalTokenIsMissing(): void
    {
        $authenticator = new IfscApiSessionAuthenticator(
            $this->buildHttpClient([
                $this->homePageResponse('session-id-123', 'csrf-abc'),
                new Response(200, [], json_encode(['entrypoint' => []], JSON_THROW_ON_ERROR)),
            ]),
        );

        $this->expectException(IfscApiClientException::class);
        $this->expectExceptionMessage('Could not retrieve internal API token');

        $authenticator->fetchSession();
    }

    public function testFetchSessionThrowsWhenRequestFails(): void
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

        $authenticator->fetchSession();
    }

    private function homePageResponse(string $sessionId, string $csrfToken): Response
    {
        return new Response(
            200,
            ['Set-Cookie' => [
                'some_cookie=abc; path=/; HttpOnly',
                sprintf('_ifsc_resultservice_session=%s; path=/; HttpOnly', $sessionId),
            ]],
            sprintf('<head><meta name="csrf-token" content="%s" /></head>', $csrfToken),
        );
    }

    private function entrypointResponse(string $internalToken, ?string $rotatedSessionId = null): Response
    {
        $headers = $rotatedSessionId !== null
            ? ['Set-Cookie' => [sprintf('_ifsc_resultservice_session=%s; path=/', $rotatedSessionId)]]
            : [];

        return new Response(
            200,
            $headers,
            json_encode(['entrypoint' => ['internal_api_token' => $internalToken]], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<Response|ConnectException> $responses
     * @param array<int, array<string, mixed>>|null $history
     */
    private function buildHttpClient(array $responses, ?array &$history = null): Client
    {
        $handler = HandlerStack::create(new MockHandler($responses));

        if ($history !== null) {
            $handler->push(Middleware::history($history));
        }

        return new Client(['handler' => $handler]);
    }
}
