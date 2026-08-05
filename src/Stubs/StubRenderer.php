<?php

declare(strict_types=1);

namespace Studio\Gesso\Stubs;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use InvalidArgumentException;
use JsonException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;

use function array_filter;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function explode;
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
use function sort;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function ucfirst;
use function var_export;

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
 * @phpstan-import-type StubTuple from StubGenerator
 *
 * @internal The `gesso stubs` / `openapi:stubs` CLI surface is the supported API.
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

    public const DEFAULT_OUTPUT_DIRS = [
        'phpunit' => 'tests/Contract',
        'laravel' => 'tests/Feature/Contract',
        'symfony' => 'tests/Contract',
        'pest' => 'tests/Contract',
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
     * `GET /v1/pets/{petId}` becomes `GetV1PetsPetIdTest`. Derived from the
     * method and path rather than `operationId` so the name is unique by
     * construction — `(method, path)` is unique in a document, `operationId`
     * is only unique when the author kept it so.
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

    /** @param StubOperation $operation */
    public function render(array $operation, string $className): string
    {
        return match ($this->adapter) {
            'pest' => $this->renderPest($operation),
            default => $this->renderClass($operation, $className),
        };
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
        $pad = '';
        $subject = sprintf('`%s %s`', $operation['method'], $operation['path']);
        if ($operation['operation_id'] !== null) {
            $subject .= sprintf(' (operationId `%s`)', $operation['operation_id']);
        }

        $lines = [
            $pad . '/**',
            $pad . ' * Contract stub for ' . $subject . '.',
        ];
        if ($operation['summary'] !== null && trim($operation['summary']) !== '') {
            $lines[] = $pad . ' *';
            $lines[] = $pad . ' * ' . trim($operation['summary']);
        }
        $lines[] = $pad . ' *';
        $lines[] = $pad . ' * Generated by `gesso stubs`: no test validated the responses below.';
        $lines[] = $this->adapter === 'pest'
            ? $pad . ' * Fill in each TODO and drop the `->todo()` call to enable the test.'
            : $pad . ' * Fill in each TODO and delete the `markTestIncomplete()` call.';
        $lines[] = $pad . ' */';

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

        if ($tuple['is_range']) {
            $lines[] = sprintf(
                '// The spec declares `%s`; this stub exercises %d.',
                $tuple['status'],
                $tuple['status_code'],
            );
        }

        return array_merge($lines, match ($this->adapter) {
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
            $arguments[] = $this->literal($tuple['content_type']);
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
        $helper = match ($operation['method']) {
            'GET' => 'getJson',
            'POST' => 'postJson',
            'PUT' => 'putJson',
            'PATCH' => 'patchJson',
            'DELETE' => 'deleteJson',
            default => null,
        };

        $call = $helper === null
            ? sprintf('$this->json(%s, %s', $this->literal($operation['method']), $this->literal($operation['request_path']))
            : sprintf('$this->%s(%s', $helper, $this->literal($operation['request_path']));

        $lines = [];
        if ($operation['has_request_body']) {
            $lines[] = '// TODO: adjust the payload your application expects.';
            $lines[] = '$payload = ' . $this->literal($operation['request_body']) . ';';
            $lines[] = '';
            $call .= ', $payload';
        }
        $call .= ')';

        if ($operation['headers'] !== []) {
            $lines[] = '$response = $this->withHeaders(' . $this->literal($operation['headers']) . ')->' . substr($call, strlen('$this->')) . ';';
        } else {
            $lines[] = '$response = ' . $call . ';';
        }

        $lines[] = '';
        $lines[] = sprintf('$response->assertStatus(%d);', $tuple['status_code']);
        $lines[] = '$this->assertResponseMatchesOpenApiSchema($response);';

        return $lines;
    }

    /**
     * @param StubOperation $operation
     * @param StubTuple $tuple
     *
     * @return list<string>
     */
    private function symfonyBody(array $operation, array $tuple): array
    {
        $arguments = [
            $this->literal($operation['request_path']),
            $this->literal($operation['method']),
        ];
        if ($operation['headers'] !== []) {
            $server = [];
            foreach ($operation['headers'] as $name => $value) {
                $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
            }
            $arguments[] = 'server: ' . $this->literal($server, 1);
        }
        if ($operation['has_request_body']) {
            $arguments[] = 'content: ' . $this->literal($this->encode($operation['request_body']));
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
        $lines[] = '    ' . ($noContent ? '[]' : $this->literal(['Content-Type' => $tuple['content_type']], 1)) . ',';
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

        if ($tuple['is_range']) {
            return array_merge([sprintf(
                '// The spec declares `%s`; this stub exercises %d.',
                $tuple['status'],
                $tuple['status_code'],
            )], $lines);
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
