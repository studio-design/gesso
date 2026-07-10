<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use function array_map;
use function implode;

enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case QUERY = 'QUERY';

    /**
     * Comma-separated list of supported method values for use in error
     * messages (e.g. "Allowed: GET, POST, PUT, PATCH, DELETE, QUERY").
     *
     * Centralised so the Pest dispatcher and the Laravel trait error
     * surfaces stay in sync when a new case is added to the enum.
     *
     * @internal Used by the Pest plugin and the Laravel ValidatesOpenApiSchema trait.
     */
    public static function listOfValues(): string
    {
        return implode(', ', array_map(static fn(self $m): string => $m->value, self::cases()));
    }
}
