# Conformance

Gesso is measured against two corpora nobody here maintains, both pinned by
commit SHA in `composer.json` and asserted on every CI run:

| Corpus | Question it answers | Result |
| --- | --- | --- |
| [OAI/learn.openapis.org][learn] | Does Gesso understand the documents the OpenAPI Initiative itself publishes? | 22 of 22 files load without error; one document reports three not-enforced security schemes |
| [JSON Schema Test Suite][suite] | What does Gesso's schema rewriting change about a schema's meaning? | 11 of 3,784 verdicts move; each one listed with a reason |

Both results are committed as machine-readable baselines, so a number can only
move when someone updates the record.

## OpenAPI example documents

Corpus: [`OAI/learn.openapis.org`][learn] at
`43756549c27cbf84107b190b82c65e0336f2f09f` (CC-BY-4.0) — the example API
descriptions published by the OpenAPI Initiative alongside the specification.

Every document for OAS 3.0, 3.1, and 3.2 is loaded through `OpenApiSpecLoader`
and diagnosed by `gesso doctor`, in **both** its JSON and its YAML form: 11
documents, 22 files. The `examples/v2.0` directory is Swagger 2.0, which this
package does not accept by design, and is not measured.

| Document | OAS | Operations | Responses | Diagnosis |
| --- | --- | ---: | ---: | --- |
| `v3.0/api-with-examples` | 3.0.0 | 2 | 4 | clean |
| `v3.0/callback-example` | 3.0.0 | 1 | 1 | clean |
| `v3.0/link-example` | 3.0.0 | 6 | 6 | clean |
| `v3.0/petstore` | 3.0.0 | 3 | 6 | clean |
| `v3.0/petstore-expanded` | 3.0.0 | 4 | 8 | clean |
| `v3.0/uspto` | 3.0.1 | 3 | 5 | clean |
| `v3.1/non-oauth-scopes` | 3.1.0 | 1 | 0 | clean |
| `v3.1/tictactoe` | 3.1.0 | 3 | 5 JSON / 6 YAML | `warning` — 3 security schemes reported as not enforced |
| `v3.1/webhook-example` | 3.1.0 | 0 | 0 | clean; `webhooks`-only, nothing to enforce |
| `v3.2/3.2-query-example` | 3.2.0 | 1 | 1 | clean |
| `v3.2/3.2-tags-example` | 3.2.0 | 4 | 0 | clean |

Nothing here produces an error: `gesso doctor` exits `0` on all 22 files and
reports `status: ok` for ten of the eleven documents. `tictactoe` is the
exception at `status: warning`, from three `skipped` diagnostics — its HTTP
Basic and OAuth2 schemes are recognized and resolved, then reported as not
enforced rather than silently passed. "Clean" in the table above means no
issue of any severity.

Recorded in `tests/fixtures/compatibility/v1-oas-example-documents.json` and
asserted by `tests/Integration/Conformance/OasExampleDocumentTest.php`. Issues
are pinned as `severity/category` pairs, not prose: message wording is not a
compatibility surface (see [versioning](versioning.md)) and is pinned by the
doctor's own unit tests.

Four things the table is deliberately not hiding:

- **`webhook-example` yields zero operations.** The document describes only
  `webhooks`, which Gesso deliberately does not consult (see
  [supported features](supported-features.md#spec-features-not-consulted)). It
  loads without complaint, and the zero is the honest signal — not a pass.
- **`tictactoe` counts 5 responses as JSON and 6 as YAML.** That difference is
  upstream, not in the pipeline: the YAML form declares a `202` on
  `POST /board/{row}/{column}` that the JSON form omits. Every other document
  is diagnosed identically in both serializations, and the test asserts that.
- **The YAML forms are copied out of the corpus before they are read.** The
  corpus ships `petstore.json` and `petstore.yaml` side by side, and the loader
  resolves a bare spec name to the JSON sibling first — the doctor reports that
  shadowing as a configuration error rather than diagnosing a file the runtime
  would not have loaded. Copying the YAML forms into a tree of their own
  exercises the YAML pipeline for real instead of pinning that diagnostic.
- **Three of these documents used to fail.** `webhook-example`,
  `non-oauth-scopes`, and `3.2-tags-example` omit `paths` or an operation's
  `responses`, which OAS 3.1 made optional; the doctor reported all three as
  structure errors until [#479](https://github.com/studio-design/gesso/pull/479)
  made the check version-aware. That is what this corpus is for.

## JSON Schema conversion delta

Gesso rewrites your OpenAPI Schema Objects before validating anything: OAS 3.0
is lowered to Draft 07, `discriminator` becomes `if`/`then` conditionals,
`nullable` becomes a type array, annotation keywords are stripped, and
`readOnly` / `writeOnly` become boolean subschemas. That rewriting is the part
of a contract-testing tool most likely to quietly change what your spec means.

This section measures exactly where it does. Of 3,784 official test-suite cases
put through both pipelines, conversion changes the verdict on 11, all of them
deliberate. Every one is listed with a reason.

### What is measured

3,784 of the 3,824 cases in the [official JSON Schema Test Suite][suite] are
validated twice:

1. **bare** — the schema exactly as the suite wrote it, through
   [opis/json-schema][opis] alone;
2. **converted** — the same schema after
   `Studio\Gesso\Spec\OpenApiSchemaConverter::convert()`, through the same
   validator.

The remaining 40 are not compared at all. 4 are excluded outright (see below),
and 36 use a boolean `true` / `false` root schema, which a Schema Object cannot
express — `convert()` has no counterpart to produce, so there is no second
verdict to compare against. Both counts are published in the baseline rather
than silently dropped.

Only the cases where the two verdicts differ are recorded. The recorded set is
committed as
`tests/fixtures/compatibility/v1-json-schema-conversion-delta.json`
and asserted by `tests/Integration/Conformance/JsonSchemaConversionDeltaTest.php`
on every CI run. A new entry fails the build; so does a disappeared one.

**This does not claim that Gesso is a conformant JSON Schema validator.**
That claim belongs to opis, and it is measured independently by
[Bowtie][bowtie] via the `php-opis-json-schema` harness — a report Gesso has no
hand in producing. What is measured here is the delta Gesso adds on top of
opis, because that is the part no external report covers.

### Current result

Corpus: [`json-schema-org/JSON-Schema-Test-Suite`][suite] at
`cf2e5e0ff2e3d90239c3b59e68ac4c080bd4ac92` (MIT), pinned by commit SHA in
`composer.json`. `composer.lock` is not committed for a library, so
`composer.json` is the pin.

| Suite | OAS pipeline | In corpus | Excluded | Boolean root | Compared | Verdict changed |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `draft7` | 3.0 → Draft 07 | 1,657 | 0 | 18 | 1,639 | 0 |
| `draft2020-12` | 3.1 / 3.2 → 2020-12 | 2,167 | 4 | 18 | 2,145 | 11 |
| **Total** | | **3,824** | **4** | **36** | **3,784** | **11** |

Required and `optional/` cases are both included, `optional/format/` among
them. The suite's `remotes/` directory is served at `http://localhost:1234/` as
the suite expects, so remote `$ref` cases genuinely resolve instead of failing
identically on both sides.

OAS 3.2 shares the 3.1 conversion pipeline, so running it would reproduce the
3.1 numbers exactly and it is not run twice.

#### The 11 differences

Two causes. Each recorded case cites one of them by key in the baseline's
`reasons` map. The `draft7` suite converts with no verdict change at all.

**`unsupported-dialect` — 9 cases** (`vocabulary.json`,
`format-assertion.json`). These declare a custom metaschema URI in `$schema`.
`OpenApiSchemaDialect::assertSupported()` rejects unknown dialects with
`InvalidOpenApiSpecException` instead of falling back to one with different
semantics. Deliberate, and documented in
[supported features](supported-features.md#openapi-30-31-and-32).

**`stripped-annotation-ref` — 2 cases** (`refOfUnknownKeyword.json`). The
schema points `$ref` at `#/examples/0`. The converter strips `examples` as an
OAS annotation keyword, so the reference target no longer exists and opis
reports an unresolved reference. A spec that references into an `examples`
array is unsupported; the outcome is a loud error, never a silent pass.
Referencing annotation internals is not something OpenAPI sanctions, so this is
documented rather than fixed.

#### The 4 exclusions

`unevaluatedItems with $dynamicRef` and `unevaluatedProperties with $dynamicRef`
(2020-12) are skipped before they run. Bare opis recurses without bound on them
and exhausts the stack — the conversion layer is not involved, and the
resulting fatal error is not catchable from PHP, so the groups have to be
excluded statically. They are listed in the baseline with that reason so the
exclusion stays visible instead of silently shrinking the corpus.

## Running it

```bash
composer install   # fetches both pinned corpora into vendor/
vendor/bin/phpunit tests/Integration/Conformance
```

Both corpora are normal dev dependencies, declared as inline `repositories`
entries in `composer.json` because neither is published to Packagist and
neither carries usable upstream tags. `composer.lock` is not committed for a
library, so `composer.json` is the pin.

## Updating a pinned corpus

1. Bump `dist.url`, `dist.reference`, and `source.reference` in the
   `composer.json` repository entry to the new commit SHA, and bump the
   package `version` (Composer will not refresh a package repository unless the
   version changes).
2. `composer update json-schema-org/json-schema-test-suite` or
   `composer update oai/learn.openapis.org`.
3. Run the test. It will report the new counts and anything that moved.
4. Update `corpus.commit` and the moved entries in the corresponding baseline,
   and update the tables above.

For the conversion delta, every entry must cite a key in the baseline's
`reasons` map. A delta citing an unknown reason, or a published reason no
longer cited by anything, fails a second assertion, so an unexplained
regression cannot be papered over by regenerating the file.

If a newly added JSON Schema group triggers the same unbounded opis recursion,
the test process will die with a fatal error rather than fail cleanly. Add the
group to `EXCLUDED_GROUPS` in the test and to `excluded_groups` in the baseline.

A newly added OpenAPI example document simply appears as an extra entry, and a
removed one as a missing entry; both fail until the baseline records the change.

[suite]: https://github.com/json-schema-org/JSON-Schema-Test-Suite
[learn]: https://github.com/OAI/learn.openapis.org
[opis]: https://opis.io/json-schema
[bowtie]: https://bowtie.report
