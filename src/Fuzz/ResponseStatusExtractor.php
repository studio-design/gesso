<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;

use function get_debug_type;
use function is_callable;
use function is_int;
use function is_object;
use function sprintf;

/**
 * Turn a contract-check dispatch return value into an HTTP status code.
 * Duck-typing `getStatusCode()` covers PSR-7 responses, Symfony's Response,
 * and Laravel's TestResponse (a `__call` proxy) without a framework
 * dependency.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ResponseStatusExtractor
{
    public static function extract(mixed $response): int
    {
        if (is_int($response)) {
            return $response;
        }

        if (is_object($response) && is_callable([$response, 'getStatusCode'])) {
            $status = $response->getStatusCode();
            if (is_int($status)) {
                return $status;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Contract check dispatchUsing() must return an int status code, a PSR-7 response, or an object exposing getStatusCode(): int — got %s.',
            get_debug_type($response),
        ));
    }
}
