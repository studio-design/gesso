<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Psr\Http\Message\UriInterface;
use Studio\Gesso\Spec\RemoteSpecSource;

use function parse_url;
use function strtolower;

/**
 * An `Authorization` header value scoped to a single web origin.
 *
 * Created when a remote spec source declares `authorizationEnv`. The scope
 * is the entry document's origin — scheme, host, and effective port per
 * RFC 6454 — so nested `$ref` fetches back to the same origin (relative
 * refs, same-origin absolute refs) carry the credential, while any other
 * target never does: not a cross-host ref (even to an allowlisted host),
 * not another port on the same host, and not an `http://` downgrade of an
 * `https://` entry document, which would put the credential on the wire in
 * plaintext.
 *
 * The header value is never embedded in diagnostics; exception messages
 * carry only URLs (already redacted by {@see HttpRefLoader}).
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class RemoteAuthorization
{
    private function __construct(
        public string $headerValue,
        private string $scheme,
        private string $normalizedHost,
        private int $effectivePort,
    ) {}

    /**
     * `$url` must be an absolute `http://` / `https://` URL with a host —
     * {@see RemoteSpecSource} validates this before the
     * loader constructs the scope.
     */
    public static function forUrl(string $headerValue, string $url): self
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return new self(
            $headerValue,
            $scheme,
            HttpRefLoader::normalizeHost((string) ($parts['host'] ?? '')),
            $parts['port'] ?? self::defaultPort($scheme),
        );
    }

    public function appliesToUri(UriInterface $uri): bool
    {
        $scheme = strtolower($uri->getScheme());

        return $scheme === $this->scheme &&
            HttpRefLoader::normalizeHost($uri->getHost()) === $this->normalizedHost &&
            ($uri->getPort() ?? self::defaultPort($scheme)) === $this->effectivePort;
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'http' ? 80 : 443;
    }
}
