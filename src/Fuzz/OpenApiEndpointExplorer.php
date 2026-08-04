<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Faker\Generator;
use InvalidArgumentException;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Request\ParameterCollector;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use ValueError;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_ends_with;
use function strtok;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Generate N happy-path request cases for a single (method, path) operation
 * in an OpenAPI spec. Framework-agnostic — the Laravel adapter wraps this in
 * a trait, but the same primitive can be used from PSR-7 / Symfony tests.
 *
 * Path resolution: the user's `$path` may be either the spec template form
 * (`/v1/pets/{petId}`) or a concrete URI (`/api/v1/pets/123`). Both resolve
 * via {@see OpenApiPathMatcher} configured with the loader's stripPrefixes.
 * The matcher's captured variables are intentionally discarded — values for
 * `in:path` parameters are always (re)generated from the operation's spec
 * so callers never depend on whichever URI form they passed.
 *
 * Only JSON-compatible request bodies and schema-form parameters are
 * generated; unsupported media/parameter forms fail loudly.
 */
final class OpenApiEndpointExplorer
{
    /**
     * @throws InvalidArgumentException when $cases < 1, the spec path is not
     *                                  found, or the operation is not declared.
     */
    public static function explore(
        string $specName,
        string $method,
        string $path,
        int $cases = 30,
        ?int $seed = null,
    ): ExplorationCases {
        return self::buildValidCases($specName, $method, $path, $cases, $seed);
    }

    /**
     * Generate request cases that each violate one identified schema keyword.
     * Expected response classes are explicit by design: negative contract
     * tests must assert the documented client-error behavior, not merely
     * accept every response that is not a 5xx.
     *
     * @param list<int> $expectedStatusClasses
     */
    public static function exploreInvalid(
        string $specName,
        string $method,
        string $path,
        array $expectedStatusClasses,
        int $cases = 30,
        ?int $seed = null,
    ): ExplorationCases {
        if ($expectedStatusClasses === []) {
            throw new InvalidArgumentException('exploreInvalid() requires at least one expected HTTP status class.');
        }
        foreach ($expectedStatusClasses as $statusClass) {
            if ($statusClass < 1 || $statusClass > 5) {
                throw new InvalidArgumentException(sprintf('Expected HTTP status class must be between 1 and 5, got %d.', $statusClass));
            }
        }

        $validCases = self::buildValidCases($specName, $method, $path, $cases, $seed);
        [$bodySchema, $parameterSchemas, $bodyRequired] = self::schemasForOperation($specName, $method, $path);
        $faker = SchemaDataGenerator::createFaker($seed);
        $built = [];

        foreach ($validCases as $index => $validCase) {
            $targets = [];
            if ($bodyRequired) {
                $targets[] = [
                    'location' => 'body',
                    'name' => null,
                    'mutation' => new SchemaMutation(null, 'required', ''),
                    'remove' => true,
                ];
            }
            if ($bodySchema !== null) {
                try {
                    foreach (SchemaMutationGenerator::generate($bodySchema, 100, $faker, $index) as $mutation) {
                        $targets[] = ['location' => 'body', 'name' => null, 'mutation' => $mutation, 'remove' => false];
                    }
                } catch (InvalidArgumentException) {
                    // Try parameter constraints before declaring the operation unsupported.
                }
            }
            foreach ($parameterSchemas as $parameter) {
                if ($parameter['required']) {
                    $targets[] = [
                        'location' => $parameter['in'],
                        'name' => $parameter['name'],
                        'mutation' => new SchemaMutation(null, 'required', ''),
                        'remove' => true,
                    ];
                }

                try {
                    foreach (SchemaMutationGenerator::generate($parameter['schema'], 100, $faker, $index) as $mutation) {
                        $targets[] = [
                            'location' => $parameter['in'],
                            'name' => $parameter['name'],
                            'mutation' => $mutation,
                            'remove' => false,
                        ];
                    }
                } catch (InvalidArgumentException) {
                    // This parameter has no supported deterministic mutation.
                }
            }

            if ($targets === []) {
                throw new InvalidArgumentException(sprintf(
                    "Operation %s '%s' in spec '%s' has no supported invalid mutation target.",
                    strtoupper($method),
                    $validCase->matchedPath,
                    $specName,
                ));
            }

            $target = $targets[$index % count($targets)];
            /** @var SchemaMutation $mutation */
            $mutation = $target['mutation'];
            $mutated = $target['remove'] === true
                ? self::removeInput($validCase, $target['location'], $target['name'])
                : match ($target['location']) {
                    'body' => $validCase->withBody($mutation->value),
                    'query' => $validCase->withQuery(self::replaceValue($validCase->query, $target['name'], $mutation->value)),
                    'header' => $validCase->withHeaders(self::replaceValue($validCase->headers, $target['name'], $mutation->value)),
                    'path' => $validCase->withPathParams(self::replaceValue($validCase->pathParams, $target['name'], $mutation->value)),
                    default => $validCase,
                };
            $built[] = new ExploredCase(
                $mutated->body,
                $mutated->query,
                $mutated->headers,
                $mutated->pathParams,
                $mutated->method,
                $mutated->matchedPath,
                ExplorationCaseKind::Invalid,
                $mutation->keyword,
                $target['location'] . $mutation->pointer,
                $expectedStatusClasses,
                $seed,
                $index,
            );
        }

        return new ExplorationCases($built);
    }

    /**
     * Generate cases that carry only concrete path-parameter values — no
     * body, query, or headers. Contract probes that dispatch a different
     * method against the path (issue #439) only need a concrete URI, so the
     * generatability of a documented operation's body, query, and header
     * inputs must not gate them. Fails loudly only when a required path
     * parameter cannot be generated — a real inability to construct a URI.
     *
     * @internal probe-construction primitive for {@see ContractCheckPlan}, not public API
     *
     * @throws InvalidArgumentException when $cases < 1, the spec path is not
     *                                  found, the operation is not declared,
     *                                  or a path parameter is not generatable.
     */
    public static function exploreUriOnly(
        string $specName,
        string $method,
        string $path,
        int $cases = 1,
        ?int $seed = null,
    ): ExplorationCases {
        [$spec, $methodEnum, $matchedPath, $pathSpec, $operation] = self::resolveOperationContext($specName, $method, $path, $cases);

        $version = OpenApiVersion::fromSpec($spec);
        $jsonSchemaDialect = OpenApiSchemaDialect::fromSpec($spec, $version);
        $pathParameters = array_values(array_filter(
            ParameterCollector::collect($methodEnum->value, $matchedPath, $pathSpec, $operation)->parameters,
            static fn(array $p): bool => ($p['in'] ?? null) === 'path',
        ));
        self::assertParametersGeneratable($pathParameters, $methodEnum->value, $matchedPath, $specName);

        $faker = SchemaDataGenerator::createFaker($seed);
        $built = [];
        for ($i = 0; $i < $cases; $i++) {
            $built[] = new ExploredCase(
                body: null,
                query: [],
                headers: [],
                pathParams: self::generateParameterValues($pathParameters, 'path', $version, $jsonSchemaDialect, $faker, $i),
                method: $methodEnum,
                matchedPath: $matchedPath,
                seed: $seed,
                caseIndex: $i,
            );
        }

        return new ExplorationCases($built);
    }

    /**
     * Names of the operation's `required: true` `in: header` parameters, in
     * collection order. Reserved header names (`Accept` / `Content-Type` /
     * `Authorization`) never appear: {@see ParameterCollector} drops them per
     * OAS §4.7.12.1, so a generated valid case carries no value to omit for
     * them either.
     *
     * @internal probe-construction primitive for {@see ContractCheckPlan}, not public API
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when the method is unsupported, the
     *                                  spec path is not found, or the
     *                                  operation is not declared.
     */
    public static function requiredHeaderNames(string $specName, string $method, string $path): array
    {
        [, $methodEnum, $matchedPath, $pathSpec, $operation] = self::resolveOperationContext($specName, $method, $path, 1);

        $names = [];
        foreach (ParameterCollector::collect($methodEnum->value, $matchedPath, $pathSpec, $operation)->parameters as $parameter) {
            if (($parameter['in'] ?? null) !== 'header' || ($parameter['required'] ?? false) !== true) {
                continue;
            }
            $name = $parameter['name'] ?? null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function buildValidCases(
        string $specName,
        string $method,
        string $path,
        int $cases,
        ?int $seed,
    ): ExplorationCases {
        [$spec, $methodEnum, $matchedPath, $pathSpec, $operation] = self::resolveOperationContext($specName, $method, $path, $cases);
        $methodUpper = $methodEnum->value;

        $version = OpenApiVersion::fromSpec($spec);
        $jsonSchemaDialect = OpenApiSchemaDialect::fromSpec($spec, $version);
        $bodySchema = self::extractRequestBodySchema($operation, $version, $methodUpper, $matchedPath, $specName, $jsonSchemaDialect, $spec);
        /** @var list<array<string, mixed>> $parameters */
        $parameters = ParameterCollector::collect($methodUpper, $matchedPath, $pathSpec, $operation)->parameters;
        self::assertParametersGeneratable($parameters, $methodUpper, $matchedPath, $specName);

        $faker = SchemaDataGenerator::createFaker($seed);
        $built = [];
        for ($i = 0; $i < $cases; $i++) {
            $body = $bodySchema !== null ? SchemaDataGenerator::generateOne($bodySchema, $faker, $i) : null;
            if ($bodySchema !== null) {
                SchemaValueValidator::assertValid($body, $bodySchema, $i);
            }
            $query = self::generateParameterValues($parameters, 'query', $version, $jsonSchemaDialect, $faker, $i);
            $headers = self::generateParameterValues($parameters, 'header', $version, $jsonSchemaDialect, $faker, $i);
            $pathParams = self::generateParameterValues($parameters, 'path', $version, $jsonSchemaDialect, $faker, $i);
            $built[] = new ExploredCase(
                body: $body,
                query: $query,
                headers: $headers,
                pathParams: $pathParams,
                method: $methodEnum,
                matchedPath: $matchedPath,
                seed: $seed,
                caseIndex: $i,
            );
        }

        return new ExplorationCases($built);
    }

    /**
     * Resolve the shared generation context: loaded spec, method enum, spec
     * path template, path item, and operation node — failing loudly on an
     * invalid case count, unsupported method, unknown path, or undeclared
     * operation.
     *
     * @return array{0: array<string, mixed>, 1: HttpMethod, 2: string, 3: array<string, mixed>, 4: array<string, mixed>}
     */
    private static function resolveOperationContext(
        string $specName,
        string $method,
        string $path,
        int $cases,
    ): array {
        if ($cases < 1) {
            throw new InvalidArgumentException(sprintf(
                'OpenApiEndpointExplorer::explore() requires cases >= 1, got %d.',
                $cases,
            ));
        }

        $methodUpper = strtoupper($method);

        try {
            $methodEnum = HttpMethod::from($methodUpper);
        } catch (ValueError) {
            throw new InvalidArgumentException(sprintf(
                "Unsupported HTTP method '%s' — explorer accepts %s.",
                $method,
                implode(', ', array_map(static fn(HttpMethod $m): string => $m->value, HttpMethod::cases())),
            ));
        }

        $spec = OpenApiSpecLoader::load($specName);
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];

        $matchedPath = self::resolveMatchedPath($paths, $path);
        if ($matchedPath === null) {
            throw new InvalidArgumentException(sprintf(
                "Path '%s' is not declared in OpenAPI spec '%s'.",
                $path,
                $specName,
            ));
        }

        /** @var array<string, mixed> $pathSpec */
        $pathSpec = is_array($paths[$matchedPath] ?? null) ? $paths[$matchedPath] : [];
        $resolvedOperation = OpenApiOperationResolver::resolve($pathSpec, $methodUpper);
        $operation = $resolvedOperation['operation'];
        if (!$resolvedOperation['found'] || !is_array($operation)) {
            throw new InvalidArgumentException(sprintf(
                "Operation %s '%s' is not declared in OpenAPI spec '%s'.",
                $methodUpper,
                $matchedPath,
                $specName,
            ));
        }

        return [$spec, $methodEnum, $matchedPath, $pathSpec, $operation];
    }

    /**
     * @return array{0: null|array<string, mixed>, 1: list<array{in: string, name: string, required: bool, schema: array<string, mixed>}>, 2: bool}
     */
    private static function schemasForOperation(string $specName, string $method, string $path): array
    {
        $spec = OpenApiSpecLoader::load($specName);
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $matchedPath = self::resolveMatchedPath($paths, $path);
        if ($matchedPath === null || !is_array($paths[$matchedPath] ?? null)) {
            throw new InvalidArgumentException(sprintf("Path '%s' is not declared in OpenAPI spec '%s'.", $path, $specName));
        }
        $methodUpper = strtoupper($method);
        $pathSpec = $paths[$matchedPath];
        $resolved = OpenApiOperationResolver::resolve($pathSpec, $methodUpper);
        if (!$resolved['found'] || !is_array($resolved['operation'])) {
            throw new InvalidArgumentException(sprintf("Operation %s '%s' is not declared in OpenAPI spec '%s'.", $methodUpper, $matchedPath, $specName));
        }
        $operation = $resolved['operation'];
        $version = OpenApiVersion::fromSpec($spec);
        $dialect = OpenApiSchemaDialect::fromSpec($spec, $version);
        $body = self::extractRequestBodySchema($operation, $version, $methodUpper, $matchedPath, $specName, $dialect, $spec);
        $parameterSchemas = [];
        foreach (ParameterCollector::collect($methodUpper, $matchedPath, $pathSpec, $operation)->parameters as $parameter) {
            if (!is_string($parameter['in'] ?? null) || !is_string($parameter['name'] ?? null) || !is_array($parameter['schema'] ?? null)) {
                continue;
            }
            $parameterSchemas[] = [
                'in' => $parameter['in'],
                'name' => $parameter['name'],
                'required' => ($parameter['required'] ?? false) === true || $parameter['in'] === 'path',
                'schema' => OpenApiSchemaConverter::convert($parameter['schema'], $version, SchemaContext::Request, null, $dialect),
            ];
        }

        return [$body, $parameterSchemas, ($operation['requestBody']['required'] ?? false) === true];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function replaceValue(array $values, mixed $name, mixed $value): array
    {
        if (is_string($name)) {
            $values[$name] = $value;
        }

        return $values;
    }

    private static function removeInput(ExploredCase $case, mixed $location, mixed $name): ExploredCase
    {
        if ($location === 'body') {
            return $case->withBody(null);
        }
        if (!is_string($name)) {
            return $case;
        }

        return match ($location) {
            'query' => $case->withQuery(self::withoutKey($case->query, $name)),
            'header' => $case->withHeaders(self::withoutKey($case->headers, $name)),
            'path' => $case->withPathParams(self::withoutKey($case->pathParams, $name)),
            default => $case,
        };
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function withoutKey(array $values, string $name): array
    {
        unset($values[$name]);

        return $values;
    }

    /**
     * Resolve a user-supplied path string to its spec template. Accepts both
     * the template form (literal `/v1/pets/{petId}`) and concrete URIs
     * (`/api/v1/pets/123`); literal lookup is tried first so a spec that
     * happens to use placeholder-shaped segments is still selectable.
     *
     * @param array<string, mixed> $paths
     */
    private static function resolveMatchedPath(array $paths, string $path): ?string
    {
        if (isset($paths[$path]) && is_array($paths[$path])) {
            return $path;
        }

        $specPaths = [];
        foreach ($paths as $key => $value) {
            if (is_array($value)) {
                $specPaths[] = $key;
            }
        }

        $matcher = new OpenApiPathMatcher($specPaths, OpenApiSpecLoader::getStripPrefixes());

        return $matcher->match($path);
    }

    /**
     * Find the JSON-shaped requestBody schema and convert it to the dialect
     * selected by the OpenAPI document.
     * Returns null only when the operation declares no `requestBody` at all
     * (or one that isn't required and lacks a JSON variant) — that's the
     * "GET-style endpoint" case and the explorer correctly emits cases with
     * `body = null`.
     *
     * Throws when the spec declares `requestBody.required: true` but the
     * explorer cannot synthesize a JSON body. Without this guard every
     * generated case would 4xx at the server with no signal back to the
     * test author about why fuzzing degraded — exactly the silent-failure
     * mode the rest of the library tries to avoid.
     *
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $spec
     *
     * @return null|array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function extractRequestBodySchema(
        array $operation,
        OpenApiVersion $version,
        string $method,
        string $matchedPath,
        string $specName,
        string $jsonSchemaDialect,
        array $spec,
    ): ?array {
        $requestBody = $operation['requestBody'] ?? null;
        if (!is_array($requestBody)) {
            return null;
        }

        $required = ($requestBody['required'] ?? false) === true;
        $content = $requestBody['content'] ?? null;

        if (!is_array($content) || $content === []) {
            if ($required) {
                throw new InvalidArgumentException(sprintf(
                    "Operation %s '%s' in spec '%s' declares `requestBody.required: true` but no `content` map. "
                    . 'Add at least an `application/json` schema so the explorer can synthesize a body.',
                    $method,
                    $matchedPath,
                    $specName,
                ));
            }

            return null;
        }

        $schema = self::pickJsonSchema($content);
        if (!is_array($schema)) {
            if ($required) {
                throw new InvalidArgumentException(sprintf(
                    "Operation %s '%s' in spec '%s' declares `requestBody.required: true` but no JSON-compatible "
                    . 'media type (`application/json` / `+json`) carries a `schema` definition (saw: %s). '
                    . 'The explorer cannot synthesize a body without one — add a schema or skip this operation.',
                    $method,
                    $matchedPath,
                    $specName,
                    implode(', ', array_keys($content)),
                ));
            }

            return null;
        }

        return OpenApiSchemaConverter::convert(
            $schema,
            $version,
            SchemaContext::Request,
            new DiscriminatorContext($spec, true),
            $jsonSchemaDialect,
        );
    }

    /**
     * Prefer exact `application/json` over `+json` suffixes (problem+json,
     * merge+json, etc.) so a spec that defines both gets the canonical body
     * generated. Both lookups are case-insensitive and tolerate media-type
     * parameters (`application/json; charset=utf-8`).
     *
     * @param array<string, mixed> $content
     *
     * @return null|array<string, mixed>
     */
    private static function pickJsonSchema(array $content): ?array
    {
        $jsonFallback = null;
        foreach ($content as $mediaType => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!isset($entry['schema']) || !is_array($entry['schema'])) {
                continue;
            }

            $bare = strtolower(trim((string) strtok($mediaType, ';')));
            if ($bare === 'application/json') {
                return $entry['schema'];
            }
            if ($jsonFallback === null && str_ends_with($bare, '+json')) {
                $jsonFallback = $entry['schema'];
            }
        }

        return $jsonFallback;
    }

    /**
     * Filter spec parameters to a single `in:` location and generate a value
     * per name for the current iteration.
     *
     * `ParameterCollector::collect()` has already deduped on `(in, name)`, so
     * the explorer never sees a duplicate to overwrite — the assignment
     * semantics here are write-once, matching the merged spec view.
     *
     * `content`-form parameters (OAS 3.x §4.7.12.6) are filtered out earlier
     * by {@see self::assertParametersGeneratable()} when they would have made
     * the request unsatisfiable; remaining ones are non-required and skipped.
     *
     * @param list<array<string, mixed>> $parameters
     *
     * @return array<string, mixed>
     */
    private static function generateParameterValues(
        array $parameters,
        string $location,
        OpenApiVersion $version,
        string $jsonSchemaDialect,
        ?Generator $faker,
        int $iteration,
    ): array {
        $filtered = array_filter(
            $parameters,
            static fn(array $p): bool => ($p['in'] ?? null) === $location && is_string($p['name'] ?? null),
        );

        $values = [];
        foreach ($filtered as $param) {
            /** @var string $name */
            $name = $param['name'];
            $schema = isset($param['schema']) && is_array($param['schema']) ? $param['schema'] : null;
            if ($schema === null) {
                continue;
            }
            $converted = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request, null, $jsonSchemaDialect);
            $value = SchemaDataGenerator::generateOne($converted, $faker, $iteration);
            SchemaValueValidator::assertValid($value, $converted, $iteration);
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * Refuse to explore an operation whose `required: true` parameters can't
     * be synthesized — e.g. `content`-form parameters (OAS 3.x §4.7.12.6) or
     * malformed schema-less entries. Silent skips here would cause every
     * fuzzed request to 4xx with no link back to the explorer.
     *
     * @param list<array<string, mixed>> $parameters
     *
     * @throws InvalidArgumentException
     */
    private static function assertParametersGeneratable(
        array $parameters,
        string $method,
        string $matchedPath,
        string $specName,
    ): void {
        foreach ($parameters as $param) {
            $name = is_string($param['name'] ?? null) ? $param['name'] : '';
            $location = is_string($param['in'] ?? null) ? $param['in'] : '';
            $required = ($param['required'] ?? false) === true || $location === 'path';
            if (!$required) {
                continue;
            }

            $hasSchema = isset($param['schema']) && is_array($param['schema']);
            if ($hasSchema) {
                continue;
            }

            $reason = isset($param['content']) && is_array($param['content'])
                ? 'declares a `content` map instead of `schema` (the explorer only generates `schema`-form parameters)'
                : 'is missing a `schema` definition';

            throw new InvalidArgumentException(sprintf(
                "Required %s parameter '%s' on %s '%s' in spec '%s' %s.",
                $location !== '' ? $location : 'parameter',
                $name,
                $method,
                $matchedPath,
                $specName,
                $reason,
            ));
        }
    }
}
