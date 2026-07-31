<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;

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
 * Every case keeps the existing self-check against the converted schema, so a
 * generator defect fails loudly here instead of reaching user code. The one
 * exception is probe cases — branches whose reachability the schema alone
 * cannot decide, i.e. the none-match state of conditional `allOf` and choice
 * points discovered inside it: when generation cannot produce a valid value,
 * the probe case is dropped instead of failing the run. For enum-driven
 * discriminators that decision is exact — the admissible domain is filtered
 * exhaustively, so the drop means a closed set genuinely forbids the state;
 * for other shapes it rests on the bounded deterministic retry search and
 * remains best-effort. Schemas outside the enumeration subset or beyond its
 * documented bounds throw from the pre-pass before anything is generated.
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
                    $point->probeContext || $branch === $point->probeBranch,
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
            // A probe pins a state whose reachability the schema alone cannot
            // decide (the none-match side of conditional allOf — unreachable
            // for closed discriminator sets). Its case is dropped only after
            // generation's bounded retry search failed to produce a valid
            // value; every other case stays loud.
            if ($plan->probe && !SchemaValueValidator::isValid($value, $schema)) {
                continue;
            }
            SchemaValueValidator::assertValid($value, $schema, $index);
            $cases[] = new PlannedSchemaCase($index, $value, $plan);
        }

        return $cases;
    }
}
