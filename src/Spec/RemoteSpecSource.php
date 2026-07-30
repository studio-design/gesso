<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use const PHP_URL_HOST;

use InvalidArgumentException;

use function parse_url;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtolower;
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
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSpecSource: URL must start with http:// or https://, got `%s`.',
                $url,
            ));
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === false || $host === null || $host === '') {
            throw new InvalidArgumentException(sprintf(
                'RemoteSpecSource: URL has no parseable host: `%s`.',
                $url,
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
