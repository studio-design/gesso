<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use const PHP_URL_HOST;

use InvalidArgumentException;
use Studio\Gesso\Internal\HttpRefLoader;

use function parse_url;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Declares an HTTP(S) entry document for a named spec (issue #407).
 *
 * Passed to {@see OpenApiSpecLoader::configure()} via `$remoteSpecs` so a
 * spec hosted in a private GitHub repository or an internal registry can be
 * used by name without vendoring the file into the repository. Sharing the
 * HTTP `$ref` opt-in, a remote source only works together with
 * `allowRemoteRefs: true`, an injected PSR-18 client + PSR-17 factory, and
 * a host allowlist that contains the URL's host.
 */
final readonly class RemoteSpecSource
{
    /**
     * Hex-encoded SHA-256 pin of the raw response body, normalized to
     * lowercase. `null` when the document is not pinned.
     */
    public ?string $expectedSha256;

    /**
     * @param string $url absolute `http://` / `https://` URL of the entry document
     * @param null|string $authorizationEnv name of an environment variable whose value is sent
     *                                      verbatim as the `Authorization` header (e.g.
     *                                      `Bearer <token>`). The header is only sent to the
     *                                      URL's own host, never to cross-host `$ref` targets.
     * @param null|string $expectedSha256 optional hex SHA-256 of the raw entry-document bytes;
     *                                    a mismatch fails the load before parsing
     *
     * @throws InvalidArgumentException when the URL is not absolute HTTP(S), the env variable
     *                                  name is empty, or the pin is not a 64-digit hex string
     */
    public function __construct(
        public string $url,
        public ?string $authorizationEnv = null,
        ?string $expectedSha256 = null,
    ) {
        // Configure-time rejections surface in CI logs, and a spec URL may
        // carry signed-URL query tokens or userinfo. Diagnostics use the
        // redacted form only; the raw URL also replaces the parameter slot
        // so traces stay clean under zend.exception_ignore_args=Off. The
        // promoted property keeps the raw value — the fetch needs it.
        $isHttp = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
        $host = parse_url($url, PHP_URL_HOST);
        $hasFragment = str_contains($url, '#');
        $safeUrl = HttpRefLoader::redactSensitiveUrlData($url);
        // The shared redaction keeps fragments because $ref diagnostics
        // need their JSON pointers, but an entry URL's fragment is rejected
        // wholesale and may carry an OAuth-style token — hide its content
        // and show only that one was present.
        $fragmentStart = strpos($safeUrl, '#');
        if ($fragmentStart !== false) {
            $safeUrl = substr($safeUrl, 0, $fragmentStart) . '#[redacted]';
        }
        $url = $safeUrl;

        if (!$isHttp) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSpecSource: URL must start with http:// or https://, got `%s`.',
                $safeUrl,
            ));
        }

        if ($host === false || $host === null || $host === '') {
            throw new InvalidArgumentException(sprintf(
                'RemoteSpecSource: URL has no parseable host: `%s`.',
                $safeUrl,
            ));
        }

        if ($hasFragment) {
            // A fragment is client-side only and never part of the wire
            // request, so it cannot select a different entry document —
            // but it would make the URL spelling diverge from the fetched
            // resource's identity. Reject loudly instead of stripping.
            throw new InvalidArgumentException(sprintf(
                'RemoteSpecSource: URL must not contain a fragment: `%s`. '
                . 'The entry document is always loaded whole; remove the `#...` part.',
                $safeUrl,
            ));
        }

        if ($authorizationEnv !== null && trim($authorizationEnv) === '') {
            throw new InvalidArgumentException(
                'RemoteSpecSource: $authorizationEnv must be a non-empty environment variable name, or null.',
            );
        }

        if ($expectedSha256 !== null && preg_match('/^[0-9a-fA-F]{64}$/', $expectedSha256) !== 1) {
            throw new InvalidArgumentException(
                'RemoteSpecSource: $expectedSha256 must be a 64-character hex SHA-256 digest, or null.',
            );
        }

        $this->expectedSha256 = $expectedSha256 !== null ? strtolower($expectedSha256) : null;
    }
}
