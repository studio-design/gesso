<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use function array_key_exists;
use function array_pad;
use function explode;
use function is_array;
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
        /** @var array<string, mixed> $query */
        $query = [];
        if ($queryString === '') {
            return $query;
        }

        foreach (explode('&', $queryString) as $pair) {
            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($encodedName);
            if ($name === '') {
                continue;
            }

            $value = urldecode($encodedValue);
            if (!array_key_exists($name, $query)) {
                $query[$name] = $value;

                continue;
            }

            if (!is_array($query[$name])) {
                $query[$name] = [$query[$name]];
            }
            $query[$name][] = $value;
        }

        return $query;
    }
}
