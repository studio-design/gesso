<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Studio\Gesso\Internal\HttpRefLoader;
use Studio\Gesso\Internal\RemoteAuthorization;

use function array_key_exists;
use function is_string;

/**
 * Carries the per-resolution state that `OpenApiRefResolver::walk()` needs
 * but which doesn't change per recursion step (source file, HTTP wiring,
 * remote-refs gate). Threading these as discrete parameters got unwieldy
 * once HTTP support added a PSR-18 client + PSR-17 factory + opt-in flag,
 * so they live on this immutable carrier instead.
 *
 * The per-resolution document cache is intentionally NOT held here — it
 * is mutated as files/URLs are loaded, so the resolver still passes it
 * by reference alongside the (immutable) context.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class RefResolutionContext
{
    /**
     * Private constructor — callers go through {@see filesystemOnly()} or
     * {@see withRemoteRefs()} so the pairing invariant
     * "client + factory + flag are all set together, or none of them are"
     * is enforced at construction time. This eliminates the
     * client-without-flag and flag-without-client failure modes that
     * would otherwise need runtime guards.
     */
    private function __construct(
        public readonly ?string $sourceFile,
        public readonly ?ClientInterface $httpClient,
        public readonly ?RequestFactoryInterface $requestFactory,
        public readonly bool $allowRemoteRefs,
        /** @var list<string> */
        public readonly array $allowedRemoteRefHosts,
        public readonly int $maxRemoteRefBytes,
        /** @var list<string> */
        public readonly array $allowedLocalRefRoots,
        public readonly ?RemoteAuthorization $remoteAuthorization = null,
        /**
         * The `$schema` declaration currently in force — the document's, or a
         * schema resource's own for its subtree — as `['$schema' => <value>]`,
         * or `[]` when nothing in the document states one. The *declaration*
         * rather than the dialect, because a value that is not a URI string is
         * not an absent one: it selects no dialect anything can read, and it
         * still has to travel with a target substituted out of that resource
         * so the converter reports it.
         *
         * @var array<string, mixed>
         */
        public readonly array $schemaDeclaration = [],
    ) {}

    /**
     * A context that can resolve internal `$ref` plus local-filesystem
     * external refs. HTTP refs reject with `RemoteRefDisallowed`.
     *
     * @param list<string> $allowedLocalRefRoots
     * @param array<string, mixed> $schemaDeclaration
     */
    public static function filesystemOnly(
        ?string $sourceFile = null,
        array $allowedLocalRefRoots = [],
        array $schemaDeclaration = [],
    ): self {
        return new self(
            $sourceFile,
            null,
            null,
            false,
            [],
            HttpRefLoader::DEFAULT_MAX_RESPONSE_BYTES,
            $allowedLocalRefRoots,
            null,
            $schemaDeclaration,
        );
    }

    /**
     * A context with HTTP `$ref` resolution enabled. The `$client` /
     * `$factory` pair is required — passing `null` for either is
     * structurally impossible via this factory.
     *
     * @param list<string> $allowedRemoteRefHosts
     * @param list<string> $allowedLocalRefRoots
     * @param array<string, mixed> $schemaDeclaration
     */
    public static function withRemoteRefs(
        ClientInterface $client,
        RequestFactoryInterface $factory,
        array $allowedRemoteRefHosts,
        ?string $sourceFile = null,
        int $maxRemoteRefBytes = HttpRefLoader::DEFAULT_MAX_RESPONSE_BYTES,
        array $allowedLocalRefRoots = [],
        ?RemoteAuthorization $remoteAuthorization = null,
        array $schemaDeclaration = [],
    ): self {
        return new self(
            $sourceFile,
            $client,
            $factory,
            true,
            $allowedRemoteRefHosts,
            $maxRemoteRefBytes,
            $allowedLocalRefRoots,
            $remoteAuthorization,
            $schemaDeclaration,
        );
    }

    /**
     * The dialect in force, or `null` when none is declared or the declared
     * value is not one this package can read — nothing may be assumed about a
     * resource whose dialect is a spec error the converter is about to report.
     */
    public function schemaDialect(): ?string
    {
        $declared = $this->schemaDeclaration['$schema'] ?? null;

        return is_string($declared) && OpenApiSchemaDialect::isSupported($declared) ? $declared : null;
    }

    /**
     * True when a `$schema` is declared but names no readable dialect. It is
     * then unknown rather than absent, so nothing that depends on knowing it —
     * applying `$ref` siblings, asserting a resource boundary — may act.
     */
    public function declaresUnreadableDialect(): bool
    {
        return array_key_exists('$schema', $this->schemaDeclaration) && $this->schemaDialect() === null;
    }

    /**
     * True when the dialect in force treats `$ref` as an in-place applicator,
     * so keywords sitting next to it apply alongside the resolved target.
     */
    public function appliesRefSiblings(): bool
    {
        $dialect = $this->schemaDialect();

        return $dialect !== null && OpenApiSchemaDialect::appliesRefSiblings($dialect);
    }

    /**
     * Return a copy with the declaration replaced. Used when a Schema Object
     * declares its own `$schema`, making it the root of a schema resource
     * whose subtree is read under that dialect instead.
     *
     * @param array<string, mixed> $schemaDeclaration
     */
    public function withSchemaDeclaration(array $schemaDeclaration): self
    {
        return new self(
            $this->sourceFile,
            $this->httpClient,
            $this->requestFactory,
            $this->allowRemoteRefs,
            $this->allowedRemoteRefHosts,
            $this->maxRemoteRefBytes,
            $this->allowedLocalRefRoots,
            $this->remoteAuthorization,
            $schemaDeclaration,
        );
    }

    /**
     * Return a copy with the source file replaced. Used when the resolver
     * descends into an external document and the relative-path base
     * shifts to that document's directory / URL. All other fields are
     * preserved verbatim — the pairing invariant cannot be invalidated
     * by this method.
     */
    public function withSourceFile(?string $sourceFile): self
    {
        return new self(
            $sourceFile,
            $this->httpClient,
            $this->requestFactory,
            $this->allowRemoteRefs,
            $this->allowedRemoteRefHosts,
            $this->maxRemoteRefBytes,
            $this->allowedLocalRefRoots,
            $this->remoteAuthorization,
            $this->schemaDeclaration,
        );
    }
}
