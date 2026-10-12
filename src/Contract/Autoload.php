<?php

declare(strict_types=1);

/**
 * Lazy aliases for the Studio\Gesso\Fuzz\ spellings of the named contract
 * checks, which moved to Studio\Gesso\Contract\ (issue #503). Loaded via
 * composer.json autoload.files. Each legacy name is resolved on first use,
 * aliased with class_alias(), and announced once on the deprecation channel.
 * Removed in Gesso 3.0 together with its registry rows.
 *
 * An explicit allowlist, not a Fuzz\ -> Contract\ prefix rewrite: the
 * generator types (ExploredCase, OpenApiSpecExplorer, ...) stay in Fuzz\.
 * Each notice() call takes literal arguments because DeprecationRegistryTest
 * compares them verbatim with tests/fixtures/compatibility/v2-deprecations.json.
 */

namespace Studio\Gesso\Contract;

use Studio\Gesso\Internal\Deprecations;

use function class_alias;
use function spl_autoload_register;

spl_autoload_register(static function (string $legacy): void {
    switch ($legacy) {
        case 'Studio\\Gesso\\Fuzz\\ContractCheck':
            class_alias(ContractCheck::class, $legacy);
            Deprecations::notice(
                'fuzz.namespace.contract_check',
                'Studio\\Gesso\\Fuzz\\ContractCheck',
                'Studio\\Gesso\\Contract\\ContractCheck',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckPlan':
            class_alias(ContractCheckPlan::class, $legacy);
            Deprecations::notice(
                'fuzz.namespace.contract_check_plan',
                'Studio\\Gesso\\Fuzz\\ContractCheckPlan',
                'Studio\\Gesso\\Contract\\ContractCheckPlan',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckProbe':
            class_alias(ContractCheckProbe::class, $legacy);
            Deprecations::notice(
                'fuzz.namespace.contract_check_probe',
                'Studio\\Gesso\\Fuzz\\ContractCheckProbe',
                'Studio\\Gesso\\Contract\\ContractCheckProbe',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckFailure':
            class_alias(ContractCheckFailure::class, $legacy);
            Deprecations::notice(
                'fuzz.namespace.contract_check_failure',
                'Studio\\Gesso\\Fuzz\\ContractCheckFailure',
                'Studio\\Gesso\\Contract\\ContractCheckFailure',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckSkip':
            class_alias(ContractCheckSkip::class, $legacy);
            Deprecations::notice(
                'fuzz.namespace.contract_check_skip',
                'Studio\\Gesso\\Fuzz\\ContractCheckSkip',
                'Studio\\Gesso\\Contract\\ContractCheckSkip',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\ContractCheckSummary':
            class_alias(ContractCheckSummary::class, $legacy);
            Deprecations::notice(
                'fuzz.namespace.contract_check_summary',
                'Studio\\Gesso\\Fuzz\\ContractCheckSummary',
                'Studio\\Gesso\\Contract\\ContractCheckSummary',
                '3.0',
            );

            return;
        case 'Studio\\Gesso\\Fuzz\\OpenApiContractChecks':
            class_alias(OpenApiContractChecks::class, $legacy);
            Deprecations::notice(
                'fuzz.namespace.open_api_contract_checks',
                'Studio\\Gesso\\Fuzz\\OpenApiContractChecks',
                'Studio\\Gesso\\Contract\\OpenApiContractChecks',
                '3.0',
            );

            return;
    }
});
