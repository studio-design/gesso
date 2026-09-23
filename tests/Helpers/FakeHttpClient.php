<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function array_keys;
use function implode;
use function is_callable;
use function sprintf;

/**
 * Test double PSR-18 client. Returns pre-canned responses keyed by URL.
 * Throws {@see FakeHttpClientUnexpectedRequest} (a `ClientExceptionInterface`)
 * when a test forgets to register a response, so the surfacing error
 * points at the missing fixture rather than at a "no response" mystery.
 *
 * The map allows two value shapes:
 *  - `ResponseInterface` — returned as-is
 *  - `callable(RequestInterface): ResponseInterface` — for tests that
 *    need to assert on the request or simulate dynamic behaviour
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var array<string, callable(RequestInterface): ResponseInterface|ResponseInterface> */
    private array $responses;

    /** @var list<string> */
    private array $sentUrls = [];

    /** @param array<string, callable(RequestInterface): ResponseInterface|ResponseInterface> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public static function jsonResponse(string $body, int $status = 200, ?string $contentType = 'application/json'): ResponseInterface
    {
        $headers = $contentType !== null ? ['Content-Type' => $contentType] : [];

        return new Response($status, $headers, $body);
    }

    public static function yamlResponse(string $body, int $status = 200, ?string $contentType = 'application/yaml'): ResponseInterface
    {
        $headers = $contentType !== null ? ['Content-Type' => $contentType] : [];

        return new Response($status, $headers, $body);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->sentUrls[] = $url;

        if (!isset($this->responses[$url])) {
            throw new FakeHttpClientUnexpectedRequest(sprintf(
                'No mock response registered for %s. Registered URLs: %s',
                $url,
                $this->responses === [] ? '<none>' : implode(', ', array_keys($this->responses)),
            ));
        }

        $response = $this->responses[$url];
        if (is_callable($response)) {
            return $response($request);
        }

        return $response;
    }

    /** @return list<string> */
    public function sentUrls(): array
    {
        return $this->sentUrls;
    }
}
