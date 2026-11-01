<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Rector\Rector\AbstractRector;
use Studio\Gesso\Rector\GessoSetList;

/**
 * `OpenApiCoverageTracker::hasAnyCoverage($spec)` →
 * `OpenApiCoverageTracker::current()->hasAnyCoverageOn($spec)`.
 *
 * @internal Applied through {@see GessoSetList::V3}; the class name is not a contract.
 */
final class HasAnyCoverageToCurrentTrackerRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    /**
     * @param StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$this->isName($node->class, 'Studio\Gesso\Coverage\OpenApiCoverageTracker') ||
            !$this->isName($node->name, 'hasAnyCoverage')) {
            return null;
        }

        // Keep the Name node the user wrote, so an imported short name stays short.
        return new MethodCall(new StaticCall($node->class, 'current'), 'hasAnyCoverageOn', $node->args);
    }
}
