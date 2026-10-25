<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use function array_map;
use function http_build_query;
use function implode;
use function is_array;
use function is_bool;
use function rawurlencode;
use function str_replace;

/**
 * Request-target building shared by explored cases and Arazzo step requests:
 * `{name}` path templates, query strings (`http_build_query` for explored
 * cases, OpenAPI style serialization for Arazzo), and the boolean spelling
 * (`true` / `false`) both use on the wire.
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
     * The query string as OpenAPI serializes it: a list repeats its name
     * (`form` + `explode: true`, the default) unless `$delimiters` names the
     * wire delimiter of a non-exploded style (`,`, `%20`, `%7C`).
     *
     * @param array<string, mixed> $query
     * @param array<string, string> $delimiters parameter name → wire delimiter
     */
    public static function openApiQuery(array $query, array $delimiters): ?string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $value = self::serialise($value);
            $key = rawurlencode((string) $name);
            if (!is_array($value)) {
                $pairs[] = $key . '=' . rawurlencode((string) $value);

                continue;
            }

            $items = array_map(static fn(mixed $item): string => rawurlencode((string) $item), $value);
            if (isset($delimiters[$name])) {
                $pairs[] = $key . '=' . implode($delimiters[$name], $items);

                continue;
            }

            foreach ($items as $item) {
                $pairs[] = $key . '=' . $item;
            }
        }

        return $pairs === [] ? null : implode('&', $pairs);
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
