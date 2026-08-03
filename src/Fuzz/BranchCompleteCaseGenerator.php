<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Faker\Generator;
use InvalidArgumentException;

use function count;
use function max;
use function sprintf;

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
 * suppressions can forbid the pinned state on a perfectly valid schema. A
 * targeted case counts as covering its branch only when the value is valid
 * for the whole schema AND generation observed the target branch itself
 * being taken ({@see PinnedBranchObservation}) — whole-schema validity alone
 * cannot tell the target from a sibling branch that also matches the value.
 *
 * A single failed candidate proves nothing, so an unrealized target is
 * searched: attempts alternate the plain pinned view with a branch-refined
 * view (the branch content re-merged last, restoring non-conjunctive
 * keywords such as `pattern` that a later `allOf` merge displaced) across
 * iteration offsets that re-rotate every unpinned choice and redraw random
 * domains — at least {@see self::TARGET_SEARCH_ITERATIONS} offsets, widened
 * to cycle the widest choice point completely, since an unpinned sibling
 * rotation can gate the target's validity (e.g. via `dependentSchemas`).
 * A branch is dropped only on a static unreachability proof — generation
 * recorded a schema-derived contradiction at a node whose constraints are
 * unavoidable under the plan ({@see PinnedBranchObservation::$provenDeadEnd}):
 * an emptied enum/type intersection or const conflict, an absorbing `not`,
 * an exhausted finite const/enum domain, or impossible presence arithmetic.
 * Search failure is kept strictly apart: generator determinism is not
 * evidence that no other value exists on the schema, so without a proof the
 * run fails loudly instead of silently under-covering. If every case is
 * dropped, one rotation-only case still runs loudly, so an unsatisfiable
 * schema remains an error. Schemas outside the enumeration subset or beyond
 * its documented bounds throw from the pre-pass before anything is
 * generated.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class BranchCompleteCaseGenerator
{
    /**
     * Minimum iteration offsets tried per targeted case before concluding;
     * each offset runs a plain and a branch-refined attempt. The actual
     * width grows to the widest choice point's branch count plus one so a
     * single untargeted rotation is always cycled through completely — an
     * unpinned sibling choice can gate the target's validity (e.g. via
     * `dependentSchemas`) and must not run out of rounds before its
     * admissible pick comes up.
     */
    private const TARGET_SEARCH_ITERATIONS = 4;

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
        $widestChoice = 0;
        foreach (SchemaChoicePointEnumerator::enumerate($schema) as $point) {
            $widestChoice = max($widestChoice, $point->branchCount);
            for ($branch = 0; $branch < $point->branchCount; $branch++) {
                $plans[] = new CaseSelectionPlan(
                    [...$point->ancestors, $point->pointer => $branch],
                    $point->pointer,
                    $branch,
                );
            }
        }
        if ($plans === []) {
            $plans[] = new CaseSelectionPlan([]);
        }
        for ($extra = 0; $extra < $extraCases; $extra++) {
            $plans[] = new CaseSelectionPlan([]);
        }

        $faker = SchemaDataGenerator::createFaker($seed);
        $cases = [];
        foreach ($plans as $index => $plan) {
            if ($plan->targetPointer === null) {
                $value = SchemaDataGenerator::generateOne($schema, $faker, $index, $plan);
                SchemaValueValidator::assertValid($value, $schema, $index);
                $cases[] = new PlannedSchemaCase($index, $value, $plan);

                continue;
            }

            $case = self::searchTarget($schema, $faker, $index, $plan, max(self::TARGET_SEARCH_ITERATIONS, $widestChoice + 1));
            if ($case !== null) {
                $cases[] = $case;
            }
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

    /**
     * Search for a value that realizes the plan's target branch; null means
     * the branch is a proven (deterministic) dead end and its case is
     * dropped. See the class docblock for the drop/throw semantics.
     *
     * @param array<string, mixed> $schema
     */
    private static function searchTarget(
        array $schema,
        ?Generator $faker,
        int $index,
        CaseSelectionPlan $plan,
        int $rounds,
    ): ?PlannedSchemaCase {
        $attempts = 0;
        for ($round = 0; $round < $rounds; $round++) {
            foreach ([false, true] as $refine) {
                $attempt = new CaseSelectionPlan(
                    $plan->selections,
                    $plan->targetPointer,
                    $plan->targetBranch,
                    $refine,
                );
                $value = SchemaDataGenerator::generateOne($schema, $faker, $index + $round, $attempt);
                if ($attempt->observation->targetSatisfied && SchemaValueValidator::isValid($value, $schema)) {
                    return new PlannedSchemaCase($index, $value, $attempt);
                }
                if ($attempt->observation->provenDeadEnd) {
                    // A schema-derived proof (statically empty domain at a
                    // forced node, presence arithmetic) that the pinned view
                    // admits no value: no search can succeed, drop now.
                    return null;
                }
                $attempts++;
            }
        }

        // No proof and no success: generator determinism or a too-narrow
        // synthesis repertoire is not evidence that no other value exists
        // on the schema, so silent dropping here would under-cover. Fail
        // loudly instead.
        throw new FuzzGenerationException(sprintf(
            "Branch %d of choice point '%s' was not realized after %d attempts and could not be "
            . 'proven unreachable; this schema is outside the branch-complete generation subset.',
            $plan->targetBranch ?? -1,
            $plan->targetPointer ?? '',
            $attempts,
        ), $index);
    }
}
