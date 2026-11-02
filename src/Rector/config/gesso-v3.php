<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Transform\Rector\StaticCall\StaticCallToNewRector;
use Rector\Transform\ValueObject\StaticCallToNew;
use Studio\Gesso\Rector\Rule\HasAnyCoverageToCurrentTrackerRector;
use Studio\Gesso\Rector\Rule\RemovedTraitHelperCallRector;
use Studio\Gesso\Rector\Rule\RemoveEnumBasePathArgumentRector;
use Studio\Gesso\Rector\Rule\RenamedAssertionRector;

// Gesso v2 → v3: the PHP renames and removals in UPGRADING.md
// ("From v2.9.x to v3.0.0"). Config keys and CLI flags are
// `gesso config:migrate`'s job, not this set's.
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->ruleWithConfiguration(RenameClassRector::class, [
        'Studio\Gesso\Fuzz\ContractCheck' => 'Studio\Gesso\Contract\ContractCheck',
        'Studio\Gesso\Fuzz\ContractCheckFailure' => 'Studio\Gesso\Contract\ContractCheckFailure',
        'Studio\Gesso\Fuzz\ContractCheckPlan' => 'Studio\Gesso\Contract\ContractCheckPlan',
        'Studio\Gesso\Fuzz\ContractCheckProbe' => 'Studio\Gesso\Contract\ContractCheckProbe',
        'Studio\Gesso\Fuzz\ContractCheckSkip' => 'Studio\Gesso\Contract\ContractCheckSkip',
        'Studio\Gesso\Fuzz\ContractCheckSummary' => 'Studio\Gesso\Contract\ContractCheckSummary',
        'Studio\Gesso\Fuzz\OpenApiContractChecks' => 'Studio\Gesso\Contract\OpenApiContractChecks',
    ]);

    $rectorConfig->ruleWithConfiguration(StaticCallToNewRector::class, [
        new StaticCallToNew('Studio\Gesso\Exception\EnumBindingException', 'forBinding'),
    ]);

    $rectorConfig->rule(HasAnyCoverageToCurrentTrackerRector::class);
    $rectorConfig->rule(RemoveEnumBasePathArgumentRector::class);
    $rectorConfig->rule(RemovedTraitHelperCallRector::class);
    $rectorConfig->rule(RenamedAssertionRector::class);
};
