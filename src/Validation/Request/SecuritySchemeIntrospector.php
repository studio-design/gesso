<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Request;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * Spec-side probe for the auto-inject-dummy-credentials path in the Laravel
 * `ValidatesOpenApiSchema` trait. Walks an operation's `security` requirement
 * and reports which credentials the validator can be made to see by
 * synthesizing a dummy value — i.e. `http` + `bearer`, `apiKey` + (header /
 * cookie / query). Other scheme types (oauth2, openIdConnect, mutualTLS,
 * http+basic / http+digest) are silent-passed by the validator, so injecting
 * a fake value for them would be a lie and is deliberately skipped here.
 *
 * Classification reuses {@see SecurityValidator::classifyScheme()} directly so
 * the inject-side and validate-side rules cannot drift apart.
 *
 * Returns empty / false on malformed or unsupported spec entries rather than
 * mirroring SecurityValidator's hard-error surface: the validator is the
 * source of truth for "is this spec broken" and we do not want two layers
 * producing redundant errors.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SecuritySchemeIntrospector
{
    /**
     * Legacy bearer-only probe retained for `auto_inject_dummy_bearer`. The
     * implementation delegates to {@see self::injectableCredentialsFor()} and
     * filters the result to bearer entries — the legacy flag's narrower scope
     * is enforced by the caller, not by reproducing the spec walk.
     *
     * Returns true even when bearer appears alongside other schemes in an
     * AND-entry (e.g. `bearer + apiKey`). Injecting bearer alone won't satisfy
     * that entry, but it does silence the "Authorization header is missing"
     * noise and leaves only the actionable apiKey error for the user.
     *
     * @param array<string, mixed> $spec full spec root (for
     *                                   `components.securitySchemes` +
     *                                   root-level `security` inheritance)
     * @param array<string, mixed> $operation operation spec (for
     *                                        operation-level `security` override)
     */
    public function endpointAcceptsBearer(array $spec, array $operation): bool
    {
        foreach ($this->injectableCredentialsFor($spec, $operation) as $credential) {
            if ($credential['kind'] === 'bearer') {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the deduplicated list of credentials the trait may auto-inject
     * for this operation. Each entry tells the caller exactly what dummy value
     * to write and where:
     *
     * - `['kind' => 'bearer']` → `Authorization: Bearer <dummy>`
     * - `['kind' => 'apiKey', 'in' => 'header'|'cookie'|'query', 'name' => …]`
     *   → set the named header / cookie / query param to a dummy value
     *
     * Resolution mirrors {@see SecurityValidator::validate()}: operation-level
     * `security` wins over root-level, an explicit `security: []` opts the
     * operation out of all authentication and yields an empty list, and a
     * silent (missing) `security` on both levels also yields an empty list.
     *
     * AND/OR semantics are intentionally not modelled here. Over-injection on
     * an OR-endpoint is harmless because the spec already accepts either
     * alternative; under-injection on an AND-endpoint would re-introduce the
     * exact false-fail this feature exists to remove. Listing every
     * supported scheme (and letting the caller decide whether to actually
     * write) is the safer default. Apparent duplicates (same kind / location /
     * name appearing across multiple OR entries, or repeated definitions in
     * `components.securitySchemes`) are deduplicated so the caller never
     * double-writes a header or cookie.
     *
     * `apiKey in: query` injection is safe under the request validator's
     * current tolerant query-parameter handling. A strict-extras mode would
     * need to teach itself about security-driven injects; tracked separately
     * if that mode ships.
     *
     * @param array<string, mixed> $spec full spec root (for
     *                                   `components.securitySchemes` +
     *                                   root-level `security` inheritance)
     * @param array<string, mixed> $operation operation spec (for
     *                                        operation-level `security` override)
     *
     * @return list<array{kind: 'apiKey', in: 'cookie'|'header'|'query', name: string}|array{kind: 'bearer'}>
     */
    public function injectableCredentialsFor(array $spec, array $operation): array
    {
        $security = array_key_exists('security', $operation)
            ? $operation['security']
            : ($spec['security'] ?? null);

        if (!is_array($security) || $security === []) {
            return [];
        }

        $schemes = $spec['components']['securitySchemes'] ?? [];
        if (!is_array($schemes)) {
            return [];
        }

        /** @var list<array{kind: 'apiKey', in: 'cookie'|'header'|'query', name: string}|array{kind: 'bearer'}> $credentials */
        $credentials = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($security as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach ($entry as $schemeName => $_scopes) {
                if (!is_string($schemeName)) {
                    continue;
                }

                $definition = $schemes[$schemeName] ?? null;
                if (!is_array($definition)) {
                    continue;
                }

                $classification = SecurityValidator::classifyScheme($definition);

                if ($classification->kind === SchemeKind::Bearer) {
                    if (!isset($seen['bearer'])) {
                        $seen['bearer'] = true;
                        $credentials[] = ['kind' => 'bearer'];
                    }

                    continue;
                }

                if ($classification->kind === SchemeKind::ApiKey) {
                    /** @var 'cookie'|'header'|'query' $in */
                    $in = $classification->apiKeyIn;
                    /** @var string $name */
                    $name = $classification->apiKeyName;

                    $key = "apiKey:{$in}:{$name}";
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $credentials[] = ['kind' => 'apiKey', 'in' => $in, 'name' => $name];
                }
            }
        }

        return $credentials;
    }
}
