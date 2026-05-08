<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Support;

use InvalidArgumentException;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;

use function preg_last_error_msg;
use function preg_match;
use function sprintf;

/**
 * Pre-compiled set of regex patterns matched against a stringified HTTP
 * status code. Wraps the {@see compile} / {@see match} idiom shared by the
 * response-side {@see OpenApiResponseValidator}'s
 * `skip_response_codes` and the request-side `skip_request_validation_response_codes`
 * (issue #179).
 *
 * Patterns are stored keyed by the user-provided form (raw, without
 * delimiters/anchors) so {@see match} returns the exact string the caller
 * configured — useful for skip-reason diagnostics.
 *
 * @internal
 */
final class StatusCodePatternSet
{
    /** @var array<string, string> raw pattern => anchored pattern */
    private readonly array $patterns;

    /**
     * @param string[] $patterns
     * @param string $configLabel surfaced in error messages so users see
     *                            which config key they need to fix
     *                            (e.g. `skipResponseCodes`,
     *                            `skipRequestValidationResponseCodes`)
     *
     * @throws InvalidArgumentException when a pattern is empty or unparseable
     */
    public function __construct(array $patterns, string $configLabel)
    {
        $compiled = [];
        foreach ($patterns as $index => $pattern) {
            if ($pattern === '') {
                throw new InvalidArgumentException(
                    sprintf('%s[%s] must not be an empty string.', $configLabel, (string) $index),
                );
            }

            $anchored = '/^(?:' . $pattern . ')$/';

            $ok = @preg_match($anchored, '');
            if ($ok === false) {
                throw new InvalidArgumentException(sprintf(
                    '%s[%s] is not a valid regex pattern "%s": %s',
                    $configLabel,
                    (string) $index,
                    $pattern,
                    preg_last_error_msg(),
                ));
            }

            $compiled[$pattern] = $anchored;
        }

        $this->patterns = $compiled;
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    /**
     * Returns the first matching raw pattern (so the caller can echo it back
     * verbatim in skip-reason diagnostics) or null when nothing matches.
     */
    public function match(string $statusCodeStr): ?string
    {
        foreach ($this->patterns as $raw => $anchored) {
            if (preg_match($anchored, $statusCodeStr) === 1) {
                // PHP coerces numeric-string array keys to int (e.g. pattern
                // "500" lands under key 500). Cast back to string so the
                // documented ?string return type is honoured even for
                // status-code-shaped patterns.
                return (string) $raw;
            }
        }

        return null;
    }
}
