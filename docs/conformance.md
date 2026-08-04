# JSON Schema conformance

Gesso rewrites your OpenAPI Schema Objects before validating anything: OAS 3.0
is lowered to Draft 07, `discriminator` becomes `if`/`then` conditionals,
`nullable` becomes a type array, annotation keywords are stripped, and
`readOnly` / `writeOnly` become boolean subschemas. That rewriting is the part
of a contract-testing tool most likely to quietly change what your spec means.

This page measures exactly where it does. Of 3,784 official test-suite cases put
through both pipelines, conversion changes the verdict on 126 — 115 of them one
tracked defect, the other 11 deliberate. Every one is listed with a reason and
pinned in CI, so the number can only move when someone updates the record.

## What is measured

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

**This page does not claim that Gesso is a conformant JSON Schema validator.**
That claim belongs to opis, and it is measured independently by
[Bowtie][bowtie] via the `php-opis-json-schema` harness — a report Gesso has no
hand in producing. What is measured here is the delta Gesso adds on top of
opis, because that is the part no external report covers.

## Current result

Corpus: [`json-schema-org/JSON-Schema-Test-Suite`][suite] at
`cf2e5e0ff2e3d90239c3b59e68ac4c080bd4ac92` (MIT), pinned by commit SHA in
`composer.json`. `composer.lock` is not committed for a library, so
`composer.json` is the pin.

| Suite | OAS pipeline | In corpus | Excluded | Boolean root | Compared | Verdict changed |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| `draft7` | 3.0 → Draft 07 | 1,657 | 0 | 18 | 1,639 | 56 |
| `draft2020-12` | 3.1 / 3.2 → 2020-12 | 2,167 | 4 | 18 | 2,145 | 70 |
| **Total** | | **3,824** | **4** | **36** | **3,784** | **126** |

Required and `optional/` cases are both included, `optional/format/` among
them. The suite's `remotes/` directory is served at `http://localhost:1234/` as
the suite expects, so remote `$ref` cases genuinely resolve instead of failing
identically on both sides.

OAS 3.2 shares the 3.1 conversion pipeline, so running it would reproduce the
3.1 numbers exactly and it is not run twice.

### The 126 differences

Three causes. Each recorded case cites one of them by key in the baseline's
`reasons` map.

**`empty-schema-object` — 115 cases. A real defect, tracked in
[#478](https://github.com/studio-design/gesso/issues/478).** An empty Schema
Object `{}` is indistinguishable from an empty array once a spec is decoded
with `json_decode(..., true)`, so it reaches opis as a JSON array and is
rejected as not-a-schema. `additionalProperties: {}`, `properties: {x: {}}`,
`items: {}` and `not: {}` are ordinary OpenAPI and all hit it. 114 of these
surface as a loud `InvalidKeywordException`; one degrades further —
`{"items": {}, "additionalItems": false}` becomes tuple-form `items: []`, so a
compliant array is reported as a contract violation with no error at all. That
last case is the reason this harness was worth building: it is a false failure
that no existing test caught.

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

### The 4 exclusions

`unevaluatedItems with $dynamicRef` and `unevaluatedProperties with $dynamicRef`
(2020-12) are skipped before they run. Bare opis recurses without bound on them
and exhausts the stack — the conversion layer is not involved, and the
resulting fatal error is not catchable from PHP, so the groups have to be
excluded statically. They are listed in the baseline with that reason so the
exclusion stays visible instead of silently shrinking the corpus.

## Running it

```bash
composer install   # fetches the pinned corpus into vendor/
vendor/bin/phpunit tests/Integration/Conformance/JsonSchemaConversionDeltaTest.php
```

The corpus is a normal dev dependency, declared as an inline
`repositories` entry in `composer.json` because the suite is not published to
Packagist and carries no usable upstream tags.

## Updating the pinned corpus

1. Bump `dist.url`, `dist.reference`, and `source.reference` in the
   `composer.json` repository entry to the new commit SHA, and bump the
   package `version` (Composer will not refresh a package repository unless the
   version changes).
2. `composer update json-schema-org/json-schema-test-suite`.
3. Run the test. It will report the new case counts and any verdict that moved.
4. Update `corpus.commit`, `suites`, and any `deltas` entries in the
   baseline — **each citing a key in the `reasons` map** — and update the
   table above. A delta citing an unknown reason, or a published reason no
   longer cited by anything, fails a second assertion, so an unexplained
   regression cannot be papered over by regenerating the file.

If a newly added corpus group triggers the same unbounded opis recursion, the
test process will die with a fatal error rather than fail cleanly. Add the
group to `EXCLUDED_GROUPS` in the test and to `excluded_groups` in the baseline.

[suite]: https://github.com/json-schema-org/JSON-Schema-Test-Suite
[opis]: https://opis.io/json-schema
[bowtie]: https://bowtie.report
