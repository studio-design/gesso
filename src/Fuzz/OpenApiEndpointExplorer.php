<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Fuzz;

use Faker\Generator;
use InvalidArgumentException;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\SchemaContext;
use Studio\OpenApiContractTesting\Spec\OpenApiOperationResolver;
use Studio\OpenApiContractTesting\Spec\OpenApiPathMatcher;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Request\ParameterCollector;
use ValueError;

use function array_filter;
use function array_keys;
use function array_map;
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
 * Out-of-scope today: `oneOf`/`anyOf`/`allOf` body composition, non-JSON
 * media types (only `application/json` is generated for `requestBody`).
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

        $version = OpenApiVersion::fromSpec($spec);
        $bodySchema = self::extractRequestBodySchema($operation, $version, $methodUpper, $matchedPath, $specName);
        /** @var list<array<string, mixed>> $parameters */
        $parameters = ParameterCollector::collect($methodUpper, $matchedPath, $pathSpec, $operation)->parameters;
        self::assertParametersGeneratable($parameters, $methodUpper, $matchedPath, $specName);

        $faker = SchemaDataGenerator::createFaker($seed);
        $built = [];
        for ($i = 0; $i < $cases; $i++) {
            $built[] = new ExploredCase(
                body: $bodySchema !== null ? SchemaDataGenerator::generateOne($bodySchema, $faker, $i) : null,
                query: self::generateParameterValues($parameters, 'query', $version, $faker, $i),
                headers: self::generateParameterValues($parameters, 'header', $version, $faker, $i),
                pathParams: self::generateParameterValues($parameters, 'path', $version, $faker, $i),
                method: $methodEnum,
                matchedPath: $matchedPath,
            );
        }

        return new ExplorationCases($built);
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
     * Find the JSON-shaped requestBody schema and convert it to Draft 07.
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

        return OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);
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
            $converted = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Request);
            $values[$name] = SchemaDataGenerator::generateOne($converted, $faker, $iteration);
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
