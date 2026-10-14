<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Studio\Gesso\Validation\Support\QueryStyleDeserializer;

use function array_map;
use function count;
use function urldecode;

/**
 * Parses a client request's wire query string for the client-side adapters
 * (PSR-7 `RequestInterface`, the `MockHttpClient` fake), which have no
 * framework-parsed query map.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class WireQueryParser
{
    private function __construct() {}

    /**
     * OpenAPI `style: form, explode: true` serializes arrays by repeating the
     * parameter name (`tags=a&tags=b`). PHP's parse_str() overwrites earlier
     * unbracketed values, so parse the wire pairs directly and promote a
     * repeated key to an ordered list instead.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $queryString): array
    {
        $query = [];
        foreach (QueryStyleDeserializer::parseRawValues($queryString) as $name => $encodedValues) {
            $values = array_map(urldecode(...), $encodedValues);
            $query[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $query;
    }
}
