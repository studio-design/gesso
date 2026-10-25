<?php

declare(strict_types=1);

namespace Studio\Gesso\Contract;

use function class_alias;

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

// Declared beside the class, not by an autoloader, so the legacy name exists
// whenever this file is included — including PHPUnit's direct require_once
// in a separate process. See Autoload.php. Removed in Gesso 3.0.
class_alias(ContractCheckSkip::class, 'Studio\\Gesso\\Fuzz\\ContractCheckSkip');
