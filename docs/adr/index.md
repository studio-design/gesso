# Architecture decision records

Decisions that shape what Gesso is, rather than how one part of it works. A
change that contradicts an accepted ADR needs a new ADR that supersedes it, not
just a pull request.

| ADR | Status | Date | Decision |
| --- | --- | --- | --- |
| [0001 — Align the v2 technical identity with Gesso](0001-gesso-v2-identity.md) | Accepted | 2026-07-13 | Namespace, package, and CLI rename to the Gesso identity, with the scope boundaries the rename did not authorize. |
| [0002 — Phase Arazzo workflow execution behind shared runtime expressions](0002-arazzo-workflow-execution.md) | Accepted | 2026-07-30 | Arazzo support lands in phases, starting from a runtime-expression evaluator shared with the existing validators. |
| [0003 — Branch-complete response payloads and an SDK round-trip harness](0003-sdk-roundtrip-harness.md) | Accepted | 2026-07-31 | Close the SDK ⇄ spec gap by feeding spec-derived payloads through a generated decoder. |
| [0004 — v3 consistency policy and protected core](0004-v3-consistency-policy-and-protected-core.md) | Accepted | 2026-08-06 | v3 changes shape, not capability: the invariants that may not regress, the inclusion criterion and its non-goals, and the rule a reduction PR is held to. |

## Writing one

Follow the most recent file. The header carries `Status`, `Date`, `Issue`, and
`Related`; 0004 adds `Supersedes`, which every later ADR should carry even when
the value is `none`. Number files sequentially and add the row above in the same
change, so this page never lags the directory.
