<?php

declare(strict_types=1);

/**
 * Lazy aliases for the Studio\Gesso\Fuzz\ spellings of the named contract
 * checks, which moved to Studio\Gesso\Contract\ (issue #503). Loaded via
 * composer.json autoload.files. Removed in Gesso 3.0 together with its
 * registry rows.
 *
 * The alias is declared together with its canonical class, whichever name
 * loads it: PHP resolves parameter/return types, instanceof, and catch
 * without autoloading, so an alias created only when the legacy name reaches
 * an autoloader would be missing from a legacy type hint on an object built
 * through the new name. Prepending puts this loader ahead of Composer's for
 * the seven canonical names. The deprecation notice is therefore best-effort:
 * it fires when a legacy name is what loads the class, and a legacy name
 * referenced after its canonical class is already loaded resolves silently.
 *
 * An explicit allowlist, not a Fuzz\ -> Contract\ prefix rewrite: the
 * generator types (ExploredCase, OpenApiSpecExplorer, ...) stay in Fuzz\.
 * Each notice() call takes literal arguments because DeprecationRegistryTest
 * compares them verbatim with tests/fixtures/compatibility/v2-deprecations.json.
 */

namespace Studio\Gesso\Contract;

use Studio\Gesso\Internal\Deprecations;

use function class_alias;
use function class_exists;
use function in_array;
use function spl_autoload_register;
use function str_starts_with;
use function strlen;
use function substr;

// Composer includes this file once, but a PSR-4 lookup of the name
// Studio\Gesso\Contract\Autoload includes it again; a second prepended loader
// registered from inside the autoload chain would re-enter it without end.
if (isset($GLOBALS['__gesso_contract_aliases'])) {
    return;
}
$GLOBALS['__gesso_contract_aliases'] = true;

spl_autoload_register(static function (string $class): void {
    $short = substr($class, strlen(__NAMESPACE__) + 1);
    if (
        str_starts_with($class, __NAMESPACE__ . '\\') &&
        in_array($short, ['ContractCheck', 'ContractCheckPlan', 'ContractCheckProbe', 'ContractCheckFailure', 'ContractCheckSkip', 'ContractCheckSummary', 'OpenApiContractChecks'], true)
    ) {
        require __DIR__ . '/' . $short . '.php';
        class_alias($class, 'Studio\\Gesso\\Fuzz\\' . $short);

        return;
    }

    switch ($class) {
        case 'Studio\\Gesso\\Fuzz\\ContractCheck':
            class_exists(ContractCheck::class);
            Deprecations::notice(
                'fuzz.namespace.contract_check',
                'Studio\\Gesso\\Fuzz\\ContractCheck',
                'Studio\\Gesso\\Contract\\ContractCheck',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckPlan':
            class_exists(ContractCheckPlan::class);
            Deprecations::notice(
                'fuzz.namespace.contract_check_plan',
                'Studio\\Gesso\\Fuzz\\ContractCheckPlan',
                'Studio\\Gesso\\Contract\\ContractCheckPlan',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckProbe':
            class_exists(ContractCheckProbe::class);
            Deprecations::notice(
                'fuzz.namespace.contract_check_probe',
                'Studio\\Gesso\\Fuzz\\ContractCheckProbe',
                'Studio\\Gesso\\Contract\\ContractCheckProbe',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckFailure':
            class_exists(ContractCheckFailure::class);
            Deprecations::notice(
                'fuzz.namespace.contract_check_failure',
                'Studio\\Gesso\\Fuzz\\ContractCheckFailure',
                'Studio\\Gesso\\Contract\\ContractCheckFailure',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckSkip':
            class_exists(ContractCheckSkip::class);
            Deprecations::notice(
                'fuzz.namespace.contract_check_skip',
                'Studio\\Gesso\\Fuzz\\ContractCheckSkip',
                'Studio\\Gesso\\Contract\\ContractCheckSkip',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckSummary':
            class_exists(ContractCheckSummary::class);
            Deprecations::notice(
                'fuzz.namespace.contract_check_summary',
                'Studio\\Gesso\\Fuzz\\ContractCheckSummary',
                'Studio\\Gesso\\Contract\\ContractCheckSummary',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\OpenApiContractChecks':
            class_exists(OpenApiContractChecks::class);
            Deprecations::notice(
                'fuzz.namespace.open_api_contract_checks',
                'Studio\\Gesso\\Fuzz\\OpenApiContractChecks',
                'Studio\\Gesso\\Contract\\OpenApiContractChecks',
                '3.0',
            );

            return;
    }
}, prepend: true);
