# v3 research: competitive landscape

- Date: 2026-10-26
- Status: research input for [ADR 0007](../adr/0007-v3-scope-and-release-window.md)
  (Accepted 2026-10-26). Internal; not shipped in the package and not part of the docs site
  navigation.
- Sources: the GitHub REST API (stars, releases, issue state) and Packagist
  `stats.json` (monthly and total installs), both read on 2026-10-26.

Install counts are context for prioritisation, not market-share claims. Packagist
counts installs, including CI re-installs, and says nothing about private or
root-application use. No figure here should be quoted in user-facing docs
without its date and source.

## PHP validators and wrappers

| Package | Installs / month | Latest release | Notes |
| --- | --- | --- | --- |
| `league/openapi-psr7-validator` | 737k | 0.24, 2026-05-08 | 563 stars. The README documents OpenAPI 3.0.x. Issue #163 (3.1 support) has been open since 2022-04; 0.19 shipped 3.1 document parsing (PR #185), and commenters in that thread (not the maintainer) report that 2020-12 keyword semantics are not fully applied. Most-discussed issues: #35 (stops at the first error), #165 (reserved header parameters), #218 (webhooks), #248 (security validation broke after a `cebe/php-openapi` upgrade), #201 (error messages cannot be customised). |
| `osteel/openapi-httpfoundation-testing` | 96k | n/a | Wraps league for HttpFoundation messages. Issue #44 (3.1 support) was closed citing maintainer bandwidth; #59 asks about the project's ongoing viability. |
| `kirschbaum-development/laravel-openapi-validator` | 58k | n/a | Laravel trait that wraps league. |
| `hotmeteor/spectator` | 191k | v3 line (v3.0.0 released 2026-04-28) | Laravel 12+ only. Ships a PHPUnit 11 extension with coverage and `min_coverage`, plus `spectator:routes`, `spectator:validate`, and `spectator:stubs`. Offers JSON errors described as being "for LLM-driven workflows". 3.1 support works by normalising `nullable`. |
| `byjg/php-swagger-test` | n/a | 6.1 | Claims OpenAPI 3.1 and JSON Schema 2020-12. |
| `duyler/openapi` | n/a | 0.9 | Claims OpenAPI 3.2. |
| API Platform `ApiTestCase` | n/a | n/a | `assertMatchesResourceItemJsonSchema` and friends validate against the JSON Schema that API Platform generates from resource metadata, not against an exported OpenAPI document. |
| `dedoc/scramble` | 2.76M | n/a | Generates OpenAPI 3.1.0 from Laravel code. Ships no test helpers. |
| `laravel/boost` | 8.3M | n/a | Auto-discovers package skills at `vendor/*/resources/boost/skills/**/SKILL.md`. |
| `studio-design/gesso` | 3.8k | n/a | 9.3k installs in total. |

## Cross-language tools

| Tool | State on 2026-10-26 |
| --- | --- |
| Schemathesis | Very active. TraceCov reports keyword-level schema coverage for fuzzed traffic. Supports OpenAPI 3.2. |
| Dredd | Archived. |
| Optic | Archived. |
| Prism | Active; open 3.1 dialect bug #2797. |
| Specmatic | Active. |
| Pact | Active (consumer-driven contracts, not spec-first). |
| oasdiff | Active (spec-to-spec breaking-change detection). |
| Spectral, Redocly | Active; both lint OpenAPI 3.2. |

## Trends

- Demand for OpenAPI 3.1 in PHP is real: the largest validator's 3.1 issue is
  the longest-running request on its tracker, and its two largest wrappers
  inherit the gap.
- OpenAPI 3.2 is supply-side so far: tools advertise it before users ask.
- Coverage gates are becoming table stakes (Spectator v3, Schemathesis TraceCov).
- Demand for Arazzo is thin.
- Laravel Boost gives packages a zero-configuration channel into coding agents.

## Ranked reasons to choose Gesso

1. OpenAPI 3.1 support, with a migration path from league, osteel, and
   kirschbaum.
2. Scramble-first: validate tests against the exported Scramble document.
3. Published correctness evidence (`docs/conformance.md`).
4. Symfony and API Platform breadth, not only Laravel.
5. Coverage depth at CI scale (parallel merge, gates, baselines).
6. Agent-ready (a Boost skill; see the [adoption research](2026-10-26-v3-adoption.md)).
7. Drift gates (enum drift, route parity, strict required).
8. Fuzzing, Arazzo, and fakes as depth rather than headline.

## Follow-ups for user-facing docs

- The README feature comparison is dated 2026-07-10 (it names Spectator
  v3.0.2, league v0.24, osteel v0.14, and kirschbaum v2.0.2) and is due for a
  re-check. The research pass flagged `spectator:stubs` as missing; the CLI row
  does list `stubs` for Spectator, so the refresh should verify each cell rather
  than add that row.
- User-facing docs describe competitors by documented technical scope only,
  version-pinned, dated, and linked. They do not cite issue threads about
  another project's maintenance (osteel #44 and #59 stay in this research file
  only), and they do not describe league as "stuck on 3.0".
