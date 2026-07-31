<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Request;

use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;

use function in_array;

/**
 * Process-global registry of security-scheme names the consumer has
 * acknowledged as unvalidatable (issue #445).
 *
 * {@see SecurityValidator} emits a one-shot `E_USER_WARNING` when a request
 * is secured by a scheme it cannot enforce (`oauth2`, `openIdConnect`,
 * `mutualTLS`, `http` + non-`bearer`). That warning is correct, but before
 * this registry the only way to say "understood, this scheme is covered by a
 * separate test" was the global PHP error handler — which under Laravel turns
 * the warning into an order-dependent test failure. Acknowledgement is keyed
 * by `components.securitySchemes` name (the same key the warning dedup uses)
 * so unrelated schemes — including newly introduced ones — keep warning.
 *
 * Static singleton mirroring {@see DiscriminatorEnforcement}
 * so the acknowledgement can reach {@see SecurityValidator} without changing
 * SemVer-frozen public constructors. Configured by
 * {@see OpenApiCoverageExtension} (PHPUnit) and by the Laravel
 * `ValidatesOpenApiSchema` trait; read by {@see SecurityValidator}.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class AcknowledgedSecuritySchemes
{
    /** @var list<string> */
    private static array $schemeNames = [];

    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Replace the acknowledged scheme-name list for this process.
     *
     * @param list<string> $schemeNames `components.securitySchemes` keys
     *
     * @internal
     */
    public static function configure(array $schemeNames): void
    {
        self::$schemeNames = $schemeNames;
    }

    /**
     * @internal Read by {@see SecurityValidator} when deciding whether to
     * emit the silent-pass warning for an unvalidatable scheme.
     */
    public static function isAcknowledged(string $schemeName): bool
    {
        return in_array($schemeName, self::$schemeNames, true);
    }

    /**
     * @return list<string>
     *
     * @internal Read by {@see SecurityValidator} for the rot checks (an
     * acknowledged name that is absent from the spec, or that the validator
     * can actually enforce, is reported instead of silently accepted).
     */
    public static function names(): array
    {
        return self::$schemeNames;
    }

    /**
     * Reset to the default (nothing acknowledged). Test seam mirroring
     * {@see DiscriminatorEnforcement::reset()}.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$schemeNames = [];
    }
}
