<?php

declare(strict_types=1);

namespace Studio\Gesso\Stubs;

use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Validation\Request\ParameterCollector;
use Studio\Gesso\Validation\Request\RequestBodyValidator;
use Studio\Gesso\Validation\Response\ResponseStatusTargetEnumerator;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\FormBodyDecoder;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
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
 * @phpstan-type StubTuple array{status: string, content_type: string, wire_content_type: string, status_code: null|int, is_range: bool, reason: 'ok'|'unreachable'|'malformed', skip_notice: null|string, example: mixed, has_example: bool}
 * @phpstan-type StubRequestBody array{content_type: string, wire_content_type: string, body: mixed, skip_notice: null|string}
 * @phpstan-type StubOperation array{
 *     method: string,
 *     path: string,
 *     request_path: string,
 *     operation_id: null|string,
 *     summary: null|string,
 *     headers: array<string, string>,
 *     request_required: bool,
 *     request_candidates: list<StubRequestBody>,
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
                [$requestRequired, $requestCandidates] = $this->requestBody($operation);

                $plans[] = [
                    'method' => $method,
                    'path' => $path,
                    'request_path' => $this->requestPath($path, $parameters),
                    'operation_id' => is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null,
                    'summary' => is_string($operation['summary'] ?? null) ? $operation['summary'] : null,
                    'headers' => $this->requiredHeaders($parameters),
                    'request_required' => $requestRequired,
                    'request_candidates' => $requestCandidates,
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
     * The media type the generated request or response actually carries.
     *
     * A spec key may be a *range* (`application/*`, `*&#47;*`) rather than a
     * media type. Ranges are legal on the spec side — the validator matches a
     * concrete type against them — but a client cannot put one on the wire,
     * and a range Content-Type reads as non-JSON, which makes the body
     * validator skip the schema entirely.
     *
     * The substitute is checked against the runtime resolver rather than
     * guessed: whatever is sent must come back as *this* key, or the stub
     * would validate a sibling's schema and leave its own tuple uncovered.
     *
     * Which resolver decides that depends on the *substitute*, not on the
     * declared key: the runtime routes a JSON-flavoured Content-Type through
     * {@see ContentTypeMatcher::findJsonContentTypeForResponse()} and anything
     * else through {@see ContentTypeMatcher::findContentTypeKey()}. The two
     * disagree on ranges — the JSON resolver skips `<type>/*` whenever a
     * literal JSON key exists, while the general one matches it in its second
     * pass. So a range shadowed by a JSON sibling is still reachable through a
     * non-JSON Content-Type, which is only worth sending when the key declares
     * no `schema`: with one, that route ends in the "non-JSON media type
     * declaring a schema this engine cannot evaluate" skip instead.
     *
     * The request side differs twice. Its JSON route is
     * {@see ContentTypeMatcher::findJsonContentType()} with no exact-match
     * preference, so only the first JSON key (or `application/*`) is ever
     * selected by a JSON Content-Type. And forms are the one non-JSON family
     * {@see RequestBodyValidator} still checks against a schema, which makes a
     * form Content-Type worth trying for a schema-carrying range like
     * `multipart/*` — unreachable through every other route.
     *
     * Null means no media type can select the key at all — a `<type>/*` range
     * carrying a schema and declared next to a literal JSON key is the case:
     * the JSON route resolves to the sibling and the non-JSON route cannot
     * validate. That is the media-type twin of a `4XX` declared alongside every
     * exact 4xx code, and the caller reports those instead of stubbing them.
     *
     * @param array<string, mixed> $siblings the content map the key belongs to
     * @param bool $forRequest resolve the way RequestBodyValidator does rather
     *                         than ResponseSchemaResolver
     */
    private static function wireMediaType(string $declared, array $siblings, bool $forRequest = false): ?string
    {
        $normalized = ContentTypeMatcher::normalizeMediaType($declared);
        $hasSchema = isset($siblings[$declared]['schema']);
        $candidates = [$declared, 'application/json'];

        // Every way a made-up type can lose to a sibling goes through an exact
        // key match or a `<type>/*` one, so one more attempt than there are
        // siblings — on both halves of the media type — always leaves an
        // unclaimed name. A fixed handful does not: a document declaring
        // `application/vnd.gesso-stub` through `-stub3` would make the range
        // next to them read as unreachable when `application/xml` selects it
        // perfectly well.
        $attempts = count($siblings) + 1;
        $prefixes = self::primaryTypes($normalized, $attempts);
        $stubTypes = static function (string $suffix) use ($prefixes, $attempts): array {
            $types = [];
            foreach ($prefixes as $prefix) {
                for ($i = 0; $i < $attempts; $i++) {
                    $types[] = $prefix . '/vnd.gesso-stub' . ($i === 0 ? '' : (string) $i) . $suffix;
                }
            }

            return $types;
        };

        $candidates = array_merge($candidates, $stubTypes('+json'));

        if ($forRequest && $hasSchema) {
            $candidates[] = FormBodyDecoder::URLENCODED;
            $candidates[] = FormBodyDecoder::MULTIPART;
        }

        if (!$hasSchema) {
            $candidates = array_merge($candidates, $stubTypes(''));
        }

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '*')) {
                // A range cannot go on the wire, and reads as non-JSON, which
                // makes the body validator skip the schema entirely.
                continue;
            }
            $normalizedCandidate = ContentTypeMatcher::normalizeMediaType($candidate);
            $matched = match (true) {
                !ContentTypeMatcher::isJsonContentType($normalizedCandidate) => ContentTypeMatcher::findContentTypeKey($normalizedCandidate, $siblings),
                $forRequest => ContentTypeMatcher::findJsonContentType($siblings),
                default => ContentTypeMatcher::findJsonContentTypeForResponse($normalizedCandidate, $siblings),
            };
            if ($matched === $declared) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The top-level types a substitute for `$normalized` may be built under.
     *
     * A concrete key pins its own — only `<type>/<subtype>` matches
     * `<type>/*`. `*&#47;*` does not: it is only reached by
     * {@see ContentTypeMatcher::findContentTypeKey()}'s third pass, so a
     * sibling `application/*` claims every `application/…` candidate before it
     * gets there, and a document ranging over both `application/*` and
     * `text/*` needs something like `image/…` to reach the full wildcard at
     * all. Registered top-level types come first because a generated stub
     * reads better with one; `x-gesso-stub…` only appears for a content map
     * that somehow ranges over every one of them.
     *
     * @return list<string>
     */
    private static function primaryTypes(string $normalized, int $attempts): array
    {
        [$type] = explode('/', $normalized, 2);
        if ($type !== '*' && $type !== '') {
            return [$type];
        }

        // `multipart` and `message` are left out: both carry framing a stub
        // would have to fabricate for the Content-Type not to be a lie.
        $types = ['application', 'text', 'image', 'audio', 'video', 'font', 'model'];
        for ($i = count($types); $i < $attempts; $i++) {
            $types[] = 'x-gesso-stub' . $i;
        }

        return $types;
    }

    /**
     * Why a request body or a response tuple can only ever validate as Skipped,
     * or null when the stub really does exercise a schema.
     *
     * The generated assertions are satisfied by a Skipped result, so without
     * this the test would read as finished while the coverage document keeps
     * reporting the tuple as skipped rather than validated. Both cases are
     * properties of the spec, not of the stub: OpenAPI 3.2 `itemSchema`
     * streaming cannot be checked from a buffered body, and a non-JSON media
     * type carrying a `schema` is a contract this JSON Schema engine does not
     * evaluate — the one exception being a form request body, which
     * {@see RequestBodyValidator} still checks field by field. The stub is
     * still worth writing — it exercises the endpoint and moves the tuple off
     * `uncovered` — but the reason belongs in the file rather than in a
     * surprise.
     *
     * @param array<string, mixed> $media the declared media type entry
     */
    private static function skipNotice(string $wireContentType, array $media, bool $forRequest = false): ?string
    {
        $subject = $forRequest ? 'this request body' : 'this response';
        $itemSchema = 'the spec declares `itemSchema` here, and stream items cannot be validated '
            . 'from a buffered body, so ' . $subject . ' can only validate as Skipped';
        $normalized = ContentTypeMatcher::normalizeMediaType($wireContentType);

        if (ContentTypeMatcher::isJsonContentType($normalized)) {
            // The JSON route reads `schema` first and only falls back to the
            // streaming skip when there is none.
            return isset($media['schema']) || !isset($media['itemSchema']) ? null : $itemSchema;
        }

        // The non-JSON route checks `itemSchema` first, then rejects any other
        // schema as unevaluatable — bar a form body, whose fields are coerced
        // and validated against the declared schema.
        return match (true) {
            isset($media['itemSchema']) => $itemSchema,
            !isset($media['schema']) => null,
            $forRequest && FormBodyDecoder::isFormMediaType($normalized) => null,
            default => 'a non-JSON media type declaring a `schema` is a contract this validator '
                . 'cannot evaluate, so ' . $subject . ' can only validate as Skipped',
        };
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
                    $content,
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
     * @param array<string, mixed> $siblings
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
        array $siblings,
        ?array $states,
        array $wireStatuses,
    ): ?array {
        if ($states !== null && ($states[$endpoint . "\x1f" . $status . "\x1f" . $contentType] ?? 'uncovered') === 'validated') {
            return null;
        }

        [$example, $hasExample] = $this->example($media);
        $statusCode = $wireStatuses[$status] ?? null;
        $wireContentType = $contentType === OpenApiCoverageTracker::ANY_CONTENT_TYPE
            ? $contentType
            : self::wireMediaType($contentType, $siblings);

        return [
            'status' => $status,
            // The declared key names the tuple — it is what the coverage
            // document reports and what the generated method is named after.
            // What goes on the wire may differ: a range key is not a media
            // type a response can carry, and sending one makes the validator
            // read the body as non-JSON and skip the schema entirely.
            'content_type' => $contentType,
            'wire_content_type' => $wireContentType ?? $contentType,
            'status_code' => $statusCode,
            'is_range' => $statusCode !== null && (string) $statusCode !== $status,
            'reason' => match (true) {
                $statusCode !== null && $wireContentType !== null => 'ok',
                $statusCode !== null => 'unreachable',
                self::isStatusKey($status) => 'unreachable',
                default => 'malformed',
            },
            'skip_notice' => $wireContentType === null || $contentType === OpenApiCoverageTracker::ANY_CONTENT_TYPE
                ? null
                : self::skipNotice($wireContentType, $media),
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
     * @return array{bool, list<StubRequestBody>}
     */
    private function requestBody(array $operation): array
    {
        $requestBody = is_array($operation['requestBody'] ?? null) ? $operation['requestBody'] : null;
        if ($requestBody === null) {
            return [false, []];
        }

        $content = is_array($requestBody['content'] ?? null) ? $requestBody['content'] : [];
        if ($content === []) {
            return [false, []];
        }

        // Every declared media type is kept: which one a stub can actually send
        // depends on the adapter and the HTTP method — a decision this class is
        // deliberately blind to — and an operation offering both multipart and
        // urlencoded is stubbable through the latter even where the former is
        // not.
        $json = ContentTypeMatcher::findJsonContentType($content);
        $candidates = [];
        foreach (array_keys($content) as $key) {
            $key = (string) $key;
            $media = is_array($content[$key] ?? null) ? $content[$key] : [];
            [$example, $hasExample] = $this->example($media);
            $wire = self::wireMediaType($key, $content, forRequest: true);
            if ($wire === null) {
                continue;
            }
            $candidates[] = [
                'content_type' => $key,
                'wire_content_type' => $wire,
                'body' => $hasExample ? $example : [],
                'skip_notice' => self::skipNotice($wire, $media, forRequest: true),
            ];
        }

        // Most-expressible first, but a media type the validator actually
        // checks outranks all of them: an operation declaring both
        // `application/xml` and `multipart/form-data` should be stubbed
        // through the form, whose schema is enforced, not through the XML that
        // validates as Skipped whatever the body is.
        $rank = static fn(array $candidate): array => [
            $candidate['skip_notice'] === null ? 0 : 1,
            match (true) {
                $candidate['content_type'] === $json => 0,
                ContentTypeMatcher::normalizeMediaType($candidate['content_type']) === FormBodyDecoder::URLENCODED => 1,
                default => 2,
            },
        ];
        usort($candidates, static fn(array $a, array $b): int => $rank($a) <=> $rank($b));

        return [($requestBody['required'] ?? false) === true, $candidates];
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
