<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use function count;
use function escapeshellarg;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Renders a one-line curl command that reproduces an HTTP request, with
 * sensitive header values redacted by default so the command stays safe to
 * print into CI logs and test failure output.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class CurlCommandFormatter
{
    private const REDACTED_PLACEHOLDER = '<redacted>';
    private const SENSITIVE_HEADER_NAMES = ['authorization', 'proxy-authorization', 'cookie'];
    private const SENSITIVE_NAME_PATTERN = '/api[-_]?key|token|secret/i';

    /**
     * @param array<string, mixed> $headers header name → scalar value or list of values
     * @param ?string $body raw request body; rendered only for JSON content types
     */
    public static function format(
        string $method,
        string $uri,
        array $headers,
        ?string $body,
        ?string $contentType,
        bool $redact = true,
    ): string {
        if ($redact) {
            $uri = self::redactQueryValues($uri);
        }
        $command = sprintf('curl -X %s %s', $method, escapeshellarg($uri));

        foreach ($headers as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $single) {
                $rendered = $redact && self::isSensitiveName($name)
                    ? self::REDACTED_PLACEHOLDER
                    : (is_scalar($single) ? (string) $single : '');
                $command .= ' -H ' . escapeshellarg($name . ': ' . $rendered);
            }
        }

        if ($body !== null && $body !== '' && $contentType !== null && self::isJsonContentType($contentType)) {
            $command .= ' --data ' . escapeshellarg($body);
        }

        return str_replace(["\r", "\n"], ' ', $command);
    }

    /**
     * OpenAPI supports `apiKey` security in query parameters, so query values
     * whose (URL-decoded) names match the sensitive-name pattern must not
     * reach CI logs either. Pair order and encoding of names are preserved;
     * valueless pairs have nothing to redact.
     */
    private static function redactQueryValues(string $uri): string
    {
        $queryStart = strpos($uri, '?');
        if ($queryStart === false) {
            return $uri;
        }

        $pairs = explode('&', substr($uri, $queryStart + 1));
        foreach ($pairs as $index => $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (self::isSensitiveName(rawurldecode($parts[0]))) {
                $pairs[$index] = $parts[0] . '=' . self::REDACTED_PLACEHOLDER;
            }
        }

        return substr($uri, 0, $queryStart) . '?' . implode('&', $pairs);
    }

    private static function isSensitiveName(string $name): bool
    {
        return in_array(strtolower($name), self::SENSITIVE_HEADER_NAMES, true) ||
            preg_match(self::SENSITIVE_NAME_PATTERN, $name) === 1;
    }

    private static function isJsonContentType(string $contentType): bool
    {
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mime === 'application/json' || str_ends_with($mime, '+json');
    }
}
