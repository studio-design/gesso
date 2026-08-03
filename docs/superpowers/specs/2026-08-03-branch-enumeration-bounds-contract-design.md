# Branch-complete enumeration bounds contract

## Context

Issue #443 requires branch-complete response generation to document its finite
enumeration bounds and to fail loudly when a schema exceeds them. The current
implementation enforces maximum depth, choice-point count, and node visits, but
the exact values are only visible on the internal enumerator. The node-visit
budget also lacks a direct regression test.

`PinnedBranchObservation` additionally contains an obsolete class-level
description saying repeated deterministic failures prove a dead end. The
current safety rule is stricter: only a schema-derived proof may mark a target
unreachable; an unsuccessful search must fail loudly.

## Design

- Keep generation behavior and the public PHP API unchanged.
- Document the exact branch-complete enumeration limits in `docs/fuzzing.md`:
  32 nested property/item levels, 256 collected choice points, and 10,000 node
  visits across branch contexts.
- State that exceeding any limit throws a supported-subset diagnostic instead
  of returning partial branch coverage.
- Add a direct `SchemaChoicePointEnumeratorTest` case that constructs a shallow
  schema with enough rediscovered branch contexts to exceed the node-visit
  budget. Assert the exception type and stable diagnostic category rather than
  the complete message prose.
- Replace the obsolete `PinnedBranchObservation` explanation with the current
  static-proof-only policy already expressed by `provenDeadEnd`.

## Testing

The regression test must first fail because no direct node-budget assertion is
present, then pass without changing production behavior because the loud bound
already exists. Run the focused enumerator suite, affected fuzz suites if the
fixture reveals shared behavior, formatting/static analysis, and the full
`composer ci` gate.

## Non-goals

- Changing any enumeration limit.
- Adding a configurable limit or new public API.
- Broadening the supported schema-generation subset.
- Changing how unreachable branches are proven or searched.
