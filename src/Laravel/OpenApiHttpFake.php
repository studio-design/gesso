<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Assert;
use Studio\Gesso\Internal\CurlCommandFormatter;
use Studio\Gesso\Internal\FailureOutput;
use Studio\Gesso\OpenApiFakeResponse;
use Studio\Gesso\Psr7\OpenApiPsr7Validator;

use function sprintf;

/**
 * Consumer-side contract testing for Laravel's HTTP client: answers
 * `Http::fake()` from an external service's OpenAPI document and validates
 * every outgoing request against it.
 *
 * ```php
 * Http::fake(OpenApiHttpFake::respond('stripe'));
 * Http::fake(['api.stripe.com/*' => OpenApiHttpFake::respond('stripe', ['POST /v1/charges' => 402])]);
 * ```
 */
final class OpenApiHttpFake
{
    private function __construct() {}

    /**
     * An `Http::fake()`-compatible responder. An outgoing request the spec
     * rejects fails the test with the adapters' usual failure output; an
     * accepted one is answered by {@see OpenApiFakeResponse::for()}.
     *
     * @param string $specName named spec of the external API, loaded like any other spec
     * @param array<string, int> $statuses `'METHOD /spec/{template}'` => status to answer with (default: the lowest declared 2xx)
     *
     * @return Closure(Request): PromiseInterface
     */
    public static function respond(string $specName, array $statuses = []): Closure
    {
        $validator = new OpenApiPsr7Validator($specName);

        return static function (Request $request) use ($specName, $statuses, $validator): PromiseInterface {
            $psr = $request->toPsrRequest();
            $method = $psr->getMethod();
            $path = $psr->getUri()->getPath();
            $path = $path === '' ? '/' : $path;

            $result = $validator->validateRequest($psr);
            if (!$result->isValid()) {
                Assert::fail(FailureOutput::compose(
                    sprintf('OpenAPI fake rejected outgoing request %s %s (spec: %s)', $method, $path, $specName),
                    $result,
                    static fn(): string => CurlCommandFormatter::format(
                        $method,
                        (string) $psr->getUri(),
                        $psr->getHeaders(),
                        (string) $psr->getBody(),
                        $psr->hasHeader('Content-Type') ? $psr->getHeaderLine('Content-Type') : null,
                    ),
                ));
            }

            $fake = OpenApiFakeResponse::for(
                $specName,
                $method,
                $path,
                $statuses[$method . ' ' . ($result->matchedPath() ?? $path)] ?? null,
            );

            return Factory::response($fake->content(), $fake->status(), $fake->headers());
        };
    }
}
