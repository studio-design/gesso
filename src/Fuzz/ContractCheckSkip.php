<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * Contract check skipped (not probed) for a path or method.
 */
final readonly class ContractCheckSkip
{
    /**
     * @param null|string $method null for path-level skips (e.g. every explorable method documented)
     */
    public function __construct(
        public ContractCheck $check,
        public string $path,
        public ?string $method,
        public string $reason,
    ) {}
}
