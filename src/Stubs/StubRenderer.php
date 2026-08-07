<?php

declare(strict_types=1);

namespace Studio\Gesso\Stubs;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use InvalidArgumentException;
use JsonException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;

use function array_filter;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function explode;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sort;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function ucfirst;
use function var_export;
use function wordwrap;

/**
 * Renders one {@see StubGenerator} plan as a test class (or, for Pest, a test
 * file) written in the idiom of the matching quickstart, so generated code
 * looks like the documented usage rather than a fifth dialect.
 *
 * Every generated test starts with an incomplete/todo marker: a freshly
 * scaffolded suite must not turn a green build red, and removing the marker is
 * the one edit that tells the user they have finished filling the stub in.
 *
 * @phpstan-import-type StubOperation from StubGenerator
 * @phpstan-import-type StubRequestBody from StubGenerator
 * @phpstan-import-type StubTuple from StubGenerator
 *
 * @internal The `gesso stubs` / `gesso:stubs` CLI surface is the supported API.
 */
final class StubRenderer
{
    public const ADAPTERS = ['phpunit', 'laravel', 'symfony', 'pest'];

    /** Base test class each adapter's quickstart extends. */
    public const DEFAULT_BASE_CLASSES = [
        'phpunit' => 'PHPUnit\Framework\TestCase',
        'laravel' => 'Tests\TestCase',
        'symfony' => 'PHPUnit\Framework\TestCase',
        'pest' => '',
    ];

    /**
     * Pest generates Laravel HTTP calls, so its files have to land where the
     * project's `uses(TestCase::class, ValidatesOpenApiSchema::class)->in(...)`
     * binding reaches them — `Feature`, per docs/pest-plugin.md. Dropped under
     * `tests/Contract` they would parse but have no harness the moment the
     * `->todo()` came off.
     */
    public const DEFAULT_OUTPUT_DIRS = [
        'phpunit' => 'tests/Contract',
        'laravel' => 'tests/Feature/Contract',
        'symfony' => 'tests/Contract',
        'pest' => 'tests/Feature/Contract',
    ];

    public const DEFAULT_NAMESPACES = [
        'phpunit' => 'Tests\Contract',
        'laravel' => 'Tests\Feature\Contract',
        'symfony' => 'Tests\Contract',
        'pest' => '',
    ];

    public function __construct(
        private readonly string $adapter,
        private readonly string $specName,
        private readonly string $namespace,
        private readonly string $baseClass,
    ) {
        if (!in_array($adapter, self::ADAPTERS, true)) {
            throw new InvalidArgumentException("Unsupported adapter: {$adapter}");
        }
    }

    /**
     * Resolve one file-name-safe class name per plan, disambiguating the
     * collisions {@see self::className()} can produce.
     *
     * `GET /foo-bar` and `GET /foo/bar` both studly-case to `GetFooBarTest`.
     * Left alone the second plan would hit the never-overwrite guard and be
     * reported as an untouched existing file, quietly dropping an uncovered
     * operation. Every member of a colliding group is suffixed with a digest
     * of its own endpoint — not with its position — so a name does not shift
     * when an unrelated operation is added to or removed from the spec.
     *
     * @param list<StubOperation> $plans
     *
     * @return list<string> parallel to $plans
     */
    public static function classNames(array $plans): array
    {
        $counts = [];
        foreach ($plans as $plan) {
            $name = self::className($plan['method'], $plan['path']);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        $names = [];
        foreach ($plans as $plan) {
            $name = self::className($plan['method'], $plan['path']);
            $names[] = ($counts[$name] ?? 0) > 1
                ? substr($name, 0, -4) . ucfirst(substr(
                    hash('xxh128', $plan['method'] . ' ' . $plan['path']),
                    0,
                    7,
                )) . 'Test'
                : $name;
        }

        return $names;
    }

    /**
     * `GET /v1/pets/{petId}` becomes `GetV1PetsPetIdTest`. Derived from the
     * method and path rather than `operationId` so the name is unique by
     * construction — `(method, path)` is unique in a document, `operationId`
     * is only unique when the author kept it so. Collisions this can still
     * produce are resolved by {@see self::classNames()}.
     */
    public static function className(string $method, string $path): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $path) ?: [];
        // The method is a constant shouted in the spec; path segments carry the
        // author's own casing (`{petId}` → `PetId`), so only the method is
        // folded before capitalising.
        $studly = ucfirst(strtolower($method));
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $studly .= ucfirst($word);
        }

        return ($studly === '' ? 'Operation' : $studly) . 'Test';
    }

    /**
     * Why this adapter cannot express the operation's request, or null when it
     * can.
     *
     * A multipart body on an `additionalOperations` method is the usual case:
     * Request::create() routes its parameters argument to the query bag for a
     * non-standard method, and multipart — unlike urlencoded — has no raw-byte
     * form FormBodyDecoder can parse back, so neither the field bag nor the
     * body can be populated. Emitting a stub anyway would produce a request
     * whose requestBody silently validates as Skipped. The other case is a
     * body whose every declared media type the runtime resolves to something
     * else, leaving nothing to send. The phpunit adapter is unaffected by
     * either: it only validates responses and never builds a request.
     *
     * @param StubOperation $operation
     */
    public function unsupportedReason(array $operation): ?string
    {
        // The core adapter never builds a request, so no requestBody — however
        // it is declared — can make an operation unstubbable for it.
        if ($this->adapter === 'phpunit') {
            return null;
        }

        // Only a *required* body with no expressible media type is a dead
        // end. An optional one can simply be omitted, and an operation that
        // also offers urlencoded is stubbable through that.
        if (!$operation['request_required'] || $this->selectRequestBody($operation) !== null) {
            return null;
        }

        $declared = array_map(
            static fn(array $candidate): string => $candidate['content_type'],
            $operation['request_candidates'],
        );

        if ($declared === []) {
            return 'its required body declares no media type a client could send and have the '
                . 'validator resolve back to it';
        }

        return sprintf(
            'its required %s body cannot be built through Request::create(), which routes '
            . 'parameters to the query bag for non-standard methods like %s',
            implode(' / ', $declared),
            $operation['method'],
        );
    }

    /** @param StubOperation $operation */
    public function render(array $operation, string $className): string
    {
        return match ($this->adapter) {
            'pest' => $this->renderPest($operation),
            default => $this->renderClass($operation, $className),
        };
    }

    /**
     * The first declared request media type this adapter can actually put on
     * the wire, or null when the operation declares no body or none of its
     * media types is expressible. Only the request-building adapters ask; the
     * core one validates responses alone and never calls this.
     *
     * @param StubOperation $operation
     *
     * @return null|StubRequestBody
     */
    private function selectRequestBody(array $operation): ?array
    {
        foreach ($operation['request_candidates'] as $candidate) {
            $normalized = ContentTypeMatcher::normalizeMediaType($candidate['content_type']);
            if (!str_starts_with($normalized, 'multipart/') ||
                $this->parametersReachTheRequestBag($operation['method'])) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param StubOperation $operation */
    private function renderClass(array $operation, string $className): string
    {
        $imports = match ($this->adapter) {
            'phpunit' => [
                'Studio\Gesso\OpenApiResponseValidator',
                'Studio\Gesso\Validation\Strict\StrictRequiredTracker',
            ],
            'laravel' => [
                'Studio\Gesso\Attribute\OpenApiSpec',
                'Studio\Gesso\Laravel\ValidatesOpenApiSchema',
            ],
            default => [
                'Studio\Gesso\Attribute\OpenApiSpec',
                'Studio\Gesso\Symfony\OpenApiAssertions',
                'Symfony\Component\HttpFoundation\Request',
                'Symfony\Component\HttpFoundation\Response',
            ],
        };

        $baseShortName = $this->shortName($this->baseClass);
        if (str_contains($this->baseClass, '\\')) {
            $imports[] = ltrim($this->baseClass, '\\');
        }
        sort($imports);

        $lines = ['<?php', '', 'declare(strict_types=1);', ''];
        if ($this->namespace !== '') {
            $lines[] = 'namespace ' . $this->namespace . ';';
            $lines[] = '';
        }
        foreach ($imports as $import) {
            $lines[] = 'use ' . $import . ';';
        }
        $lines[] = '';
        $lines = array_merge($lines, $this->docblock($operation));

        if ($this->adapter !== 'phpunit') {
            $lines[] = sprintf('#[OpenApiSpec(%s)]', $this->literal($this->specName));
        }
        $lines[] = sprintf('final class %s extends %s', $className, $baseShortName);
        $lines[] = '{';

        $trait = match ($this->adapter) {
            'laravel' => 'ValidatesOpenApiSchema',
            'symfony' => 'OpenApiAssertions',
            default => null,
        };
        if ($trait !== null) {
            $lines[] = '    use ' . $trait . ';';
            $lines[] = '';
        }

        $first = true;
        foreach ($this->methodNames($operation) as $index => $methodName) {
            if (!$first) {
                $lines[] = '';
            }
            $first = false;
            $lines[] = sprintf('    public function %s(): void', $methodName);
            $lines[] = '    {';
            $lines = array_merge($lines, $this->indent($this->methodBody($operation, $operation['tuples'][$index]), 2));
            $lines[] = '    }';
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    /** @param StubOperation $operation */
    private function renderPest(array $operation): string
    {
        $lines = ['<?php', '', 'declare(strict_types=1);', ''];
        $lines = array_merge($lines, $this->docblock($operation));

        foreach ($operation['tuples'] as $tuple) {
            $lines[] = '';
            $lines[] = sprintf(
                'it(%s, function (): void {',
                $this->literal($this->describeTuple($operation, $tuple)),
            );
            $lines = array_merge($lines, $this->indent($this->pestBody($operation, $tuple), 1));
            // `todo()` skips the test and lists it as outstanding, so a freshly
            // generated suite reports work to do instead of failures.
            $lines[] = '})->todo();';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Indent a body, splitting the multi-line array literals a body line can
     * carry so every physical line lands at the right column.
     *
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function indent(array $lines, int $levels): array
    {
        $pad = str_repeat('    ', $levels);
        $indented = [];

        foreach ($lines as $line) {
            foreach (explode("\n", $line) as $physical) {
                $indented[] = $physical === '' ? '' : $pad . $physical;
            }
        }

        return $indented;
    }

    /**
     * @param StubOperation $operation
     *
     * @return list<string>
     */
    private function docblock(array $operation): array
    {
        $subject = sprintf(
            '`%s %s`',
            $this->commentSafe($operation['method']),
            $this->commentSafe($operation['path']),
        );
        if ($operation['operation_id'] !== null) {
            $subject .= sprintf(' (operationId `%s`)', $this->commentSafe($operation['operation_id']));
        }

        $lines = ['/**', ' * Contract stub for ' . $subject . '.'];
        if ($operation['summary'] !== null && trim($operation['summary']) !== '') {
            $lines[] = ' *';
            foreach ($this->commentBody($operation['summary']) as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = ' *';
        $lines[] = ' * Generated by `gesso stubs`: no test validated the responses below.';
        $lines[] = $this->adapter === 'pest'
            ? ' * Fill in each TODO and drop the `->todo()` call to enable the test.'
            : ' * Fill in each TODO and delete the `markTestIncomplete()` call.';
        if ($this->adapter === 'pest') {
            $lines[] = ' *';
            $lines[] = ' * These calls need the Laravel harness: bind this directory with';
            $lines[] = ' * `uses(TestCase::class, ValidatesOpenApiSchema::class)` in tests/Pest.php.';
        }
        $lines[] = ' */';

        return $lines;
    }

    /**
     * Neutralise a spec string bound for a docblock. A legitimate `summary`
     * containing `*​/` would otherwise close the comment early and leave the
     * generated file a syntax error.
     */
    private function commentSafe(string $value): string
    {
        return str_replace(['*/', "\r"], ['*\\/', ''], $value);
    }

    /**
     * A multi-line spec string as docblock lines, each carrying its own ` * `
     * prefix so an embedded newline cannot escape the comment.
     *
     * @return list<string>
     */
    private function commentBody(string $value): array
    {
        $lines = [];
        foreach (explode("\n", $this->commentSafe(trim($value))) as $line) {
            $line = rtrim($line);
            $lines[] = $line === '' ? ' *' : ' * ' . $line;
        }

        return $lines;
    }

    /**
     * Unique snake_case method names, one per tuple.
     *
     * @param StubOperation $operation
     *
     * @return list<string>
     */
    private function methodNames(array $operation): array
    {
        $base = $this->snake($operation['method'] . ' ' . $operation['path']);
        $names = [];
        $seen = [];

        foreach ($operation['tuples'] as $tuple) {
            $suffix = $this->snake($tuple['status']) . '_' . (
                $tuple['content_type'] === OpenApiCoverageTracker::ANY_CONTENT_TYPE
                    ? 'no_content'
                    : $this->snake($tuple['content_type'])
            );
            $name = 'test_' . $base . '_' . $suffix;

            // Two content types can sanitize to the same identifier
            // (`application/json` and `application+json`); keep every tuple
            // addressable rather than dropping one.
            $count = $seen[$name] ?? 0;
            $seen[$name] = $count + 1;
            $names[] = $count === 0 ? $name : $name . '_' . ($count + 1);
        }

        return $names;
    }

    /**
     * @param StubOperation $operation
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function methodBody(array $operation, array $tuple): array
    {
        $lines = [sprintf(
            '$this->markTestIncomplete(%s);',
            $this->literal('Exercise ' . $this->describeTuple($operation, $tuple) . '.'),
        ), ''];

        return array_merge($lines, $this->tupleNotes($operation, $tuple), match ($this->adapter) {
            'phpunit' => $this->phpunitBody($operation, $tuple),
            'laravel' => $this->laravelBody($operation, $tuple),
            default => $this->symfonyBody($operation, $tuple),
        });
    }

    /**
     * @param StubOperation $operation
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function phpunitBody(array $operation, array $tuple): array
    {
        $noContent = $tuple['content_type'] === OpenApiCoverageTracker::ANY_CONTENT_TYPE;
        $lines = [];

        if ($operation['headers'] !== []) {
            $lines[] = '// Required request headers: ' . implode(', ', array_keys($operation['headers'])) . '.';
        }

        if ($noContent) {
            $body = 'null';
        } else {
            $lines[] = $tuple['has_example']
                ? "// Taken from the spec's example; replace it with what your application returns."
                : '// TODO: replace with the body your application returns.';
            $lines[] = '$body = ' . $this->literal($tuple['has_example'] ? $tuple['example'] : []) . ';';
            $lines[] = '';
            $body = '$body';
        }

        $arguments = [
            $this->literal($this->specName),
            $this->literal($operation['method']),
            $this->literal($operation['path']),
            (string) $tuple['status_code'],
            $body,
        ];
        if (!$noContent) {
            // The wire value, not the declared key: a range like
            // `application/*` reads as non-JSON and would skip the schema.
            $arguments[] = $this->literal($tuple['wire_content_type']);
        }

        $lines[] = '$result = (new OpenApiResponseValidator(new StrictRequiredTracker()))->validate(';
        foreach ($arguments as $argument) {
            $lines[] = '    ' . $argument . ',';
        }
        $lines[] = ');';
        $lines[] = '';
        $lines[] = 'self::assertTrue($result->isValid(), $result->errorMessage());';

        return $lines;
    }

    /**
     * @param StubOperation $operation
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function laravelBody(array $operation, array $tuple): array
    {
        $headers = $operation['headers'];

        // Steer content negotiation at the tuple under test. `getJson()` and
        // friends pin `Accept: application/json`, so without this every media
        // type declared under one status would resolve to the same response
        // and only one of the generated tests could ever be meaningful. The
        // helpers array_merge caller headers over their defaults, so an
        // explicit Accept wins.
        if ($tuple['content_type'] !== OpenApiCoverageTracker::ANY_CONTENT_TYPE) {
            $headers['Accept'] = $tuple['wire_content_type'];
        }

        $lines = [];
        $body = $this->selectRequestBody($operation);
        $contentType = $body === null ? '' : $body['wire_content_type'];

        // The JSON helpers take an array and encode it themselves. A scalar or
        // null JSON example, or a non-JSON media type, has to go through
        // another call shape — passing either to postJson() is a TypeError or
        // a body sent under the wrong Content-Type.
        $usesJsonHelper = $body === null || (
            $this->isJsonMediaType($contentType) && is_array($body['body'])
        );

        // A form body goes through the plain helpers, which hand the array to
        // Symfony as request parameters — which is what the framework, and
        // FormBodyDecoder / HttpFoundationFormBody reading the decoded
        // parameter bag, expect. JSON-encoding it under a form Content-Type
        // would produce a request no decoder can read.
        if (!$usesJsonHelper && is_array($body['body']) && $this->isFormMediaType($contentType)) {
            return array_merge(
                $this->parametersReachTheRequestBag($operation['method'])
                    ? $this->laravelFormBody($operation, $body['body'], $headers, $contentType)
                    : $this->laravelCustomMethodFormBody($operation, $body['body'], $headers, $contentType),
                $this->laravelAssertions($tuple),
            );
        }

        if (!$usesJsonHelper) {
            $headers['Content-Type'] = $contentType;
            $lines[] = '// TODO: adjust the payload your application expects.';
            $lines[] = '$payload = ' . $this->literal($this->rawBody($body['body'], $contentType)) . ';';
            $lines[] = '';
            $lines[] = '$response = $this->call(';
            $lines[] = '    ' . $this->literal($operation['method']) . ',';
            $lines[] = '    ' . $this->literal($operation['request_path']) . ',';
            $lines[] = '    [],';
            $lines[] = '    [],';
            $lines[] = '    [],';
            $lines[] = '    $this->transformHeadersToServerVars(' . $this->literal($headers, 1) . '),';
            $lines[] = '    $payload,';
            $lines[] = ');';

            return array_merge($lines, $this->laravelAssertions($tuple));
        }

        // `getJson()` is the one helper whose second parameter is $headers
        // rather than $data, so a GET carrying a body has to go through
        // `json()` instead.
        $helper = match (true) {
            $operation['method'] === 'GET' && $body === null => 'getJson',
            $operation['method'] === 'POST' => 'postJson',
            $operation['method'] === 'PUT' => 'putJson',
            $operation['method'] === 'PATCH' => 'patchJson',
            $operation['method'] === 'DELETE' => 'deleteJson',
            default => null,
        };

        if ($body !== null) {
            // json() defaults CONTENT_TYPE to application/json, which does not
            // match a declared `application/vnd.acme+json` — the caller's
            // headers are merged over the defaults, so declaring it wins.
            $headers['Content-Type'] = $contentType;
            $lines[] = '// TODO: adjust the payload your application expects.';
            $lines[] = '$payload = ' . $this->literal($body['body']) . ';';
            $lines[] = '';
        }

        $arguments = $helper === null
            ? [$this->literal($operation['method']), $this->literal($operation['request_path'])]
            : [$this->literal($operation['request_path'])];

        // Every helper but getJson() takes $data before $headers; the slot has
        // to be filled even when the operation declares no request body.
        if ($helper !== 'getJson') {
            $arguments[] = $body === null ? '[]' : '$payload';
        }
        if ($headers !== []) {
            $arguments[] = $this->literal($headers, 1);
        }

        $lines[] = sprintf('$response = $this->%s(', $helper ?? 'json');
        foreach ($arguments as $argument) {
            $lines[] = '    ' . $argument . ',';
        }
        $lines[] = ');';

        return array_merge($lines, $this->laravelAssertions($tuple));
    }

    /**
     * The plain (non-JSON) helpers, which send an array as request parameters.
     * Symfony's Request::create sets `application/x-www-form-urlencoded` for
     * them, and Laravel promotes the request to `multipart/form-data` as soon
     * as the array holds UploadedFile instances — which is why the multipart
     * stub points at those rather than hand-building a boundary.
     *
     * @param StubOperation $operation
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function laravelFormBody(array $operation, mixed $fields, array $headers, string $contentType): array
    {
        // Request::create() defaults the write methods to
        // application/x-www-form-urlencoded and leaves PATCH without a
        // Content-Type at all, and putting an UploadedFile in the array does
        // not change the header. Declaring it is the only thing that makes a
        // multipart operation validate against its own requestBody.
        $headers['Content-Type'] = $contentType;

        $lines = [];
        if (str_starts_with(ContentTypeMatcher::normalizeMediaType($contentType), 'multipart/')) {
            $lines[] = '// TODO: replace each file part with an Illuminate\\Http\\UploadedFile.';
        } else {
            $lines[] = '// TODO: adjust the fields your application expects.';
        }
        $lines[] = '$fields = ' . $this->literal($fields) . ';';
        $lines[] = '';

        $helper = match ($operation['method']) {
            'POST' => 'post',
            'PUT' => 'put',
            'PATCH' => 'patch',
            'DELETE' => 'delete',
            default => null,
        };

        $arguments = $helper === null
            ? [$this->literal($operation['method']), $this->literal($operation['request_path']), '$fields']
            : [$this->literal($operation['request_path']), '$fields'];
        // $headers always carries the declared Content-Type by this point.
        $arguments[] = $helper === null
            ? '[],' . "\n" . '    [],' . "\n" . '    $this->transformHeadersToServerVars(' . $this->literal($headers, 1) . ')'
            : $this->literal($headers, 1);

        $lines[] = sprintf('$response = $this->%s(', $helper ?? 'call');
        foreach ($arguments as $argument) {
            $lines[] = '    ' . $argument . ',';
        }
        $lines[] = ');';

        return $lines;
    }

    /**
     * Custom methods cannot carry form fields as request parameters:
     * Request::create() only moves the third argument into the request bag for
     * POST/PUT/PATCH/DELETE/QUERY and routes everything else — an
     * `additionalOperations` `COPY` or `FETCH` — into the query bag, leaving
     * the body empty. A urlencoded body survives as raw bytes, which
     * FormBodyDecoder parses back with parse_str(); multipart has no such
     * round trip, so the stub says what has to be built by hand.
     *
     * @param StubOperation $operation
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function laravelCustomMethodFormBody(array $operation, mixed $fields, array $headers, string $contentType): array
    {
        $headers['Content-Type'] = $contentType;

        // Multipart never reaches here: unsupportedReason() takes those
        // operations out before rendering, because urlencoded bytes under a
        // multipart Content-Type is a request no decoder can read.
        $lines = ['// TODO: adjust the fields your application expects.'];
        $lines[] = '// Sent as a raw urlencoded body: Request::create() routes parameters';
        $lines[] = sprintf('// to the query bag for %s, so they would never reach the body.', $operation['method']);
        $lines[] = '$payload = ' . $this->literal($this->urlencode($fields)) . ';';
        $lines[] = '';
        $lines[] = '$response = $this->call(';
        $lines[] = '    ' . $this->literal($operation['method']) . ',';
        $lines[] = '    ' . $this->literal($operation['request_path']) . ',';
        $lines[] = '    [],';
        $lines[] = '    [],';
        $lines[] = '    [],';
        $lines[] = '    $this->transformHeadersToServerVars(' . $this->literal($headers, 1) . '),';
        $lines[] = '    $payload,';
        $lines[] = ');';

        return $lines;
    }

    /**
     * Only POST/PUT/PATCH/DELETE/QUERY put Request::create()'s third argument
     * into the request bag; every other method, including GET, gets it as
     * query parameters.
     */
    private function parametersReachTheRequestBag(string $method): bool
    {
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE', 'QUERY'], true);
    }

    private function urlencode(mixed $fields): string
    {
        return is_array($fields) ? http_build_query($fields) : (string) $this->encode($fields);
    }

    private function isFormMediaType(string $contentType): bool
    {
        $normalized = ContentTypeMatcher::normalizeMediaType($contentType);

        return $normalized === 'application/x-www-form-urlencoded' || str_starts_with($normalized, 'multipart/');
    }

    /**
     * The runtime's own reading of "is this JSON" — `application/json` or a
     * `+json` suffix. A substring test would take `application/notjson` for
     * JSON and generate a postJson() call the validator then rejects.
     */
    private function isJsonMediaType(string $contentType): bool
    {
        return ContentTypeMatcher::isJsonContentType(ContentTypeMatcher::normalizeMediaType($contentType));
    }

    /**
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function laravelAssertions(array $tuple): array
    {
        return [
            '',
            sprintf('$response->assertStatus(%d);', (int) $tuple['status_code']),
            '$this->assertResponseMatchesOpenApiSchema($response);',
        ];
    }

    /**
     * @param StubOperation $operation
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function symfonyBody(array $operation, array $tuple): array
    {
        $body = $this->selectRequestBody($operation);
        $contentType = $body === null ? '' : $body['wire_content_type'];
        // A form body belongs in $parameters, not $content: HttpFoundationFormBody
        // reads `$request->request->all()`, and Request::create only fills that
        // bag from the parameters argument. It also sets the urlencoded
        // Content-Type for the write methods, so the media type stays right.
        // Request::create() only moves $parameters into the request bag for
        // POST/PUT/PATCH/DELETE/QUERY; a custom method would silently get them
        // as query parameters and an empty body, so it falls through to the
        // raw urlencoded body FormBodyDecoder can still parse.
        $isForm = $body !== null &&
            is_array($body['body']) &&
            $this->isFormMediaType($contentType) &&
            $this->parametersReachTheRequestBag($operation['method']);

        $arguments = [
            $this->literal($operation['request_path']),
            $this->literal($operation['method']),
        ];
        if ($isForm) {
            $arguments[] = 'parameters: ' . $this->literal($body['body'], 1);
        }
        if ($operation['headers'] !== [] || $body !== null) {
            $server = [];
            foreach ($operation['headers'] as $name => $value) {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            }
            if ($body !== null) {
                // Declared for the form branch too: Request::create() would
                // otherwise default it to urlencoded (or, for PATCH, leave it
                // unset), and a multipart operation would never match its own
                // requestBody.
                $server['CONTENT_TYPE'] = $contentType;
            }
            $arguments[] = 'server: ' . $this->literal($server, 1);
        }
        if ($body !== null && !$isForm) {
            $arguments[] = 'content: ' . $this->literal($this->rawBody($body['body'], $contentType));
        }

        $lines = ['$request = Request::create('];
        foreach ($arguments as $argument) {
            $lines[] = '    ' . $argument . ',';
        }
        $lines[] = ');';
        $lines[] = '';

        $noContent = $tuple['content_type'] === OpenApiCoverageTracker::ANY_CONTENT_TYPE;
        $lines[] = $tuple['has_example']
            ? "// Taken from the spec's example; replace it with what your application returns."
            : '// TODO: replace with the response your application returns.';
        $lines[] = '$response = new Response(';
        $lines[] = '    ' . $this->literal($noContent ? '' : $this->encode($tuple['has_example'] ? $tuple['example'] : [])) . ',';
        $lines[] = '    ' . $tuple['status_code'] . ',';
        $lines[] = '    ' . ($noContent ? '[]' : $this->literal(['Content-Type' => $tuple['wire_content_type']], 1)) . ',';
        $lines[] = ');';
        $lines[] = '';
        $lines[] = '$this->assertResponseMatchesOpenApiSchema($request, $response);';

        return $lines;
    }

    /**
     * @param StubOperation $operation
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function pestBody(array $operation, array $tuple): array
    {
        $lines = $this->laravelBody($operation, $tuple);
        $lines[count($lines) - 1] = sprintf(
            'expect($response)->toMatchOpenApiResponseSchema(%s);',
            $this->literal($this->specName),
        );

        return array_merge($this->tupleNotes($operation, $tuple), $lines);
    }

    /**
     * The comments a tuple needs above its call: which concrete status a range
     * key is being exercised with, and — when the spec puts the request body or
     * the response out of this engine's reach — why the assertions below can
     * only ever pass as Skipped. Without the second kind the stub reads as
     * finished while the coverage document keeps reporting the tuple as
     * skipped.
     *
     * @param StubOperation $operation
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function tupleNotes(array $operation, array $tuple): array
    {
        $lines = [];

        if ($tuple['is_range']) {
            $lines[] = sprintf(
                '// The spec declares `%s`; this stub exercises %d.',
                $tuple['status'],
                $tuple['status_code'],
            );
        }

        $notices = [$tuple['skip_notice']];
        if ($this->adapter !== 'phpunit') {
            // The core adapter never sends the request body, so its skip is
            // not something the generated test could run into.
            $notices[] = $this->selectRequestBody($operation)['skip_notice'] ?? null;
        }

        foreach ($notices as $notice) {
            if ($notice === null) {
                continue;
            }
            foreach (explode("\n", wordwrap('TODO: ' . $notice . '.', 68)) as $line) {
                $lines[] = '// ' . $line;
            }
        }

        return $lines;
    }

    /**
     * @param StubOperation $operation
     * @param StubTuple $tuple
     */
    private function describeTuple(array $operation, array $tuple): string
    {
        $response = $tuple['content_type'] === OpenApiCoverageTracker::ANY_CONTENT_TYPE
            ? $tuple['status'] . ' (no content)'
            : $tuple['status'] . ' ' . $tuple['content_type'];

        return sprintf('%s %s returns %s', $operation['method'], $operation['path'], $response);
    }

    /**
     * The wire body for a media type the JSON helpers cannot carry.
     *
     * A string example under a non-JSON media type is already the payload —
     * an XML `example: "<pet/>"` must be sent as `<pet/>`, not as the
     * JSON-encoded `"<pet/>"`. Everything else falls back to JSON, which is
     * the least wrong serialisation for a structured example whose media type
     * gives no encoding, and is a TODO line either way.
     */
    private function rawBody(mixed $value, string $contentType): string
    {
        if (is_string($value) && !$this->isJsonMediaType($contentType)) {
            return $value;
        }

        // A form body reaching the raw path — a custom method, which cannot
        // carry fields as request parameters — still has to go out as
        // urlencoded bytes; FormBodyDecoder::toFieldMap() parse_str()s them
        // back. JSON here would decode to nothing.
        if (is_array($value) && $this->isFormMediaType($contentType)) {
            return http_build_query($value);
        }

        return $this->encode($value);
    }

    /** JSON for a body literal embedded in a generated string argument. */
    private function encode(mixed $value): string
    {
        try {
            return (string) json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return '';
        }
    }

    /**
     * A PHP literal for a decoded JSON value: short array syntax, one entry
     * per line, so the generated file needs no reformatting pass.
     */
    private function literal(mixed $value, int $depth = 0): string
    {
        if (!is_array($value)) {
            return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null
                ? var_export($value, true)
                : 'null';
        }
        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $depth + 1);
        $isList = array_is_list($value);
        $lines = ['['];
        foreach ($value as $key => $item) {
            $lines[] = $isList
                ? $pad . $this->literal($item, $depth + 1) . ','
                : $pad . var_export((string) $key, true) . ' => ' . $this->literal($item, $depth + 1) . ',';
        }
        $lines[] = str_repeat('    ', $depth) . ']';

        return implode("\n", $lines);
    }

    private function shortName(string $class): string
    {
        $class = ltrim($class, '\\');
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /** `application/json` → `application_json`, `{petId}` → `pet_id`, `2XX` → `2xx`. */
    private function snake(string $value): string
    {
        // Split camelCase too, so a `{petId}` template variable reads the way
        // the surrounding snake_case method name does. Anchored on a lowercase
        // letter so an all-caps run like `2XX` is left alone.
        $value = preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $value) ?? $value;
        $parts = preg_split('/[^A-Za-z0-9]+/', $value) ?: [];
        $parts = array_map(static fn(string $part): string => strtolower($part), array_filter(
            $parts,
            static fn(string $part): bool => $part !== '',
        ));

        return implode('_', $parts);
    }
}
