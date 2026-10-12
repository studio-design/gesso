<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use function array_map;
use function http_build_query;
use function is_array;
use function is_bool;
use function rawurlencode;
use function str_replace;

/**
 * Request-target building shared by explored cases and Arazzo step requests:
 * `{name}` path templates, `http_build_query` query strings, and the boolean
 * spelling (`true` / `false`) both use on the wire.
 *
 * @internal Request plumbing shared by ExploredCase and ArazzoStepRequest; not public API.
 */
final class RequestUriBuilder
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $pathParams
     */
    public static function path(string $template, array $pathParams): string
    {
        foreach ($pathParams as $name => $value) {
            $template = str_replace('{' . $name . '}', rawurlencode((string) self::serialise($value)), $template);
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function query(array $query): ?string
    {
        return $query === [] ? null : http_build_query(array_map(self::serialise(...), $query));
    }

    /**
     * @param array<string, mixed> $pathParams
     * @param array<string, mixed> $query
     */
    public static function build(string $template, array $pathParams, array $query, string $prefix = ''): string
    {
        $queryString = self::query($query);

        return $prefix . self::path($template, $pathParams) . ($queryString === null ? '' : '?' . $queryString);
    }

    public static function serialise(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_array($value) ? array_map(self::serialise(...), $value) : $value;
    }
}
