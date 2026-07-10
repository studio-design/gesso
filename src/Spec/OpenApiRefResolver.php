<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\Internal\ExternalRefLoader;
use Studio\OpenApiContractTesting\Internal\HttpRefLoader;

use function array_is_list;
use function array_key_exists;
use function array_pop;
use function explode;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;

/**
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiRefResolver
{
    /**
     * Internal provenance attached to direct component-schema references in
     * `oneOf` / `anyOf`. Eager reference resolution otherwise erases the
     * component name needed for OpenAPI discriminator implicit mappings.
     */
    public const IMPLICIT_SCHEMA_NAME_EXTENSION = 'x-studio-openapi-contract-testing-implicit-schema-name';

    /**
     * Keys whose value is opaque user data per OpenAPI 3.x and JSON Schema —
     * a `$ref` literal nested under one of these keys is a data field, not a
     * Reference Object. The walker MUST NOT descend into them.
     *
     * The match is by key name only; the surrounding parent object's type is
     * not consulted. That works because wherever these keys legitimately
     * appear in a 3.x spec (Schema Object, Parameter Object, Header Object,
     * MediaType Object, Server Variable Object) their value is opaque sample
     * or default data. Spec-typed-as-string positions (e.g. Server Variable's
     * `default`) are unaffected — the carve-out is safe-by-default there
     * because a scalar value isn't an array and is never walked anyway; the
     * pinning test (`preserves_ref_key_inside_server_variable_default`)
     * exercises the malformed-but-array-shaped case to lock the structural
     * detection in place against future refactors.
     *
     * `examples` is handled separately because it is two-shaped: a list of
     * opaque values for the Schema Object 3.1 form, or a map of Example
     * Objects (which MAY themselves be Reference Objects) for the Parameter,
     * Header, MediaType, RequestBody, and Response forms.
     *
     * @var list<string>
     */
    private const ALWAYS_OPAQUE_KEYS = ['default', 'example', 'enum', 'const'];

    /**
     * Keys whose value is a map of USER-DEFINED names → entries — i.e. keys
     * inside this map are arbitrary strings the spec author chose, not OAS
     * keywords. The walker treats them just like the existing `properties`
     * carve-out: keys at this level are names, not directives, and the
     * opaque-key carve-out must NOT fire here (a schema named `default`
     * inside `components.schemas` is just a schema name, and a property
     * named `default` inside `properties` is just a property name).
     *
     * Includes the historical `properties` / `patternProperties` for which
     * the carve-out was originally introduced. Expanding the set covers the
     * other OAS-defined user-named maps so behavior is consistent across
     * all of them.
     *
     * @var list<string>
     */
    private const USER_NAMED_MAP_KEYS = [
        'properties',
        'patternProperties',
        '$defs',
        'dependentSchemas',
        'schemas',
        'responses',
        'parameters',
        'requestBodies',
        'headers',
        'securitySchemes',
        'links',
        'callbacks',
        'pathItems',
        'paths',
        'definitions',
    ];

    /**
     * Resolve every `$ref` entry in the spec in place and return the same
     * array. Any structural problem with a `$ref` throws
     * `InvalidOpenApiSpecException` so users get one actionable error at
     * load time instead of a cryptic opis failure surfacing deep inside
     * validation. See `InvalidOpenApiSpecReason` for the exhaustive list of
     * failure categories produced here.
     *
     * The input array is mutated: on a successful resolve the returned value
     * is the same array with `$ref` nodes substituted. On throw, the partially
     * mutated state is discarded at the caller (`OpenApiSpecLoader::load()`
     * only caches the result after `resolve()` returns cleanly).
     *
     * Pass `$sourceFile` (the absolute path of the spec file being loaded)
     * to enable resolution of external `$ref` targets located on the local
     * filesystem (e.g. `./schemas/pet.yaml`). When `$sourceFile` is `null`,
     * any **filesystem-relative** `$ref` triggers `LocalRefRequiresSourceFile`.
     * HTTP(S) refs are dispatched via `$httpClient` regardless of `$sourceFile`.
     *
     * Pass `$httpClient` + `$requestFactory` (PSR-18 / PSR-17) AND
     * `$allowRemoteRefs: true` to permit HTTP(S) `$ref` resolution.
     * Without both, HTTP refs throw `RemoteRefDisallowed` (flag off) or
     * `HttpClientNotConfigured` (flag on but client/factory missing).
     * `file://` URLs are always rejected to keep path-resolution rules
     * predictable.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>
     *
     * @throws InvalidOpenApiSpecException when a `$ref` cannot be resolved
     */
    public static function resolve(
        array $spec,
        ?string $sourceFile = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        bool $allowRemoteRefs = false,
    ): array {
        // OpenApiSpecLoader::configure() catches this earlier with an
        // InvalidArgumentException; this guard is for callers that
        // construct the resolver directly. Surface as the same reason
        // the per-ref path would fire so consumers can branch on a
        // single enum case.
        if ($allowRemoteRefs && ($httpClient === null || $requestFactory === null)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::HttpClientNotConfigured,
                'OpenApiRefResolver::resolve(): allowRemoteRefs requires both '
                . 'a PSR-18 ClientInterface and a PSR-17 RequestFactoryInterface.',
            );
        }

        // After the guard above, allowRemoteRefs:true implies non-null
        // client + factory.
        $context = $allowRemoteRefs
            ? RefResolutionContext::withRemoteRefs($httpClient, $requestFactory, $sourceFile)
            : RefResolutionContext::filesystemOnly($sourceFile);

        // $root is a frozen snapshot used for pointer lookups. PHP array
        // copy-on-write keeps it untouched as we mutate $spec via $node refs.
        $root = $spec;
        // Per-resolution external file/URL cache, keyed by canonical absolute
        // path or canonical URL. Sibling refs into the same target decode it once.
        $documentCache = [];
        self::walk($spec, $root, [], false, $context, $documentCache);

        return $spec;
    }

    /**
     * Resolve a local JSON Pointer (`#/...` form) against an already-loaded,
     * fully-resolved root document and return `[found, value]`.
     *
     * Public seam over the private {@see self::lookup()} so other components
     * that need pointer resolution — notably {@see OpenApiSchemaConverter}
     * lowering `discriminator.mapping` (Issue #262) — reuse the exact same
     * segment-unescape rules (`~0` / `~1`, percent-decode) instead of
     * re-implementing them and drifting. `found=false` distinguishes a
     * missing segment from a literal `null` leaf.
     *
     * @param array<string, mixed> $root
     *
     * @return array{0: bool, 1: mixed}
     */
    public static function resolvePointer(string $pointer, array $root): array
    {
        return self::lookup($pointer, $root);
    }

    /**
     * Escape a single path segment for embedding in a JSON Pointer — the
     * inverse of {@see self::unescapePointerSegment()}. `~` must be encoded
     * before `/` so a literal `/` does not turn into `~1` and then have its
     * `~` re-encoded. Used to build `#/components/schemas/{name}` from the
     * OAS discriminator-mapping bare-name shorthand, where `{name}` may
     * (exotically) contain `~` or `/`.
     */
    public static function escapePointerSegment(string $segment): string
    {
        $segment = str_replace('~', '~0', $segment);

        return str_replace('/', '~1', $segment);
    }

    /**
     * @param array<int|string, mixed> $node
     * @param array<string, mixed> $root currently-walked document's root
     * @param list<string> $chain canonical pointer-refs already on the resolution stack — used to detect cycles
     * @param bool $insideUserNamedMap true when `$node` is the direct children
     *                                 dict of a user-named map (`properties`,
     *                                 `components.schemas`, `paths`, etc. —
     *                                 see {@see self::USER_NAMED_MAP_KEYS}).
     *                                 At this level the keys are arbitrary
     *                                 user-chosen names — not OAS keywords —
     *                                 so neither the `$ref`-at-top guard nor
     *                                 the opaque-key carve-out fire. The flag
     *                                 resets one level deeper, where each
     *                                 named entry is itself an OAS object.
     * @param array<string, array<string, mixed>> $documentCache external document cache for this resolution
     */
    private static function walk(
        array &$node,
        array $root,
        array $chain,
        bool $insideUserNamedMap,
        RefResolutionContext $context,
        array &$documentCache,
        bool $captureImplicitSchemaName = false,
    ): void {
        // Reserve the provenance key for resolver-generated data. Removing a
        // user-authored value before descent prevents an inline schema from
        // spoofing an implicit discriminator mapping.
        unset($node[self::IMPLICIT_SCHEMA_NAME_EXTENSION]);

        if (!$insideUserNamedMap && array_key_exists('$ref', $node)) {
            $ref = self::assertStringRef($node['$ref']);
            $implicitSchemaName = $captureImplicitSchemaName
                ? self::implicitSchemaNameFromRef($ref)
                : null;
            self::resolveRef($node, $ref, $root, $chain, $context, $documentCache);
            if ($implicitSchemaName !== null) {
                $node[self::IMPLICIT_SCHEMA_NAME_EXTENSION] = $implicitSchemaName;
            }

            return;
        }

        foreach ($node as $key => &$child) {
            if (!is_array($child)) {
                continue;
            }

            // Inside a user-named map, keys are arbitrary names chosen by
            // the spec author — a property literally named `default` is a
            // property, not a JSON Schema default-value declaration. Skip
            // both the opaque-key carve-out and the `examples` dispatch
            // here; both only make sense when the key is being treated as
            // an OAS keyword.
            if (!$insideUserNamedMap) {
                // Opaque user data per OAS 3.x / JSON Schema (`default`,
                // `example`, `enum` items, `const`): the value is literal
                // sample data and a `$ref` key nested inside is a data
                // field, not a Reference Object. Resolving it would corrupt
                // the data, and — if remote refs are enabled — fetch
                // attacker-controlled URLs as a side effect. The invariant
                // is "opaque means opaque": no descent, no ref
                // interpretation, ever.
                if (in_array($key, self::ALWAYS_OPAQUE_KEYS, true)) {
                    continue;
                }

                // `examples` is shape-dependent. The two OAS uses disagree:
                //  - Schema Object 3.1: an array of opaque sample values.
                //  - Parameter / Header / MediaType / RequestBody / Response:
                //    a map keyed by example name, where each entry is an
                //    Example Object (or a Reference Object pointing at one).
                // Disambiguate by shape — the two valid containers are
                // required to be a list and a map respectively, so
                // `array_is_list` is a sufficient discriminator that keeps
                // the walker stateless.
                if ($key === 'examples') {
                    if (array_is_list($child)) {
                        // List form = Schema 3.1 array of opaque values; skip.
                        continue;
                    }

                    // Map form = Example Object entries; dispatch to the
                    // helper that preserves Reference Object semantics at
                    // the entry root while treating each entry's `value`
                    // field as opaque.
                    self::walkExamplesMap($child, $root, $chain, $context, $documentCache);

                    continue;
                }

                if (in_array($key, ['oneOf', 'anyOf'], true) && array_is_list($child)) {
                    foreach ($child as &$alternative) {
                        if (is_array($alternative)) {
                            self::walk($alternative, $root, $chain, false, $context, $documentCache, true);
                        }
                    }
                    unset($alternative);

                    continue;
                }
            }

            // additionalProperties is intentionally excluded: its value is a single
            // schema (not a dict of schemas), so a direct $ref under it is a
            // legitimate Reference Object that must resolve.
            $childInsideUserNamedMap = $insideUserNamedMap
                ? false
                : in_array($key, self::USER_NAMED_MAP_KEYS, true);
            self::walk($child, $root, $chain, $childInsideUserNamedMap, $context, $documentCache);
        }
        unset($child);
    }

    private static function implicitSchemaNameFromRef(string $ref): ?string
    {
        $prefix = '#/components/schemas/';
        if (!str_starts_with($ref, $prefix)) {
            return null;
        }

        $segment = substr($ref, strlen($prefix));
        if ($segment === '' || str_contains($segment, '/')) {
            return null;
        }

        return self::unescapePointerSegment($segment);
    }

    /**
     * Narrow a `$ref` value to a string or throw `NonStringRef`. Centralized
     * so the main walker and the examples-map helper produce identical
     * diagnostics — drift between the two sites would let a non-string
     * `$ref` in a Parameter/Header/MediaType `examples` entry surface a less
     * informative error than one in any other position.
     */
    private static function assertStringRef(mixed $ref): string
    {
        if (!is_string($ref)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonStringRef,
                sprintf('Invalid $ref: expected string, got %s', get_debug_type($ref)),
            );
        }

        return $ref;
    }

    /**
     * Walk the map form of `examples` (Parameter / Header / MediaType /
     * RequestBody / Response). Each entry is either an Example Object or a
     * Reference Object pointing at one. Reference Objects resolve normally;
     * Example Objects' `value` field is opaque user data and is NOT walked.
     * Non-array entries (a malformed scalar in the map) are passed through
     * unchanged — consistent with how the main `walk()` treats non-array
     * children; spec-shape validation is not the resolver's responsibility.
     *
     * Kept as a dedicated helper because the inner shape — "$ref at entry
     * root MAY be a Reference Object, but `value` underneath an entry is
     * always opaque" — does not fit the flat key-list carve-out used by the
     * main walk().
     *
     * @param array<int|string, mixed> $map
     * @param array<string, mixed> $root
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function walkExamplesMap(
        array &$map,
        array $root,
        array $chain,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        foreach ($map as &$entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Reference Object case: an examples-map entry MAY be `{$ref: ...}`
            // per OAS 3.x to share a definition from `components.examples`.
            if (array_key_exists('$ref', $entry)) {
                $ref = self::assertStringRef($entry['$ref']);
                self::resolveRef($entry, $ref, $root, $chain, $context, $documentCache);

                continue;
            }

            // Example Object: walk children EXCEPT `value` (opaque). The
            // `$insidePropertiesMap` argument is deliberately false here —
            // an Example Object's children are not a properties map, so a
            // child literally named `$ref` (unusual, but legal in a vendor
            // extension like `x-shared: { $ref: '#/...' }`) is a real
            // Reference Object that must resolve.
            foreach ($entry as $childKey => &$child) {
                if (!is_array($child)) {
                    continue;
                }
                if ($childKey === 'value') {
                    continue;
                }
                self::walk($child, $root, $chain, false, $context, $documentCache);
            }
            unset($child);
        }
        unset($entry);
    }

    /**
     * @param array<int|string, mixed> $node
     * @param array<string, mixed> $root
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveRef(
        array &$node,
        string $ref,
        array $root,
        array $chain,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        if ($ref === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::EmptyRef,
                'Invalid $ref: empty string is not a reference',
                ref: $ref,
            );
        }

        if ($ref === '#/' || $ref === '#') {
            // A bare root pointer substitutes the entire spec in place,
            // which triggers unbounded recursion before cycle detection
            // can help. Reject with a specific message so the author
            // doesn't chase a confusing "Circular" error.
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RootPointerRef,
                'Invalid $ref: root pointer "' . $ref . '" is not a reference to a definition',
                ref: $ref,
            );
        }

        if (str_starts_with($ref, 'file://')) {
            // Reject `file://` separately from ordinary path refs so a
            // visual scan of the spec doesn't gloss over it. Path-traversal
            // sandboxing for ordinary paths is intentionally absent — see
            // ExternalRefLoader's class-level docblock for the trust model.
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::FileSchemeNotSupported,
                sprintf(
                    '`file://` $ref is not supported: %s. '
                    . 'Use a relative or absolute filesystem path instead.',
                    $ref,
                ),
                ref: $ref,
            );
        }

        if (str_starts_with($ref, 'http://') || str_starts_with($ref, 'https://')) {
            self::resolveHttpRef($node, $ref, $chain, $context, $documentCache);

            return;
        }

        if (str_starts_with($ref, '#/')) {
            self::resolveInternalRef($node, $ref, $root, $chain, $context, $documentCache);

            return;
        }

        if (str_starts_with($ref, '#')) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BareFragmentRef,
                sprintf('Invalid $ref: bare fragment %s is not a JSON Pointer (expected "#/..." form)', $ref),
                ref: $ref,
            );
        }

        if ($context->sourceFile === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::LocalRefRequiresSourceFile,
                sprintf(
                    'External $ref %s cannot be resolved because the resolver was '
                    . 'invoked without a source file. Pass $sourceFile to OpenApiRefResolver::resolve() '
                    . '(OpenApiSpecLoader does this automatically).',
                    $ref,
                ),
                ref: $ref,
            );
        }

        // RFC 3986 base resolution: when the current document was loaded
        // over HTTP(S), a relative ref resolves against that URL — not
        // against any local filesystem path. Without this branch, dirname()
        // on the URL would produce nonsense like `dirname('https:')` and
        // the user would see a confusing LocalRefNotFound.
        if (str_starts_with($context->sourceFile, 'http://') || str_starts_with($context->sourceFile, 'https://')) {
            $resolvedUrl = self::resolveRelativeUrl($context->sourceFile, $ref);
            self::resolveHttpRef($node, $resolvedUrl, $chain, $context, $documentCache);

            return;
        }

        self::resolveExternalRef($node, $ref, $chain, $context, $documentCache);
    }

    /**
     * Minimal RFC 3986 reference resolution: combine a relative `$ref`
     * with an HTTP(S) base URL. Handles absolute paths (`/foo`),
     * dot-segment normalisation (`./`, `../`), and falls back to the
     * raw ref when it's already an absolute URL.
     *
     * Not a full RFC 3986 implementation — query strings and fragments
     * on the base URL are dropped before joining (matches what spec
     * authors expect when their base is a JSON document URL). For
     * pathological inputs the result is whatever `parse_url` produces;
     * tests pin the common cases.
     */
    private static function resolveRelativeUrl(string $baseUrl, string $relativeRef): string
    {
        if (str_starts_with($relativeRef, 'http://') || str_starts_with($relativeRef, 'https://')) {
            return $relativeRef;
        }

        $baseParts = parse_url($baseUrl);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return $relativeRef;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $userInfo = isset($baseParts['user'])
            ? $baseParts['user'] . (isset($baseParts['pass']) ? ':' . $baseParts['pass'] : '') . '@'
            : '';

        $authority = $userInfo . $host . $port;

        if (str_starts_with($relativeRef, '/')) {
            return sprintf('%s://%s%s', $scheme, $authority, $relativeRef);
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseDir = self::dirnameUrl($basePath);

        $combined = $baseDir . '/' . $relativeRef;
        $normalised = self::normaliseDotSegments($combined);

        return sprintf('%s://%s%s', $scheme, $authority, $normalised);
    }

    private static function dirnameUrl(string $path): string
    {
        $lastSlash = strrpos($path, '/');
        if ($lastSlash === false || $lastSlash === 0) {
            return '';
        }

        return substr($path, 0, $lastSlash);
    }

    /**
     * Apply RFC 3986 §5.2.4 dot-segment removal. Implemented inline
     * (rather than reaching for a library) because the rules are short
     * and the surface area we hit is narrow: relative `./pet.yaml`,
     * `../shared/error.json`, and combinations. Edge cases like a leading
     * `..` past the root collapse to root, matching browser behaviour.
     */
    private static function normaliseDotSegments(string $path): string
    {
        $segments = explode('/', $path);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($resolved);
            } elseif ($segment !== '.' && $segment !== '') {
                $resolved[] = $segment;
            }
        }

        return '/' . implode('/', $resolved);
    }

    /**
     * @param array<int|string, mixed> $node
     * @param array<string, mixed> $root
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveInternalRef(
        array &$node,
        string $ref,
        array $root,
        array $chain,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        // Canonicalize internal refs against the current document so cycles
        // that span files are detected against per-file pointers, not the
        // raw `#/...` string (which is ambiguous across documents).
        $chainKey = self::canonicalChainKey($context->sourceFile, $ref);

        if (in_array($chainKey, $chain, true)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::CircularRef,
                sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $chainKey])),
                ref: $ref,
            );
        }

        [$found, $target] = self::lookup($ref, $root);
        if (!$found) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnresolvableRef,
                sprintf('Unresolvable $ref: target not found for %s', $ref),
                ref: $ref,
            );
        }

        if (!is_array($target)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::NonObjectRefTarget,
                sprintf('$ref target is not an object: %s points to a %s value', $ref, get_debug_type($target)),
                ref: $ref,
            );
        }

        // Push canonicalized ref onto the chain before recursing so nested
        // self-references are detected as cycles; then replace the node
        // entirely. Sibling keys alongside $ref are dropped per OAS 3.0
        // ("any sibling elements of a $ref are ignored"), which is a safe
        // subset of 3.1.
        self::walk($target, $root, [...$chain, $chainKey], false, $context, $documentCache);
        $node = $target;
    }

    /**
     * @param array<int|string, mixed> $node
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveExternalRef(
        array &$node,
        string $ref,
        array $chain,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        [$pathPart, $fragment, $hadHash] = self::splitRef($ref);

        if ($pathPart === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::EmptyRef,
                sprintf('Invalid $ref: %s has an empty path part', $ref),
                ref: $ref,
            );
        }

        // A trailing `#` with nothing after it (`./pet.yaml#`) is
        // ambiguous. Reject it explicitly so the author makes the
        // intent clear: drop the `#` for whole-file, or write `#/...`
        // for a JSON Pointer.
        if ($hadHash && $fragment === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BareFragmentRef,
                sprintf('Invalid $ref: %s has an empty fragment after `#`', $ref),
                ref: $ref,
            );
        }

        // sourceFile non-null asserted by the caller (resolveRef).
        /** @var string $sourceFile */
        $sourceFile = $context->sourceFile;
        $document = ExternalRefLoader::loadDocument($pathPart, $sourceFile, $documentCache);

        self::descendIntoLoadedDocument(
            $node,
            $ref,
            $fragment,
            $chain,
            $document->canonicalIdentifier,
            $document->decoded,
            $context->withSourceFile($document->canonicalIdentifier),
            $documentCache,
        );
    }

    /**
     * @param array<int|string, mixed> $node
     * @param list<string> $chain
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function resolveHttpRef(
        array &$node,
        string $ref,
        array $chain,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        if (!$context->allowRemoteRefs) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefDisallowed,
                sprintf(
                    'HTTP(S) $ref is disallowed: %s. '
                    . 'Pass allowRemoteRefs: true to OpenApiSpecLoader::configure() to enable it.',
                    $ref,
                ),
                ref: $ref,
            );
        }

        if ($context->httpClient === null || $context->requestFactory === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::HttpClientNotConfigured,
                sprintf(
                    'HTTP $ref %s requires a PSR-18 ClientInterface and PSR-17 '
                    . 'RequestFactoryInterface. Pass them to OpenApiSpecLoader::configure().',
                    $ref,
                ),
                ref: $ref,
            );
        }

        [$urlPart, $fragment, $hadHash] = self::splitRef($ref);

        if ($urlPart === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::EmptyRef,
                sprintf('Invalid $ref: %s has an empty URL part', $ref),
                ref: $ref,
            );
        }

        if ($hadHash && $fragment === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::BareFragmentRef,
                sprintf('Invalid $ref: %s has an empty fragment after `#`', $ref),
                ref: $ref,
            );
        }

        try {
            $document = HttpRefLoader::loadDocument(
                $urlPart,
                $context->httpClient,
                $context->requestFactory,
                $documentCache,
            );
        } catch (InvalidOpenApiSpecException $e) {
            // Re-wrap remote-fetch failures with the resolution chain so
            // a failure 4 hops deep into a $ref tree shows the path that
            // got us there, not just the leaf URL.
            if ($e->reason === InvalidOpenApiSpecReason::RemoteRefFetchFailed && $chain !== []) {
                throw new InvalidOpenApiSpecException(
                    $e->reason,
                    sprintf('%s (via $ref chain: %s)', $e->getMessage(), implode(' -> ', $chain)),
                    ref: $e->ref,
                    previous: $e,
                );
            }

            throw $e;
        }

        self::descendIntoLoadedDocument(
            $node,
            $ref,
            $fragment,
            $chain,
            $document->canonicalIdentifier,
            $document->decoded,
            $context->withSourceFile($document->canonicalIdentifier),
            $documentCache,
        );
    }

    /**
     * Shared post-load step for both filesystem and HTTP external refs.
     * Performs cycle detection, fragment lookup, type guard, and recursion
     * with the source-file context shifted to the loaded document.
     *
     * @param array<int|string, mixed> $node
     * @param list<string> $chain
     * @param array<string, mixed> $newRoot
     * @param array<string, array<string, mixed>> $documentCache
     */
    private static function descendIntoLoadedDocument(
        array &$node,
        string $ref,
        string $fragment,
        array $chain,
        string $absoluteUri,
        array $newRoot,
        RefResolutionContext $context,
        array &$documentCache,
    ): void {
        if ($fragment !== '') {
            $internalRef = '#' . $fragment;
            $chainKey = self::canonicalChainKey($absoluteUri, $internalRef);

            if (in_array($chainKey, $chain, true)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::CircularRef,
                    sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $chainKey])),
                    ref: $ref,
                );
            }

            [$found, $target] = self::lookup($internalRef, $newRoot);
            if (!$found) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::UnresolvableRef,
                    sprintf('Unresolvable $ref: fragment %s not found in %s', $fragment, $absoluteUri),
                    ref: $ref,
                );
            }

            if (!is_array($target)) {
                throw new InvalidOpenApiSpecException(
                    InvalidOpenApiSpecReason::NonObjectRefTarget,
                    sprintf('$ref target is not an object: %s points to a %s value', $ref, get_debug_type($target)),
                    ref: $ref,
                );
            }

            self::walk($target, $newRoot, [...$chain, $chainKey], false, $context, $documentCache);
            $node = $target;

            return;
        }

        // Whole-file/URL ref: chain key is the canonical absolute identifier;
        // the document root replaces the node, and walking continues against
        // that root.
        $chainKey = $absoluteUri;
        if (in_array($chainKey, $chain, true)) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::CircularRef,
                sprintf('Circular $ref detected: %s', implode(' -> ', [...$chain, $chainKey])),
                ref: $ref,
            );
        }

        $target = $newRoot;
        self::walk($target, $newRoot, [...$chain, $chainKey], false, $context, $documentCache);
        $node = $target;
    }

    /**
     * Split a `$ref` like `./schemas/pet.yaml#/components/schemas/Pet`
     * into path part, fragment (without the leading `#`), and a flag
     * marking whether `#` was present at all. Callers need the flag to
     * distinguish `./pet.yaml` (no `#`, whole-file) from `./pet.yaml#`
     * (empty fragment, ambiguous and rejected).
     *
     * @return array{0: string, 1: string, 2: bool}
     */
    private static function splitRef(string $ref): array
    {
        $hashPos = strpos($ref, '#');
        if ($hashPos === false) {
            return [$ref, '', false];
        }

        return [substr($ref, 0, $hashPos), substr($ref, $hashPos + 1), true];
    }

    private static function canonicalChainKey(?string $sourceFile, string $ref): string
    {
        return ($sourceFile ?? '') . $ref;
    }

    /**
     * Returns `[found, value]` where `found` disambiguates a missing segment
     * from a literal `null` leaf — both of which could otherwise be the same
     * `null` return and silently misroute the error message.
     *
     * @param array<string, mixed> $root
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function lookup(string $ref, array $root): array
    {
        $pointer = substr($ref, 2);
        if ($pointer === '') {
            return [true, $root];
        }

        $segments = explode('/', $pointer);

        $node = $root;
        foreach ($segments as $segment) {
            $segment = self::unescapePointerSegment($segment);

            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return [false, null];
            }

            $node = $node[$segment];
        }

        return [true, $node];
    }

    /**
     * `~1` must be decoded before `~0` so a literal `~1` stored in the key
     * round-trips correctly. `rawurldecode` runs first so percent-encoded
     * segments produced by URL-aware tooling also resolve.
     */
    private static function unescapePointerSegment(string $segment): string
    {
        $segment = rawurldecode($segment);
        $segment = str_replace('~1', '/', $segment);

        return str_replace('~0', '~', $segment);
    }
}
