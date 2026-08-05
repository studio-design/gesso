<?php

declare(strict_types=1);

namespace Studio\Gesso\Stubs;

use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Validation\Request\ParameterCollector;
use Studio\Gesso\Validation\Response\ResponseStatusTargetEnumerator;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;

use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function ksort;
use function preg_match;
use function rawurlencode;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function usort;

/**
 * Turns a resolved spec — optionally joined against a coverage document — into
 * the per-operation plans {@see StubRenderer} writes out as test classes.
 *
 * The walk deliberately mirrors {@see OpenApiCoverageTracker}'s declared-endpoint
 * walk: the same tracked methods, and the same "a response without `content`
 * contributes a single `(status, '*')` tuple" rule. A stub for a tuple the
 * tracker can never report would be a test that cannot move the coverage number.
 *
 * @phpstan-type StubTuple array{status: string, content_type: string, status_code: null|int, is_range: bool, reason: 'ok'|'unreachable'|'malformed', example: mixed, has_example: bool}
 * @phpstan-type StubOperation array{
 *     method: string,
 *     path: string,
 *     request_path: string,
 *     operation_id: null|string,
 *     summary: null|string,
 *     headers: array<string, string>,
 *     request_body: mixed,
 *     has_request_body: bool,
 *     request_content_type: null|string,
 *     tuples: list<StubTuple>,
 * }
 *
 * @internal The `gesso stubs` / `openapi:stubs` CLI surface is the supported API.
 */
final class StubGenerator
{
    /**
     * Methods {@see OpenApiCoverageTracker} records. `OPTIONS` / `HEAD` /
     * `TRACE` never reach a coverage document, so stubbing them would generate
     * tests that can never turn a tuple green.
     */
    public const TRACKED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'QUERY'];

    /**
     * Read `(method, path, status, content-type) => response_state` out of a
     * `schema_version: 3` coverage document. Unknown or malformed rows are
     * skipped rather than rejected: a tuple missing from the map is treated as
     * uncovered, which is the safe direction for a scaffolding command.
     *
     * @param array<string, mixed> $spec one entry under the document's `specs`
     *
     * @return array<string, string>
     */
    public static function statesFromCoverage(array $spec): array
    {
        $states = [];
        $endpoints = is_array($spec['endpoints'] ?? null) ? $spec['endpoints'] : [];

        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint) || !is_string($endpoint['method'] ?? null) || !is_string($endpoint['path'] ?? null)) {
                continue;
            }
            $key = $endpoint['method'] . ' ' . $endpoint['path'];
            $responses = is_array($endpoint['responses'] ?? null) ? $endpoint['responses'] : [];
            foreach ($responses as $response) {
                if (!is_array($response) ||
                    !is_string($response['status_key'] ?? null) ||
                    !is_string($response['content_type_key'] ?? null) ||
                    !is_string($response['response_state'] ?? null)) {
                    continue;
                }
                $states[$key . "\x1f" . $response['status_key'] . "\x1f" . $response['content_type_key']] = $response['response_state'];
            }
        }

        return $states;
    }

    /**
     * @param array<string, mixed> $spec resolved by OpenApiSpecLoader
     * @param null|array<string, string> $states `"METHOD path\x1fstatus\x1fcontentType" => response_state`,
     *                                           or null to stub every declared tuple
     *
     * @return list<StubOperation>
     */
    public function plan(array $spec, ?array $states): array
    {
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $plans = [];

        foreach ($paths as $path => $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }
            $path = (string) $path;

            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $operation = $declared['operation'];
                $method = $declared['method'];
                if (!is_array($operation) || !$this->isTrackedMethod($method, $declared['location'])) {
                    continue;
                }

                // Operations with nothing left to stub stay in the list: the
                // file name a plan resolves to has to be decided against every
                // operation the spec declares, not just the uncovered ones.
                // See StubRenderer::classNames().
                $tuples = $this->tuples($method, $path, $operation, $states);
                $parameters = ParameterCollector::collect($method, $path, $pathItem, $operation)->parameters;
                [$requestBody, $hasRequestBody, $requestContentType] = $this->requestBody($operation);

                $plans[] = [
                    'method' => $method,
                    'path' => $path,
                    'request_path' => $this->requestPath($path, $parameters),
                    'operation_id' => is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null,
                    'summary' => is_string($operation['summary'] ?? null) ? $operation['summary'] : null,
                    'headers' => $this->requiredHeaders($parameters),
                    'request_body' => $requestBody,
                    'has_request_body' => $hasRequestBody,
                    'request_content_type' => $requestContentType,
                    'tuples' => $tuples,
                ];
            }
        }

        // Stable output so re-running the command produces a diffable result.
        usort($plans, static fn(array $a, array $b): int => strcmp(
            $a['method'] . ' ' . $a['path'],
            $b['method'] . ' ' . $b['path'],
        ));

        return $plans;
    }

    /** Response keys {@see ResponseStatusTargetEnumerator} accepts. */
    private static function isStatusKey(string $status): bool
    {
        return $status === 'default' ||
            preg_match('/^[1-5][0-9]{2}$/', $status) === 1 ||
            preg_match('/^[1-5](?:XX|xx)$/', $status) === 1;
    }

    /**
     * The media type the generated request actually sends.
     *
     * A spec key may be a *range* (`application/*`, `*&#47;*`) rather than a
     * media type. Ranges are legal on the spec side — the request validator
     * matches a concrete type against them — but a client cannot put one on
     * the wire, so the stub substitutes a concrete type the range covers.
     * Concrete keys, including `+json` suffixes, are sent verbatim: sending
     * `application/json` for a declared `application/vnd.acme+json` would not
     * match the spec's content map.
     */
    private static function wireMediaType(string $declared): string
    {
        $normalized = ContentTypeMatcher::normalizeMediaType($declared);
        if (!str_contains($normalized, '*')) {
            return $declared;
        }
        if ($normalized === '*/*' || $normalized === 'application/*') {
            return 'application/json';
        }

        [$type] = explode('/', $normalized, 2);

        return $type . '/plain';
    }

    private function isTrackedMethod(string $method, string $location): bool
    {
        return in_array($method, self::TRACKED_METHODS, true) ||
            str_starts_with($location, 'additionalOperations[');
    }

    /**
     * @param array<string, mixed> $operation
     * @param null|array<string, string> $states
     *
     * @return list<StubTuple>
     */
    private function tuples(string $method, string $path, array $operation, ?array $states): array
    {
        /** @var array<string, mixed> $responses */
        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $endpoint = $method . ' ' . $path;
        $wireStatuses = $this->wireStatuses($responses);
        $tuples = [];

        foreach ($responses as $status => $response) {
            $status = (string) $status;
            // A Responses Object may carry specification extensions. They are
            // not responses, so they get no stub — the same reading
            // ResponseStatusTargetEnumerator applies.
            if (str_starts_with($status, 'x-') || !is_array($response)) {
                continue;
            }

            $content = is_array($response['content'] ?? null) ? $response['content'] : [];
            if ($content === []) {
                $tuples[] = $this->tuple(
                    $endpoint,
                    $status,
                    OpenApiCoverageTracker::ANY_CONTENT_TYPE,
                    [],
                    $states,
                    $wireStatuses,
                );

                continue;
            }

            foreach ($content as $contentType => $media) {
                $tuples[] = $this->tuple(
                    $endpoint,
                    $status,
                    (string) $contentType,
                    is_array($media) ? $media : [],
                    $states,
                    $wireStatuses,
                );
            }
        }

        $tuples = array_values(array_filter($tuples, static fn(?array $tuple): bool => $tuple !== null));

        usort($tuples, static fn(array $a, array $b): int => strcmp(
            $a['status'] . "\x1f" . $a['content_type'],
            $b['status'] . "\x1f" . $b['content_type'],
        ));

        return $tuples;
    }

    /**
     * @param array<string, mixed> $media
     * @param null|array<string, string> $states
     * @param array<string, null|int> $wireStatuses
     *
     * @return null|StubTuple null when the tuple is already validated
     */
    private function tuple(
        string $endpoint,
        string $status,
        string $contentType,
        array $media,
        ?array $states,
        array $wireStatuses,
    ): ?array {
        if ($states !== null && ($states[$endpoint . "\x1f" . $status . "\x1f" . $contentType] ?? 'uncovered') === 'validated') {
            return null;
        }

        [$example, $hasExample] = $this->example($media);
        $statusCode = $wireStatuses[$status] ?? null;

        return [
            'status' => $status,
            'content_type' => $contentType,
            'status_code' => $statusCode,
            'is_range' => $statusCode !== null && (string) $statusCode !== $status,
            'reason' => match (true) {
                $statusCode !== null => 'ok',
                self::isStatusKey($status) => 'unreachable',
                default => 'malformed',
            },
            'example' => $example,
            'has_example' => $hasExample,
        ];
    }

    /**
     * The wire status that actually selects each declared response key.
     *
     * Reuses {@see ResponseStatusTargetEnumerator} rather than mapping `4XX`
     * to 400 directly: the runtime resolver prefers an exact key over a range
     * over `default`, so a spec declaring both `400` and `4XX` needs the range
     * stub to send some *other* 4xx code, otherwise the generated test would
     * silently validate the `400` schema. A key no wire status can reach
     * (`4XX` alongside all 100 exact 4xx codes) yields null and is dropped by
     * the caller with a report, because no test could ever cover it.
     *
     * @param array<string, mixed> $responses
     *
     * @return array<string, null|int>
     */
    private function wireStatuses(array $responses): array
    {
        // Only keys the enumerator accepts are handed to it. Letting it throw
        // on a malformed key (`"ok"`, `"20x"`) and swallowing the exception
        // would take the operation's valid keys down with it — the `200` next
        // to a typo'd `20x` would read as unreachable and never be stubbed.
        // The malformed key is classified separately in tuple().
        $declared = [];
        foreach (array_keys($responses) as $status) {
            $status = (string) $status;
            if (!str_starts_with($status, 'x-') && self::isStatusKey($status)) {
                $declared[$status] = $responses[$status];
            }
        }

        $wireStatuses = [];
        foreach (ResponseStatusTargetEnumerator::enumerate($declared) as $target) {
            $wireStatuses[$target['declaredStatusKey']] = $target['wireStatus'];
        }

        return $wireStatuses;
    }

    /**
     * The example a Media Type Object carries, preferring the singular
     * `example` over the first entry of `examples` (OpenAPI forbids both).
     *
     * @param array<string, mixed> $media
     *
     * @return array{mixed, bool}
     */
    private function example(array $media): array
    {
        if (array_key_exists('example', $media)) {
            return [$media['example'], true];
        }

        $examples = is_array($media['examples'] ?? null) ? $media['examples'] : [];
        foreach ($examples as $example) {
            if (is_array($example) && array_key_exists('value', $example)) {
                return [$example['value'], true];
            }
        }

        return [null, false];
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array{mixed, bool, null|string}
     */
    private function requestBody(array $operation): array
    {
        $requestBody = is_array($operation['requestBody'] ?? null) ? $operation['requestBody'] : null;
        if ($requestBody === null) {
            return [null, false, null];
        }

        $content = is_array($requestBody['content'] ?? null) ? $requestBody['content'] : [];
        if ($content === []) {
            return [null, false, null];
        }

        // A JSON media type keeps the generated call on the framework's JSON
        // helpers; anything else still gets a stub, just with its own type.
        // ContentTypeMatcher decides which is which, so the stub agrees with
        // the validator that will judge it — a substring test would read
        // `application/notjson` as JSON.
        $declared = ContentTypeMatcher::findJsonContentType($content) ?? (string) array_key_first($content);

        $media = $content[$declared] ?? null;
        [$example, $hasExample] = $this->example(is_array($media) ? $media : []);

        return [$hasExample ? $example : [], true, self::wireMediaType($declared)];
    }

    /**
     * Substitute every path template variable and append the required query
     * parameters, so the generated request line is one a client could send.
     *
     * @param list<array<string, mixed>> $parameters
     */
    private function requestPath(string $path, array $parameters): string
    {
        $query = [];

        foreach ($parameters as $parameter) {
            $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : null;
            if ($name === null) {
                continue;
            }

            if ($parameter['in'] === 'path') {
                $path = str_replace('{' . $name . '}', rawurlencode($this->placeholder($parameter)), $path);

                continue;
            }
            if ($parameter['in'] === 'query' && ($parameter['required'] ?? false) === true) {
                $query[] = rawurlencode($name) . '=' . rawurlencode($this->placeholder($parameter));
            }
        }

        return $query === [] ? $path : $path . '?' . implode('&', $query);
    }

    /**
     * @param list<array<string, mixed>> $parameters
     *
     * @return array<string, string>
     */
    private function requiredHeaders(array $parameters): array
    {
        $headers = [];

        foreach ($parameters as $parameter) {
            if ($parameter['in'] !== 'header' || ($parameter['required'] ?? false) !== true) {
                continue;
            }
            $name = is_string($parameter['name'] ?? null) ? $parameter['name'] : null;
            if ($name === null) {
                continue;
            }
            $headers[$name] = $this->placeholder($parameter);
        }

        ksort($headers);

        return $headers;
    }

    /**
     * A value for a parameter, in decreasing order of authority: the example
     * the spec gives, then what the schema pins down (`default`, `enum`), then
     * a type/format-shaped placeholder. `TODO` is the last resort and is meant
     * to be read as one.
     *
     * @param array<string, mixed> $parameter
     */
    private function placeholder(array $parameter): string
    {
        $schema = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : [];

        if (array_key_exists('example', $parameter)) {
            $scalar = $this->stringify($parameter['example']);
            if ($scalar !== null) {
                return $scalar;
            }
        }

        $examples = is_array($parameter['examples'] ?? null) ? $parameter['examples'] : [];
        foreach ($examples as $example) {
            if (!is_array($example) || !array_key_exists('value', $example)) {
                continue;
            }
            $scalar = $this->stringify($example['value']);
            if ($scalar !== null) {
                return $scalar;
            }
        }

        foreach (['example', 'default'] as $key) {
            if (array_key_exists($key, $schema)) {
                $scalar = $this->stringify($schema[$key]);
                if ($scalar !== null) {
                    return $scalar;
                }
            }
        }

        $enum = is_array($schema['enum'] ?? null) ? $schema['enum'] : [];
        foreach ($enum as $candidate) {
            $scalar = $this->stringify($candidate);
            if ($scalar !== null) {
                return $scalar;
            }
        }

        return $this->typedPlaceholder($schema);
    }

    /** @param array<string, mixed> $schema */
    private function typedPlaceholder(array $schema): string
    {
        // 3.1 allows a type array; the first entry that is not `null` decides.
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $type = null;
            foreach ($schema['type'] as $candidate) {
                if (is_string($candidate) && $candidate !== 'null') {
                    $type = $candidate;

                    break;
                }
            }
        }

        $format = is_string($schema['format'] ?? null) ? $schema['format'] : null;

        return match (true) {
            $type === 'integer' || $type === 'number' => '1',
            $type === 'boolean' => 'true',
            $format === 'uuid' => '00000000-0000-0000-0000-000000000000',
            $format === 'date' => '2026-01-01',
            $format === 'date-time' => '2026-01-01T00:00:00Z',
            default => 'TODO',
        };
    }

    /** Scalars only: an object or array example cannot go into a URL segment. */
    private function stringify(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
