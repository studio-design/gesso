# v3 research: scope decisions and work order (2026-11-15)

Inputs: the state of `main` and the issue tracker after `v3.0.0-beta.1` and
`v2.10.0`, the 2026-10-26 research set, and
[the 2026-11-01 vision](2026-11-01-v3-vision.md). This note replaces the
"Release shape" section of the vision.

## State after 3.0.0-beta.1

- `v2.10.0` carries the last v2 deprecations: the v3 assertion names (#709) and
  the *static only* row for the `schemaVersion` JSON key (#754). Every removal
  in the beta has its deprecation in a tagged v2 release (ADR 0007 rule 3(a)).
- `v3.0.0-beta.1` is tagged from `f3a6b87`. The tag, the GitHub pre-release,
  the manifest and Packagist agree, and
  `composer require --dev "studio-design/gesso:^3.0@beta" phpunit/phpunit`
  installs it in an empty project.
- Of the ADR 0007 release-window gates, one is open: a beta installed in one
  downstream suite.

## Owner decisions (2026-11-15)

1. **`config:migrate` is not backported to `2.x`.** The vision's M1 said it
   would also ship in 2.10.0. v2 users run it after requiring 3.0; the upgrade
   guide already describes that order.
2. **The parameter and streaming gaps go into 3.0, not 3.1.** That is G-a
   (parameter styles, cookie parameters, parameters declared with `content`)
   and H8 (streaming `itemSchema`, webhooks and callbacks). 3.0 has no release
   date; scope is not cut to bring GA forward.
3. **SDK exercise coverage and fuzz breadth stay.** Both were open under ADR
   0007 rule 4 (see its 2026-11-15 amendment). The failure reducer is what the
   regression-test export (#780) builds on. The cost #511 measured for SDK
   exercise coverage was a permanent `0%` line shown to projects that never
   used the harness; #792 hides that line instead of removing the feature.
4. **`#[SkipOpenApi]` reasons (#741):** the setting takes `off | warn | fail`
   and 3.0 defaults to `warn`. A `fail` default would break suites that carry
   reason-less skips on upgrade.
5. **Ambiguous routes (#745):** a new `--fail-on-ambiguous` flag.
   `--fail-on-undocumented` keeps its meaning.
6. **Diagnostic prefixes stay as they are.** The feature-oriented channels
   (`[OpenAPI Coverage]`, `[OpenAPI Strict Required]`, ...) are the design ADR
   0001 chose, and `docs/versioning.md` keeps them. `GSO` codes serve
   consumers that need a stable identifier.
7. **`tool.version` uses the tag form (#765).** The beta prints
   `3.0.0.0-beta1`, Composer's normalized version.
8. **Documentation is published per major (#766).** During the beta the site
   root stays 2.x and `main` is published under `/gesso/3.x/`. After 3.0.0 the
   root is 3.x and v2 stays under `/gesso/2.x/`, because v2 is maintained until
   2028-07-01 at the earliest. `docs_url` moves to `/gesso/3.x/failure-codes`.
9. **No `MigrationGuideTest`.** Vision item M3 asked for a test that runs each
   migration recipe on a fixture project. The Rector sets and their tests
   cover the rewrites, so the guide gets no test of its own.

Also decided during the release: the strict-required fix for nullable unions
(#751) shipped in 2.10.0, on the "closing a documented silent pass is not a
SemVer break" clause of `docs/versioning.md`.

## Work order

Longest lead time first. Each step is a milestone.

| Step | Milestone | Issues |
| --- | --- | --- |
| 1 | `v3.0 beta.2 — output values and verdicts` | Emitted values: #765, #766, #693. Migration gap found in the first downstream upgrade: #794. Leftovers: #767, #768, #554. Parameters, in this order because they share files: #769 → #770 → #771. Independent: #772, #773. Streaming, then webhooks and callbacks: #774 → #775. |
| 2 | `v3.0 beta.3 — coverage and sidecar formats` | #776, #742, #741, #740, #739, #791, #661, #691, #792, then #777. |
| 3 | `v3.0 beta.4 — new checks and CLI` | New checks: #778, #779, #780, #781, #782, #737, #743, #745. CLI and output: #783, #784, #738, #744, #696, #694, #692, #790. |
| 4 | `v3.0 GA — docs and agents` | #785, #786, #787, #788, #789. |

One item sits on the `2.x` line, in the milestone `v2.11 — migration aids`:
#795. The first downstream upgrade from 2.10.0 found that 2.x gives a Laravel
test no way to set a `gesso.php` key path and raises no notice when a test
sets a flat key, so those overrides cannot move before the major bump. The
flat keys are removed in 3.0, so under ADR 0007 rule 3(a) this ships in a
tagged 2.x release before 3.0.0.

Rules behind the order:

- **Emitted values and verdicts first.** A change to what Gesso prints or
  decides gets the longest beta exposure.
- **Coverage and sidecar formats in one batch.** Each of those issues changes
  the coverage JSON or the sidecar envelope; batching bumps the formats once.
  #777 (a JSON Schema per document) comes last in the batch so the schemas
  describe the final shape.
- **ADR questions before code.** #778 (object-level authorization) and #738
  (PHPStan aliases) are checked against ADR 0004's inclusion criterion first.
  #783 (`gesso suggest`) waits for the amendment that narrows ADR 0004's
  "spec generation" and "Overlay merge engine" non-goals, which owner decision
  2 of 2026-11-01 announced and no ADR records yet.
- **Agent and comparison work after the freeze.** #785 stays blocked on the
  configuration and CLI freeze, as #511 required. #787 and #788 publish
  numbers, so they follow the verdict changes.

Each beta is installed in a downstream suite before the next step starts.

## Placed after the first pass

- #791 (a warning when a baseline is regenerated under a coding agent, the
  part of vision item A6 that #739 and #741 do not cover) goes into beta.3
  with the other baseline safeguards.
- #790 (the original `$ref` component and the spec file and line on schema
  issues, the later part of vision item A3, size L) goes into beta.4. If its
  design does not fit, it moves to 3.1.

## Outside 3.0

- #430: Arazzo conformance. The runner leaves the core (ADR 0007 rule 5), and
  the issue has no repository to move to yet. It keeps no milestone.

## Issue hygiene done on 2026-11-15

Closed after checking their acceptance criteria against `main` and the
published beta: #503, #506, #507, #524, #511, #501, #502, #508. The two
criteria that did not ship moved to #767 and #768. #554 stays open for the
seven unused static fields in the Laravel trait. Milestones
`v3.0 — consistency` and `v3 preconditions` are closed.
