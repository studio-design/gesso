# ADR 0007: v3 scope and release window

- Status: Proposed
- Date: 2026-10-26
- Issue: none
- Related: [ADR 0004](0004-v3-consistency-policy-and-protected-core.md),
  [ADR 0005](0005-v3-configuration-and-cli-naming.md) (names, unchanged),
  [docs/versioning.md § Deprecation policy](../versioning.md#deprecation-policy)
- Supersedes: none while Proposed. If Accepted, it supersedes
  [ADR 0004](0004-v3-consistency-policy-and-protected-core.md) **in part**:
  its Decision section, the 2026-08-07 #508 amendment, and the two release
  amendments (2026-08-07 sequencing, 2026-08-12 condition-gated window). It
  retains ADR 0004's protected core (both tables), inclusion criterion,
  non-goals, and the scope boundary against splitting the package.

> **This ADR is Proposed, not Accepted.** Until a follow-up change sets its
> status to Accepted, [ADR 0004](0004-v3-consistency-policy-and-protected-core.md)
> stays in force in full, including its 2026-08-12 amendment: v3 is "shape, not
> capability", and the v3.0 window opens only on the floor, a trigger, and an
> amendment recording both. Nothing in this file authorizes a v3 change on its
> own.

The supporting research is recorded under `docs/research/` (internal, not
shipped in the package): `2026-10-26-v3-release-plan.md` (branch mechanics, the
deprecation ordering this ADR depends on, the consolidated roadmap, and the
open decisions), `2026-10-26-v3-competitive-landscape.md`,
`2026-10-26-v3-gap-verification.md`, `2026-10-26-v3-performance-and-pruning.md`,
and `2026-10-26-v3-adoption.md`.

## Context

ADR 0004 limited v3 to "shape, not capability" and gated its release on v1 EOL
(2027-07-01), plus a dated trigger, plus an amendment. Both rules assumed that
v3.0's payload was only deletion, so a major had nothing to offer adopters and
no reason to come early.

The owner proposes to change that premise (2026-10-26). v3 would also be the
release that adds capability, closes measured gaps against competing tools,
and removes features, settings, and code that cost memory or time without
matching demand. A major with adopter value does not need a trigger to justify
it, and waiting for v1 EOL only delays that value. What stays is everything
that protected adopters: the protected core, the deprecation-first rule, and
v2's support floor.

## Decision

1. **v3 may add.** A new capability is in scope if it passes ADR 0004's
   [inclusion criterion](0004-v3-consistency-policy-and-protected-core.md#inclusion-criterion)
   (no resident process; part of checking an implementation against its spec).
   New surfaces carry ADR 0005 names from their first commit. A
   [non-goal](0004-v3-consistency-policy-and-protected-core.md#non-goals) stays
   out unless an amendment to this ADR argues it back in.
2. **v3 may change verdicts.** A change to what Gesso decides about a request,
   response, or schema is allowed in v3 when it makes Gesso agree more closely
   with the OpenAPI / JSON Schema specification, pinned by a fixture. A
   conformance baseline may move in such a PR. The PR states the reason, puts
   the `git diff origin/main...HEAD` of both baseline files in its description,
   and keeps every row of ADR 0004's verification table green. The
   [reduction-PR acceptance rule](0004-v3-consistency-policy-and-protected-core.md#reduction-pr-acceptance-rule)
   still applies unchanged to PRs that claim only to remove surface.
3. **Removal rule.** A surface covered by `docs/versioning.md` is removed in
   v3.0 only if **both** hold:
   - (a) *Deprecation shipped.* Its deprecation is in a tagged v2 release
     before the removal merges (the standing rule, unchanged). Surfaces that
     cannot raise a runtime notice (enum cases, Composer constraints,
     wire-format cutoffs) count as deprecated when a tagged v2 release carries
     a docblock `@deprecated` (where a declaration exists) **and** an
     `UPGRADING.md` "Deprecations" row. A wire-format cutoff additionally emits
     a `[Gesso deprecation]` notice from the reader that would reject it.
   - (b) *Evidence.* The removal cites either a **measured cost** (a benchmark
     delta in time or peak memory, a bug class it carries, or lines and
     branches that have to be maintained twice) **or** **no demand** (no
     measured downstream use, and no open issue asking for it). Measured use
     beats cost: a surface with measured use is removed only when its
     replacement expresses everything it did.

   `@internal` code needs only the verification table green.
4. **Kept by evidence.** Enum drift, SDK exercise coverage, `src/Fuzz/`, and the
   named contract checks stay in v3. ADR 0004 recorded measured use for them
   (52 backed enums under fail-on-drift; 2 files / 246 lines of contract
   checks). That is rule 3(b) applied, not an exemption. The pruning research
   proposes extracting Arazzo, removing SDK exercise coverage, and removing
   fuzz breadth; those proposals are **open decisions** listed in
   `docs/research/2026-10-26-v3-release-plan.md` and are not decided by this
   ADR. Accepting any of them means amending this rule (and rule 5 for an
   extraction into a separate package) with the evidence rule 3(b) asks for.
5. **Scope boundaries, revised.** Still not authorized: splitting the
   repository into several Composer packages; changing validation semantics
   only because a major is available (rule 2 requires spec agreement). Newly
   authorized when a benchmark or bug evidence is attached: replacing or
   rewriting an internal component wholesale, and a major upgrade of a
   production dependency (including `opis/json-schema` `^3`), provided the
   protected-core verification table stays green.

## Release window

No floor and no trigger. v3.0.0 stable may be cut when all of these hold:

- `v3-renames.json` `unstaged_count` is 0, and every removal in the v3.0
  changeset satisfies rule 3(a) against a **tagged** v2 release;
- at least one `3.0.0-beta.N` has been published and installed through
  `composer require` in a clean project and in one downstream suite;
- ADR 0004's verification table is green on `main`.

Scope rule retained from ADR 0004: an item not ready when the gates hold moves
to v3.1 (additive) or v4.0 (breaking); it does not extend the window. v2's
support floor (active maintenance until 2028-07-01 at the earliest) is defined
in [docs/versioning.md](../versioning.md#v2-maintenance-lifecycle) and is not
shortened by this ADR.

## Protected core

ADR 0004's [protected-core table and verification table](0004-v3-consistency-policy-and-protected-core.md#protected-core)
are retained verbatim. They may be renamed or relocated, never weakened.

## Consequences

- Three lines are live until 2027-07-01 (1.x security-only from 2027-01-01,
  2.x, and `main` as v3). The backport procedure in `CONTRIBUTING.md` has to
  cover three lines; the proposed procedure is in the release-plan research
  document.
- The ADR 0004 #508 exception becomes moot; #508 is an ordinary v3 change.
- Accepted spellings documented "through v3.x" (the #504 environment variables
  and Artisan aliases) keep that promise; their removal (#523) stays in v4.0.

## Accepting this ADR

Acceptance is a separate change that:

- sets `Status: Accepted` here and in [the index](index.md);
- changes ADR 0004's status line to name the sections this ADR supersedes;
- updates `docs/versioning.md` (v2 maintenance lifecycle intro, the last
  paragraph of the deprecation policy, the non-runtime-surface rule from
  rule 3(a), and the two links to ADR 0004's 2026-08-12 amendment).

Until that change merges, ADR 0004 governs.
