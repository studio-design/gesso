<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * Named negative contract checks. Values match the Schemathesis check names
 * so cross-tool documentation and CI dashboards line up.
 */
enum ContractCheck: string
{
    case IgnoredAuth = 'ignored_auth';
    case MissingRequiredHeader = 'missing_required_header';
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
            self::IgnoredAuth => [401, 403],
            self::MissingRequiredHeader => [],
            self::UnsupportedMethod => [405],
        };
    }

    /**
     * Default pass status *classes* (`4` matches every 4xx), used when the
     * contract admits a family of answers rather than a short list. A probe
     * passes when its status matches either the expected statuses or the
     * expected classes. Override per plan via
     * {@see ContractCheckPlan::expectedStatusClasses()}.
     *
     * `missing_required_header` is the family case: frameworks answer an
     * omitted required header with 400, 406, 422, or a scheme-specific
     * 401/403, and pinning one of them would report framework choice as
     * contract drift.
     *
     * @return list<int>
     */
    public function defaultExpectedStatusClasses(): array
    {
        return match ($this) {
            self::MissingRequiredHeader => [4],
            self::IgnoredAuth, self::UnsupportedMethod => [],
        };
    }
}
