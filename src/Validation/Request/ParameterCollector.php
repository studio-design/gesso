<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function strtolower;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ParameterCollector
{
    /**
     * Per OpenAPI 3.x: "If `in` is `header` and the `name` field is `Accept`,
     * `Content-Type` or `Authorization`, the parameter definition SHALL be
     * ignored." These are controlled by content negotiation and security
     * schemes; surfacing them through parameter validation would duplicate
     * (and often disagree with) the framework's own handling.
     */
    private const RESERVED_HEADER_NAMES = ['accept', 'content-type', 'authorization'];

    /**
     * Merge path-level and operation-level parameters. Operation-level entries
     * override path-level ones with the same `name` + `in` (per OpenAPI spec).
     *
     * Malformed entries are surfaced as errors rather than silently skipped,
     * because for a contract-testing tool the absence of an error means
     * "validated and OK" — silently dropping a parameter would leave drift
     * invisible. `$ref` entries never reach this method: `OpenApiSpecLoader`
     * resolves internal refs and throws on external/circular/unresolvable ones
     * at load time.
     *
     * @param array<string, mixed> $pathSpec
     * @param array<string, mixed> $operation
     */
    public static function collect(string $method, string $matchedPath, array $pathSpec, array $operation): CollectionResult
    {
        $merged = [];
        $errors = [];

        foreach ([$pathSpec['parameters'] ?? [], $operation['parameters'] ?? []] as $source) {
            if (!is_array($source)) {
                continue;
            }

            foreach ($source as $param) {
                if (!is_array($param)) {
                    $errors[] = "Malformed parameter entry for {$method} {$matchedPath}: expected object, got scalar.";

                    continue;
                }

                if (!isset($param['in'], $param['name']) || !is_string($param['in']) || !is_string($param['name'])) {
                    $errors[] = "Malformed parameter entry for {$method} {$matchedPath}: 'name' and 'in' must be strings.";

                    continue;
                }

                // OAS 3.0/3.1/3.2 Parameter Object `name`: "If `in` is "header"
                // and the `name` field is "Accept", "Content-Type" or
                // "Authorization", the parameter definition SHALL be ignored."
                // Ignored means ignored: drop the entry without an error, like
                // `ResponseHeaderValidator` does for a response `Content-Type`
                // header. The `in === 'header'` guard keeps e.g.
                // `in: query, name: accept` in scope as an ordinary parameter.
                if ($param['in'] === 'header' && in_array(strtolower($param['name']), self::RESERVED_HEADER_NAMES, true)) {
                    continue;
                }

                $key = $param['in'] . ':' . $param['name'];
                $merged[$key] = $param;
            }
        }

        $queryParameters = 0;
        $queryStringParameters = 0;
        foreach ($merged as $param) {
            if ($param['in'] === 'query') {
                $queryParameters++;
            } elseif ($param['in'] === 'querystring') {
                $queryStringParameters++;
            }
        }

        if ($queryStringParameters > 1) {
            $errors[] = "OpenAPI 3.2 permits at most one 'in: querystring' parameter for {$method} {$matchedPath}.";
        }
        if ($queryStringParameters > 0 && $queryParameters > 0) {
            $errors[] = "OpenAPI 3.2 forbids mixing 'in: querystring' with 'in: query' parameters for {$method} {$matchedPath}.";
        }

        return new CollectionResult(array_values($merged), $errors);
    }
}
