<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

/**
 * An `Authorization` header value scoped to a single host.
 *
 * Created when a remote spec source declares `authorizationEnv`. The scope
 * is the entry document's host: nested `$ref` fetches to the same host
 * (relative refs, same-host absolute refs) carry the credential, while a
 * cross-host `$ref` — even to another allowlisted host — never does. This
 * keeps a registry credential from leaking to unrelated servers.
 *
 * The header value is never embedded in diagnostics; exception messages
 * carry only URLs (already redacted by {@see HttpRefLoader}).
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class RemoteAuthorization
{
    /** @param string $normalizedHost pre-normalized via {@see HttpRefLoader::normalizeHost()} */
    public function __construct(
        public string $headerValue,
        public string $normalizedHost,
    ) {}

    public function appliesToHost(string $host): bool
    {
        return HttpRefLoader::normalizeHost($host) === $this->normalizedHost;
    }
}
