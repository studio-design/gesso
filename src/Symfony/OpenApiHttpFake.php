<?php

declare(strict_types=1);

namespace Studio\Gesso\Symfony;

use const PHP_URL_PATH;
use const PHP_URL_QUERY;

use Closure;
use JsonException;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\Internal\CurlCommandFormatter;
use Studio\Gesso\Internal\FailureOutput;
use Studio\Gesso\Internal\WireQueryParser;
use Studio\Gesso\OpenApiFakeResponse;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\PHPUnit\CleanTraceAssert;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Symfony\Component\HttpClient\Response\MockResponse;

use function explode;
use function is_array;
use function is_resource;
use function is_string;
use function parse_url;
use function rewind;
use function sprintf;
use function stream_get_contents;
use function stream_get_meta_data;
use function strtolower;
use function trim;

/**
 * Consumer-side contract testing for Symfony's HTTP client: a
 * `MockHttpClient` response factory that answers from an external service's
 * OpenAPI document and validates every outgoing request against it.
 *
 * ```php
 * $client = new MockHttpClient(OpenApiHttpFake::respond('stripe'));
 * ```
 */
final class OpenApiHttpFake
{
    private function __construct() {}

    /**
     * A `MockHttpClient` response factory. An outgoing request the spec
     * rejects fails the test with the adapters' usual failure output; an
     * accepted one is answered by {@see OpenApiFakeResponse::for()}.
     *
     * Cookies are not read: `apiKey` schemes `in: cookie` report as missing.
     *
     * @param string $specName named spec of the external API, loaded like any other spec
     * @param array<string, int> $statuses `'METHOD /spec/{template}'` => status to answer with (default: the lowest declared 2xx)
     *
     * @return Closure(string, string, array<string, mixed>): MockResponse
     */
    public static function respond(string $specName, array $statuses = []): Closure
    {
        $validator = new OpenApiRequestValidator();

        return static function (string $method, string $url, array $options) use ($specName, $statuses, $validator): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            $path = is_string($path) && $path !== '' ? $path : '/';
            $rawQuery = parse_url($url, PHP_URL_QUERY);
            $rawQuery = is_string($rawQuery) && $rawQuery !== '' ? $rawQuery : null;
            $query = WireQueryParser::parse($rawQuery ?? '');

            $headers = self::headers($options);
            $contentType = $headers['content-type'][0] ?? null;
            $raw = self::body($options['body'] ?? '');

            $decoded = DecodedBody::absent();
            $decodeError = null;
            if ($raw !== '') {
                if (ContentTypeMatcher::isJsonOrUnspecified($contentType)) {
                    try {
                        $decoded = DecodedBody::decodeJson($raw);
                    } catch (JsonException $e) {
                        $decodeError = 'Request body could not be parsed as JSON: ' . $e->getMessage();
                    }
                } else {
                    // Form and other bodies go to the core as raw bytes: it
                    // parses urlencoded fields and reports raw multipart as
                    // skipped, exactly as for a PSR-7 client request.
                    $decoded = DecodedBody::present($raw);
                }
            }

            $result = $decodeError === null
                ? $validator->validate($specName, $method, $path, $query, $headers, $decoded, $contentType, [], null, $rawQuery)
                : OpenApiValidationResult::failure([$decodeError]);
            if (!$result->isValid()) {
                CleanTraceAssert::fail(FailureOutput::compose(
                    sprintf('OpenAPI fake rejected outgoing request %s %s (spec: %s)', $method, $path, $specName),
                    $result,
                    static fn(): string => CurlCommandFormatter::format($method, $url, $headers, $raw !== '' ? $raw : null, $contentType),
                ));
            }

            $fake = OpenApiFakeResponse::for(
                $specName,
                $method,
                $path,
                $statuses[$method . ' ' . ($result->matchedPath() ?? $path)] ?? null,
            );

            return new MockResponse($fake->content(), [
                'http_code' => $fake->status(),
                'response_headers' => $fake->headers(),
            ]);
        };
    }

    /**
     * `normalized_headers` is `lowercase-name => ["Name: value", ...]` once
     * `MockHttpClient` has prepared the request.
     *
     * @param array<array-key, mixed> $options
     *
     * @return array<string, list<string>>
     */
    private static function headers(array $options): array
    {
        $headers = [];
        $normalized = is_array($options['normalized_headers'] ?? null) ? $options['normalized_headers'] : [];
        foreach ($normalized as $name => $lines) {
            foreach (is_array($lines) ? $lines : [] as $line) {
                $parts = explode(':', (string) $line, 2);
                $headers[strtolower((string) $name)][] = trim($parts[1] ?? '');
            }
        }

        return $headers;
    }

    /**
     * Reads the prepared request body: a string, a stream (rewound so the
     * mock can read it again), or a chunk-producing closure.
     */
    private static function body(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (is_resource($body)) {
            $content = (string) stream_get_contents($body);
            if (stream_get_meta_data($body)['seekable']) {
                rewind($body);
            }

            return $content;
        }
        if ($body instanceof Closure) {
            $content = '';
            while (is_string($chunk = $body(16372)) && $chunk !== '') {
                $content .= $chunk;
            }

            return $content;
        }

        return '';
    }
}
