<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * Named negative contract checks. Values match the Schemathesis check names
 * so cross-tool documentation and CI dashboards line up.
 */
enum ContractCheck: string
{
    case UnsupportedMethod = 'unsupported_method';

    /**
     * Default pass statuses; override per plan via
     * {@see ContractCheckPlan::expectedStatuses()}.
     *
     * @return list<int>
     */
    public function defaultExpectedStatuses(): array
    {
        return match ($this) {
            self::UnsupportedMethod => [405],
        };
    }
}
