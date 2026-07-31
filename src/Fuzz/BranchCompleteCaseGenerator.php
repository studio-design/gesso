<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;

use function count;

/**
 * Deterministic branch-complete case generation over a converted JSON Schema.
 *
 * A pre-pass ({@see SchemaChoicePointEnumerator}) collects every composition
 * choice point; the derived case list contains one case per (choice point,
 * branch) pair — each pinned through a {@see CaseSelectionPlan} whose ancestor
 * selections force the targeted branch to be reachable — plus any
 * caller-requested extra rotation-only cases, and always at least one case.
 * For a fixed (schema, seed) every branch of every reachable choice point
 * therefore appears in at least one generated case.
 *
 * Every untargeted case keeps the existing loud self-check against the
 * converted schema. Targeted cases are different: a pinned branch's
 * reachability is undecidable in general — parent constraints such as
 * `oneOf` exclusivity, `const`, `minProperties`, or folded conditional
 * suppressions can forbid the pinned state on a perfectly valid schema — so
 * a targeted case that cannot realize its branch (after the deterministic
 * search machinery: enum-domain filtering, `not` retries, conditional
 * closure) is treated as an unreachable branch and dropped rather than
 * failing the run. Realizing the branch means both whole-schema validity
 * and the generation-site observation that the target branch itself was
 * taken ({@see PinnedBranchObservation}) — whole-schema validity alone
 * cannot tell the target from a sibling branch that also matches the
 * value. If every case is dropped, one rotation-only
 * case still runs loudly, so an unsatisfiable schema remains an error.
 * Schemas outside the enumeration subset or beyond its documented bounds
 * throw from the pre-pass before anything is generated.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class BranchCompleteCaseGenerator
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return list<PlannedSchemaCase>
     */
    public static function generate(array $schema, ?int $seed = null, int $extraCases = 0): array
    {
        if ($extraCases < 0) {
            throw new InvalidArgumentException(
                'BranchCompleteCaseGenerator::generate() requires extraCases >= 0, got ' . $extraCases . '.',
            );
        }

        $plans = [];
        foreach (SchemaChoicePointEnumerator::enumerate($schema) as $point) {
            for ($branch = 0; $branch < $point->branchCount; $branch++) {
                $plans[] = new CaseSelectionPlan(
                    [...$point->ancestors, $point->pointer => $branch],
                    $point->pointer,
                    $branch,
                );
            }
        }
        for ($extra = 0; $extra < $extraCases; $extra++) {
            $plans[] = new CaseSelectionPlan([]);
        }
        if ($plans === []) {
            $plans[] = new CaseSelectionPlan([]);
        }

        $faker = SchemaDataGenerator::createFaker($seed);
        $cases = [];
        foreach ($plans as $index => $plan) {
            $value = SchemaDataGenerator::generateOne($schema, $faker, $index, $plan);
            // A pinned branch's reachability is undecidable in general:
            // parent constraints — oneOf exclusivity, const, minProperties,
            // folded suppressions — can forbid the pinned state on a
            // perfectly valid schema. A targeted case counts as covering
            // its branch only when the value is valid for the whole schema
            // AND generation observed the target branch itself being taken
            // — whole-schema validity alone cannot tell the target from a
            // sibling that also matches (`anyOf`, an `if` firing on an
            // else-targeted value). When generation (including its
            // deterministic search machinery) cannot realize such a value,
            // the branch is treated as unreachable and the case is dropped;
            // untargeted cases stay loud.
            if ($plan->targetPointer !== null &&
                (!$plan->observation->targetSatisfied || !SchemaValueValidator::isValid($value, $schema))) {
                continue;
            }
            SchemaValueValidator::assertValid($value, $schema, $index);
            $cases[] = new PlannedSchemaCase($index, $value, $plan);
        }

        // Dropping must never swallow a schema no value satisfies: if every
        // targeted case was dropped, one rotation-only case still runs and
        // fails loudly on an unsatisfiable schema.
        if ($cases === []) {
            $index = count($plans);
            $value = SchemaDataGenerator::generateOne($schema, $faker, $index, new CaseSelectionPlan([]));
            SchemaValueValidator::assertValid($value, $schema, $index);
            $cases[] = new PlannedSchemaCase($index, $value, new CaseSelectionPlan([]));
        }

        return $cases;
    }
}
