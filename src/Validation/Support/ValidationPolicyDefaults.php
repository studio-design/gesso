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
 * Configured by {@see OpenApiCoverageExtension} from the like-named
 * phpunit.xml extension parameters. Read by the
 * {@see OpenApiRequestValidator} / {@see OpenApiResponseValidator}
 * constructors when the caller omits the corresponding argument, and by
 * {@see OpenApiSpecResolver::openApiSpecFallback()}. An explicit constructor
 * argument always wins, and the framework adapters (Laravel config, PSR-7 /
 * Symfony trait hooks) pass explicit arguments from their own configuration
 * surfaces — these defaults only reach validators built without one, i.e.
 * the framework-agnostic PHPUnit path.
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
     * it stands in for, so an absent phpunit.xml parameter changes nothing.
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
     * existed. The `['422', '400']` default belongs to the adapters.
     *
     * @return string[]
     *
     * @internal
     */
    public static function skipRequestValidationResponseCodes(): array
    {
        return self::$skipRequestValidationResponseCodes ?? [];
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
