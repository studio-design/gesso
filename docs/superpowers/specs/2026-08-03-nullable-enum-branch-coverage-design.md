# Nullable enum branch coverage design

- Date: 2026-08-03
- Status: Approved for implementation

## Goal

Make response payload exploration generate both reachable sides of a nullable
type when the converted JSON Schema also constrains the value with `enum`, while
preserving exact-value semantics for `const` and one-sided enums.

For example, this schema must produce both `"member"` and `null` cases:

```json
{
  "type": ["string", "null"],
  "enum": ["member", null]
}
```

This restores the documented branch-complete guarantee for every reachable
nullable choice point.

## Current behavior

`SchemaChoicePointEnumerator::visitLeaf()` returns immediately when it sees a
non-empty `enum` or any `const`. It therefore records no `/type` nullable choice
for an enum containing both null and non-null values.

`SchemaDataGenerator::generateNode()` also resolves `const` and `enum` before
its nullable-plan handling. Even if the enumerator reported the choice point,
the generator would rotate through the exact-value domain without honoring a
pinned null or non-null branch.

The combined effect is a silent coverage gap: a fixed schema and seed can
exercise only one side even though both are valid and reachable.

## Decision

Treat a non-empty enum as a finite value domain and partition its admissible
members into null and non-null groups.

The enumerator records the existing `SchemaChoicePointKind::Nullable` choice at
`<pointer>/type` only when all of the following hold:

1. `type` is an array containing `null` and at least one non-null type;
2. at least one enum member is valid against the effective leaf schema and is
   null; and
3. at least one enum member is valid against the effective leaf schema and is
   non-null.

The generator applies a pinned nullable selection to the admissible enum
members before choosing by iteration. The null branch selects only null; the
value branch selects only non-null members. The existing target observation
continues to prove that the returned value realized the requested branch.

`const` remains a terminal single-value domain. Whether its sole value is null
or non-null, it exposes no choice and therefore must not create a two-branch
nullable target. Enums whose admissible values all fall on one side follow the
same rule and remain terminal without a nullable choice point.

## Alternatives considered

### Move nullable handling before `const` and `enum`

Rejected. It would invent a null target for schemas such as
`{"type":["string","null"],"const":"fixed"}` even though `const` makes that
branch unreachable. Target search would then need to rediscover a fact already
decidable from the finite domain.

### Normalize nullable enums into synthetic composition branches

Rejected. Rewriting the schema as an implicit `anyOf` would change choice-point
pointers, case counts, and merge behavior for a narrow bug. The existing
nullable kind and `/type` selection contract already model the desired choice.

### Partition the finite enum domain

Accepted. It is local to the two components that currently disagree, preserves
existing pointers and public behavior, and lets the generator choose only
values that satisfy the exact-value constraint.

## Implementation

Keep the change inside the internal fuzzing boundary:

- `SchemaChoicePointEnumerator` determines whether an enum has admissible
  members on both nullable sides before returning from the exact-value leaf.
- `SchemaDataGenerator` filters its already-computed admissible enum values by
  a pinned nullable selection and installs the normal target observation before
  returning the selected member.
- The generic nullable generation path remains unchanged for schemas without
  an exact-value domain.

No new public class, method, configuration option, wire-format field, or
production dependency is introduced. Request exploration without a selection
plan retains its existing enum rotation.

## Tests

Follow test-driven development:

1. Change the enumerator regression coverage so a nullable enum with valid null
   and non-null members reports a two-branch `/type` choice.
2. Prove that `const`, null-only enums, non-null-only enums, and enum members
   excluded by sibling constraints do not create unreachable nullable targets.
3. Add a branch-complete generator test proving both sides of a nullable enum
   are emitted and every case satisfies the schema.
4. Retain the existing generic nullable test to guard schemas without `enum`.
5. Run the focused enumerator, branch-complete generator, and schema data
   generator suites, followed by the repository CI command.

## Documentation and compatibility

No user-guide change is required. `docs/fuzzing.md` already promises coverage
of every reachable nullable choice point; this change makes enum-constrained
schemas satisfy that existing contract.

The affected types are internal. Exact public APIs, generated coverage formats,
and deterministic plan-less request generation remain unchanged.

## Non-goals

- Do not enumerate every enum member as its own branch; the contract is null
  versus non-null coverage, not exhaustive enum-value coverage.
- Do not create nullable targets for finite domains that admit only one side.
- Do not change composition, conditional, optional-property, or array-presence
  enumeration.
- Do not alter public response-explorer or coverage-reporting APIs.
