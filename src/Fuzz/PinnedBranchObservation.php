<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Closure;

/**
 * Records whether a case's target branch was actually taken by generation.
 *
 * A whole-schema validity check cannot distinguish a value that took the
 * targeted branch from one that merely passes through a sibling — `anyOf`
 * admits any matching branch, an `if` may fire on an else-targeted value.
 * So the generation site that applies the pinned target registers a
 * branch-level check ({@see self::expect()}) against the branch content it
 * has in hand, and the node's generated value is evaluated against it once
 * the value exists ({@see self::evaluate()}). Sites that can decide on the
 * spot (property presence after the maxProperties trim) report directly.
 *
 * `targetLocal` keeps the node-level outcome at the target site — the value
 * the check ran on, or the reported state. The target search compares it
 * across attempts: identical failing outcomes mean generation is
 * deterministic for this case and the branch is a proven dead end; varying
 * outcomes mean the search cannot conclude and must fail loudly.
 *
 * A composition site also stages its branch content here when the plan asks
 * for a refinement attempt ({@see CaseSelectionPlan::$refineTarget}); the
 * composition resolver re-merges it after all other merges so
 * non-conjunctive keywords (`pattern`, `format`) displaced by a later
 * `allOf` merge are restored to the branch's own constraint.
 *
 * Regenerated nodes — `not` retries, conditional closure expansion —
 * re-register and re-evaluate; the invocation that produced the returned
 * value always evaluates last, so the final state describes the final tree.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class PinnedBranchObservation
{
    public bool $targetSatisfied = false;
    public bool $observed = false;
    public mixed $targetLocal = null;
    private ?string $owner = null;

    /** @var ?Closure(mixed): bool */
    private ?Closure $check = null;

    /** @var ?array<string, mixed> */
    private ?array $refinement = null;

    /**
     * Register the branch-level check for the target applied at the node
     * generating under `$ownerPointer`.
     *
     * @param Closure(mixed): bool $check
     */
    public function expect(string $ownerPointer, Closure $check): void
    {
        $this->owner = $ownerPointer;
        $this->check = $check;
    }

    /** Run the registered check when `$pointer` is the registering node. */
    public function evaluate(string $pointer, mixed $value): void
    {
        if ($this->check !== null && $this->owner === $pointer) {
            $this->observed = true;
            $this->targetLocal = $value;
            $this->targetSatisfied = ($this->check)($value);
        }
    }

    /** Record an on-the-spot decision from a site that needs no deferral. */
    public function report(bool $satisfied): void
    {
        $this->observed = true;
        $this->targetLocal = $satisfied;
        $this->targetSatisfied = $satisfied;
    }

    /** @param array<string, mixed> $branch */
    public function stageRefinement(array $branch): void
    {
        $this->refinement = $branch;
    }

    /** @return ?array<string, mixed> */
    public function takeRefinement(): ?array
    {
        $refinement = $this->refinement;
        $this->refinement = null;

        return $refinement;
    }
}
