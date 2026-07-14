<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Compatibility;

use function class_alias;
use function spl_autoload_register;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Registers the time-bounded v1-to-v2 namespace migration aliases.
 *
 * @internal Composer autoload bootstrap; not part of the library API.
 */
final class GessoAliasLoader
{
    /**
     * Public v1 types that consumers may reference through the future Gesso
     * namespace before upgrading to the v2 package.
     *
     * Keep this allowlist aligned with the non-@internal public API inventory.
     * Internal types are deliberately excluded so the migration aid does not
     * promote implementation details into a new public contract.
     *
     * @var array<string, true>
     */
    private const PUBLIC_TYPES = [
        'Attribute\\BoundToOpenApiEnum' => true,
        'Attribute\\OpenApiSpec' => true,
        'Attribute\\SkipOpenApi' => true,
        'Coverage\\ConsoleCoverageRenderer' => true,
        'Coverage\\CoverageSidecarEnvelope' => true,
        'Coverage\\CoverageSidecarReader' => true,
        'Coverage\\CoverageSidecarWriter' => true,
        'Coverage\\CoverageThresholdEvaluator' => true,
        'Coverage\\EndpointCoverageState' => true,
        'Coverage\\HtmlCoverageRenderer' => true,
        'Coverage\\InvalidCoverageOutputPathException' => true,
        'Coverage\\InvalidThresholdConfigurationException' => true,
        'Coverage\\JUnitCoverageRenderer' => true,
        'Coverage\\JsonCoverageRenderer' => true,
        'Coverage\\MarkdownCoverageRenderer' => true,
        'Coverage\\OpenApiCoverageTracker' => true,
        'Coverage\\ResponseCoverageState' => true,
        'DecodedBody' => true,
        'Exception\\EnumBindingException' => true,
        'Exception\\EnumBindingReason' => true,
        'Exception\\EnumDriftException' => true,
        'Exception\\InvalidOpenApiSpecException' => true,
        'Exception\\InvalidOpenApiSpecReason' => true,
        'Exception\\SpecFileNotFoundException' => true,
        'Exception\\StrictRequiredDriftException' => true,
        'Fuzz\\ExplorationCaseKind' => true,
        'Fuzz\\ExplorationCases' => true,
        'Fuzz\\ExplorationSkip' => true,
        'Fuzz\\ExploredCase' => true,
        'Fuzz\\ExploredOperation' => true,
        'Fuzz\\FailureReducer' => true,
        'Fuzz\\OpenApiEndpointExplorer' => true,
        'Fuzz\\OpenApiSpecExploration' => true,
        'Fuzz\\OpenApiSpecExplorer' => true,
        'Fuzz\\SpecExplorationSummary' => true,
        'HttpMethod' => true,
        'Laravel\\Commands\\OpenApiRoutesCommand' => true,
        'Laravel\\ExploresOpenApiEndpoint' => true,
        'Laravel\\OpenApiContractTestingServiceProvider' => true,
        'Laravel\\ValidatesOpenApiSchema' => true,
        'OpenApiRequestValidator' => true,
        'OpenApiResponseValidator' => true,
        'OpenApiValidationOutcome' => true,
        'OpenApiValidationResult' => true,
        'OpenApiVersion' => true,
        'PHPUnit\\AssertsNoEnumDrift' => true,
        'PHPUnit\\ConsoleOutput' => true,
        'PHPUnit\\InvalidStrictRequiredConfigurationException' => true,
        'PHPUnit\\OpenApiCoverageExtension' => true,
        'Pest\\Expectations' => true,
        'Psr7\\OpenApiAssertions' => true,
        'Psr7\\OpenApiPsr7ValidationResult' => true,
        'Psr7\\OpenApiPsr7Validator' => true,
        'Schema\\EnumDriftAsserter' => true,
        'Schema\\EnumDriftReport' => true,
        'SchemaContext' => true,
        'SkipOpenApiResolver' => true,
        'Spec\\OpenApiSpecLoader' => true,
        'Spec\\OpenApiSpecResolver' => true,
        'Symfony\\OpenApiAssertions' => true,
        'Validation\\Strict\\StrictRequiredAsserter' => true,
        'Validation\\Strict\\StrictRequiredMode' => true,
        'Validation\\Strict\\StrictRequiredPerCallMode' => true,
        'Validation\\Strict\\StrictRequiredReport' => true,
        'Validation\\Strict\\StrictRequiredTracker' => true,
    ];
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        spl_autoload_register(static function (string $gessoType): void {
            $gessoPrefix = 'Studio\\Gesso\\';
            if (!str_starts_with($gessoType, $gessoPrefix)) {
                return;
            }

            $relativeType = substr($gessoType, strlen($gessoPrefix));
            if (!isset(self::PUBLIC_TYPES[$relativeType])) {
                return;
            }

            $v1Type = 'Studio\\OpenApiContractTesting\\' . $relativeType;
            // The public-API inventory test guarantees that every allowlisted
            // declaration exists; class_alias() autoloads its concrete kind.
            class_alias($v1Type, $gessoType);
        });
    }
}

GessoAliasLoader::register();
