<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecResolver;

/**
 * Process-wide defaults for the validation-policy settings that previously
 * existed only as Laravel config keys (issue #502, additive half):
 * `max_errors`, `skip_response_codes`,
 * `skip_request_validation_response_codes`, and `default_spec`.
 *
 * Configured by {@see OpenApiCoverageExtension} from the matching
 * `gesso.php` keys. Read by the
 * {@see OpenApiRequestValidator} / {@see OpenApiResponseValidator}
 * constructors when the caller omits the corresponding argument, and by
 * {@see OpenApiSpecResolver::openApiSpecFallback()}. An explicit constructor
 * argument always wins. The PSR-7 and Symfony trait hooks default to these
 * values (issue #563), so overriding a hook still pins a per-suite setting;
 * the Laravel adapter reads the same keys from `config('gesso.*')`.
 *
 * Static singleton mirroring {@see DiscriminatorEnforcement} so the
 * defaults reach the validators without giving them a second constructor.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ValidationPolicyDefaults
{
    private static ?int $maxErrors = null;

    /** @var null|string[] */
    private static ?array $skipResponseCodes = null;

    /** @var null|string[] */
    private static ?array $skipRequestValidationResponseCodes = null;
    private static ?string $defaultSpec = null;

    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Overwrite all four defaults for this process. `null` means "not
     * configured": the matching reader falls back to the constructor default
     * it stands in for, so an absent `gesso.php` key changes nothing.
     *
     * @param null|string[] $skipResponseCodes
     * @param null|string[] $skipRequestValidationResponseCodes
     *
     * @internal
     */
    public static function configure(
        ?int $maxErrors = null,
        ?array $skipResponseCodes = null,
        ?array $skipRequestValidationResponseCodes = null,
        ?string $defaultSpec = null,
    ): void {
        self::$maxErrors = $maxErrors;
        self::$skipResponseCodes = $skipResponseCodes;
        self::$skipRequestValidationResponseCodes = $skipRequestValidationResponseCodes;
        self::$defaultSpec = $defaultSpec;
    }

    /**
     * @internal
     */
    public static function maxErrors(): int
    {
        return self::$maxErrors ?? 20;
    }

    /**
     * @return string[]
     *
     * @internal
     */
    public static function skipResponseCodes(): array
    {
        return self::$skipResponseCodes ?? OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES;
    }

    /**
     * Unconfigured default is `[]`, not
     * {@see OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES}:
     * direct constructor callers stay strict, exactly as before this class
     * existed. The adapters pass their `['422', '400']` default as `$default`.
     *
     * @param string[] $default returned when the setting is not configured
     *
     * @return string[]
     *
     * @internal
     */
    public static function skipRequestValidationResponseCodes(array $default = []): array
    {
        return self::$skipRequestValidationResponseCodes ?? $default;
    }

    /**
     * @internal
     */
    public static function defaultSpec(): string
    {
        return self::$defaultSpec ?? '';
    }

    /**
     * Reset to unconfigured. Test seam mirroring
     * {@see DiscriminatorEnforcement::reset()}.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::configure();
    }
}
