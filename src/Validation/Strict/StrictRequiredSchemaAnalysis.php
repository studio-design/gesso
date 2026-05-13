<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

/**
 * Result of {@see StrictRequiredSchemaWalker::analyse()}: the parallel
 * (walked-required, disjunctions) pair produced by descending an OpenAPI
 * response schema once.
 *
 * Both fields are filled together by a single descent and consumed
 * together by the asserter and the per-call checker. Wrapping the pair
 * in this object enforces the "check disjunctions first, then look up
 * required" rule structurally — callers go through {@see self::lookup()}
 * which returns either a {@see StrictRequiredDisjunctionMatch} (caller
 * must skip / NOTE) or a {@see StrictRequiredKnownRequired} (caller can
 * diff against observed keys). PHPStan exhaustively checks the
 * `instanceof` discriminator on the union return type.
 *
 * Diagnostic accessors (`disjunctions()`, `walkedPointers()`) are exposed
 * for the asserter's NOTE-rendering loop and for unit-test introspection;
 * neither should be used to bypass the `lookup()` rule.
 *
 * @internal Returned by the schema walker; consumers are the asserter and
 *           the per-call checker.
 */
final class StrictRequiredSchemaAnalysis
{
    /**
     * @param array<string, list<string>> $walked
     * @param list<array{pointer: string, reason: string}> $disjunctions
     */
    public function __construct(
        private readonly array $walked,
        private readonly array $disjunctions,
    ) {}

    /**
     * Resolve a single observed pointer against the schema. Always returns
     * one of the two leaf variants — never null — so callers' `instanceof`
     * branches are exhaustive.
     */
    public function lookup(string $pointer): StrictRequiredDisjunctionMatch|StrictRequiredKnownRequired
    {
        $covering = StrictRequiredSchemaWalker::findCoveringDisjunction($pointer, $this->disjunctions);
        if ($covering !== null) {
            return new StrictRequiredDisjunctionMatch($covering['pointer'], $covering['reason']);
        }

        return new StrictRequiredKnownRequired($this->walked[$pointer] ?? []);
    }

    /**
     * @return list<array{pointer: string, reason: string}>
     */
    public function disjunctions(): array
    {
        return $this->disjunctions;
    }

    /**
     * @return array<string, list<string>>
     */
    public function walkedPointers(): array
    {
        return $this->walked;
    }
}
