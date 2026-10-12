<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

/**
 * A preflighted success or failure action (`end` or `goto` a step in the
 * same workflow), with Reusable Object references already resolved.
 *
 * @internal Arazzo runner implementation detail; not public API.
 */
final readonly class ArazzoAction
{
    /**
     * @param 'end'|'goto' $type
     * @param list<ArazzoSimpleCondition> $criteria all must hold for the action to apply
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $stepId,
        public array $criteria,
    ) {}
}
