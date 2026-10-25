# v3 research: verified gaps against competitor failure modes

- Date: 2026-10-26
- Status: research input for [ADR 0007](../adr/0007-v3-scope-and-release-window.md)
  (Proposed). Internal; not shipped in the package.
- Baseline: `origin/main` at `6f2c26c` (release manifest 2.7.1).
- Method: small probe scripts (not committed) built one minimal OpenAPI document
  per case and drove it through the public `OpenApiRequestValidator::validate()`
  / `OpenApiResponseValidator::validate()` entry points with
  `DecodedBody::decodeJson()` bodies, and ran `gesso doctor` on the same
  documents. The case ids below (`1`, `R1`, `5p`, `N1`, `S`, …) name those probe
  cases. Spec text was checked against OAS 3.0.4, 3.1.1, and 3.2.0.

## Relation to ADR 0004

This analysis was written against [ADR 0004](../adr/0004-v3-consistency-policy-and-protected-core.md),
which says "v3 changes shape, not capability" and lists oasdiff-style spec diffing,
an official GitHub Action, and "Gesso calling an LLM" as non-goals. Almost
everything below is a bug fix or an additive feature, and both are SemVer-clean
in 2.x minors. Verdict-tightening fixes (for example, cookie parameters that
used to pass now fail) are allowed in a minor under the same reasoning #262 used
for discriminator enforcement, but each one needs a changelog note, and the ones
that move a conformance baseline need their own review. ADR 0007 (Proposed)
addresses the scope question for v3; until it is accepted, ADR 0004 governs.

## 1. Dependency tree

- Runtime `require`: `opis/json-schema ^2.6` (with `opis/string` and `opis/uri`),
  `phpunit/phpunit`, and the `psr/http-client`, `psr/http-factory`, and
  `psr/http-message` interfaces. That is the full list.
- There is no `cebe/php-openapi`. The only `cebe/*` package in the lock file is
  `cebe/markdown`, which arrives through dev-only `league/commonmark` (Laravel
  dev tooling) and is not reachable with `--no-dev`.
- Spec parsing is in-house: `OpenApiSpecLoader` → `Internal\SpecDocumentDecoder`
  (`json_decode`; YAML through the optional `symfony/yaml`
  `Yaml::parse(..., PARSE_OBJECT_FOR_MAP)`) → `OpenApiRefResolver` (eager `$ref`
  inlining) → `OpenApiSchemaConverter` → opis. Parser-upgrade breakage of the
  kind reported in league #248 therefore cannot happen here. The trade-off is
  that Gesso owns reference resolution, and §2.A row 8g shows its one real hole.

## 2. Verified table

Buckets: **OK** = correct per spec · **OK-strict** = stricter than the spec,
deliberately · **FP** = false positive (a valid input fails) · **FN** = silent
pass of an invalid input · **SKIP** = honest `Skipped` outcome · **CRASH** =
engine exception surfaced as a failure · **DOC≠RT** = `gesso doctor` says
COMPATIBLE while the runtime fails or skips.

### A. Competitor complaints

| Case | Scenario (competitor ref) | Gesso result | Bucket |
| --- | --- | --- | --- |
| 1 | Stops at first error (league #35) | 3 errors: required (b, c), `/a` type, `/d` type; `issues()` carries category, instancePath, and keyword | OK |
| R1 | Request: bad path + missing query + missing header + bad body | 4 errors, one per source | OK |
| R2 | 30 body errors, default `maxErrors` | 20 body + 1 header = 21 (the cap is per schema run; `maxErrors: 0` is unlimited and gives 31) | OK |
| 1c | Errors cannot be customised (league #201) | `ValidationIssue` (category, message, instancePath, keyword, method, path, statusCode, contentType, parameter) plus the versioned `JsonValidationResultRenderer` (`schema_version: 1`) | OK |
| 2 | `Accept` / `Content-Type` / `Authorization` header parameters (league #165) | Not validated as parameters, **but every request to the operation fails** with a "Reserved in:header parameter" spec error, even when the header is sent; doctor reports COMPATIBLE with 0 issues | **FP + DOC≠RT** |
| 3a–h | Security (league #248): bearer missing, wrong scheme, OR, AND, `security: []` override, optional `{}`, global inheritance | All correct | OK |
| 3i–j | oauth2 / HTTP basic with no credentials | Success plus a one-shot `[security]` `E_USER_WARNING` | OK (documented) |
| 4 | 3.1 webhooks (league #218) | "No matching path found … POST newPet"; there is no webhook API | **Gap** |
| 5a/5b | `oneOf` + discriminator with overlapping branches | Fails "should match exactly one schema" | OK: the JSON Schema `oneOf` verdict. OAS 3.2 §4.25 (Discriminator Object) says the discriminator "MUST NOT change the validation outcome" |
| 5c | Discriminator steers to the branch and reports the branch error | `/bark` type error only | OK |
| 5d | Unknown discriminator value | Enum guard plus `oneOf` errors | OK-strict (documented in #262) |
| 5f/5h | 3.0 `{nullable: true, allOf/oneOf: [$ref]}` with `null` | Success | Lenient. OAS 3.0.4 §4.7.24 says `nullable` "only takes effect if type is explicitly defined within the same Schema Object". It matches user expectation, so keep it |
| 5g/5i/5j | `nullable` inside a `$ref` target; nullable enum | Success | OK |
| 5j2 | 3.0 `nullable` + `allOf`, wrong inner type | Fails correctly, plus a noisy extra "`[/owner]` must match type null" | OK (message noise) |
| **N1** | **3.0 `allOf: [{$ref}, {nullable: true}]` (league #208 idiom)** | **CRASH**: `InvalidKeywordException: allOf[1] must be a json schema`; doctor COMPATIBLE | **CRASH + DOC≠RT** |
| **S** | **Any subschema made only of stripped annotations (`{example}`, `{deprecated}`, `{xml}`, `{externalDocs}`, 3.0 `{nullable}`) in `allOf`, `properties.x`, or `items`** | **CRASH** in 3.0 and 3.1 (`description`, `title`, or `readOnly` alone are fine) | **CRASH + DOC≠RT** |
| 5k/5l | `readOnly` + `required` omitted from a request, top level and inside `allOf` (Spectator #183, league #176) | Success | OK |
| 5m/5o | `readOnly` sent in a request; `writeOnly` leaked in a response, inside `allOf` | Fails | OK |
| N2 | Property `$ref` → `Id{readOnly}` sent in a request | Fails (eager `$ref` inlining puts the marker at the top level) | OK |
| **5p** | Property `allOf: [{$ref Id(readOnly)}]` sent in a request | **Success** | **FN** (documented limitation) |
| N3 | 3.0 `nullable` + `oneOf` + discriminator, `null` | Fails | Documented limitation |
| 6a–f | `type: object` without properties vs string / array / number; untyped `properties`; `additionalProperties: false`; `{}` (Spectator #201) | All correct | OK |
| 7a–f/7h | Integer, number, boolean, and uuid path parameters (Spectator #210) | Coerced and validated correctly | OK |
| **7g/P1/P2** | **Mixed literal and template segments: `/report.{format}`, `/v/{a}-{b}`, `/files/{name}.{ext}`** | **No path match**, or garbage placeholder names (`name}.{ext`) reported as a "malformed spec"; doctor COMPATIBLE | **FP + DOC≠RT** |
| 8a–e | 3.1 with no `$schema` → 2020-12: `$dynamicRef` recursion, `prefixItems`, `unevaluatedProperties`, `dependentRequired` (Prism complaint) | All enforced | OK |
| 8h | Explicit `$schema: draft-07` inside 3.1 | Draft-07 tuple semantics honoured | OK |
| 8f | Draft-04-style array `items` in 3.1 | Failure carrying opis `InvalidKeywordException` text | Loud (acceptable). Should become a `response.spec` category and a doctor error |
| **8g** | **`$id`-scoped refs: `$ref: https://ex.test/list` to an embedded `$id`; `#/$defs/A` under a schema with `$id`** | **Throws** "HTTP(S) $ref is disallowed" / "Unresolvable $ref" at spec load | **Gap (2020-12 conformance)** |

### B. Gesso's own documented gaps (all confirmed)

| Case | Scenario | Result | Bucket |
| --- | --- | --- | --- |
| 9a/9b | `deepObject` `filter[age]=5` (valid) | **Fails** "string must match integer"; values are not coerced | **FP** |
| 9c | `form` + `explode: false` object `color=R,100` | Fails "must match type object" | **FP** |
| 9d/9e | Header `type: array` `X-Ids: 1,2` | Fails "must match type array" | **FP** |
| 9f/9g | Path `style: matrix` `;id=5`, `label` `.5` | Fails type integer | **FP** |
| 9h | Path `type: array` `1,2` | Fails type array | **FP** |
| 9i/9j | `in: cookie` required + missing, or wrong type | **Success, no warning** | **FN + DOC≠RT** |
| 9k | `parameters[].content` (JSON in query) with an invalid value | **Success, no warning** | **FN + DOC≠RT** |
| 9l/9p | XML / `text/plain` with a schema | Skipped with a reason | SKIP (doctor reports 0 skipped → DOC≠RT) |
| 9m/9n | SSE / JSONL `itemSchema` (bad item) | Skipped | SKIP (DOC≠RT) |
| 9o | Multipart response | Skipped | SKIP (DOC≠RT) |
| 9q | Wrong response Content-Type | Fails, lists the declared types | OK |
| 9r | Undocumented 5xx | Skipped by the default `5\d\d` pattern | OK (configurable) |
| — | oauth2, OIDC, mTLS, basic, digest | Pass plus a warning; doctor reports `skipped` | OK (documented; doctor agrees) |

Doctor on a combined document (14 operations covering all of B): **COMPATIBLE,
Errors 0, Warnings 0, Skipped 0**.

### C. Competitor features: does Gesso already have them?

| Feature | Gesso status |
| --- | --- |
| Spectator `spectator:stubs` / `validate` / LLM-friendly JSON errors | `gesso stubs`, `gesso doctor`, and `JsonValidationResultRenderer` exist |
| Schemathesis stateful testing | Already there: the `src/Arazzo` workflow runner and `followLinksUsing()` link chains (`docs/fuzzing.md`, `docs/arazzo.md`) |
| Schemathesis TraceCov keyword-level schema coverage | Missing (§4.1) |
| Schemathesis GitHub Action | ADR 0004 non-goal |
| byjg / duyler webhooks | Missing (§4.2) |
| byjg / duyler streaming NDJSON / SSE | Missing (§4.3) |
| oasdiff breaking changes | ADR 0004 non-goal (§4.4) |
| API Platform has no OpenAPI-document validation | Gesso's core value already |

## 3. Gap designs

Effort: S ≤ 1 day · M = 2–4 days · L = 1–2 weeks. Each regression test pins
outcome, category, and instancePath, never message prose (`AGENTS.md`).

### G1. Annotation-only subschema crash (CRASH, DOC≠RT) — S, highest priority

- Root cause: `OpenApiSchemaConverter::convertSubschemaInPlace()` maps `[]` to
  `true` *before* `convertInPlace()` strips `OPENAPI_COMMON_KEYS` /
  `OPENAPI_3_0_KEYS`. A slot that becomes empty *after* stripping reaches opis as
  a JSON array.
- Fix (verified): add `if ($slot === []) { $slot = true; }` after the
  `convertInPlace()` call. Applied in a scratch checkout, it turned all 11 `S`
  probes to Success, while `OpenApiSchemaConverterTest` (131 tests) and
  `JsonSchemaConversionDeltaTest` stayed green, so the **baseline did not move**.
  The root schema is already safe: `{example: 1}` as the whole response schema
  validates.
- N1 after the fix: `allOf: [Owner, {nullable: true}]` with `null` fails "null
  must match object". That is spec-correct per 3.0.4 (`nullable` without a
  sibling `type` has no effect). Leniency would follow the 5f/5h precedent but is
  a policy call: 5f/5h put `nullable` on the same Schema Object as the
  applicator, while N1 puts it in a bare branch. Recommendation: a one-shot
  `[OpenAPI Schema]` warning ("nullable without type in allOf branch has no
  effect") rather than leniency.
- Doctor: once G1 lands, this class no longer crashes. Add a doctor guard that
  runs each request or response schema through `SchemaValidatorRunner` once, so
  any future opis `InvalidKeywordException` surfaces as a doctor error (this also
  closes DOC≠RT for 8f).
- Files: `src/Spec/OpenApiSchemaConverter.php`,
  `tests/Unit/Spec/OpenApiSchemaConverterTest.php`, a new fixture
  `tests/fixtures/specs/annotation-only-subschemas.json`.
- Demand: the league #208 idiom; `{example: …}`-only properties are common in
  generated specs (Scramble, l5-swagger).

### G2. Reserved header parameters fail every request (FP, DOC≠RT) — S

- Spec: 3.2 Parameter Object `name`: "If in is "header" and the name field is
  "Accept", "Content-Type" or "Authorization", the parameter definition SHALL be
  ignored." 3.1.1 and 3.0.4 say the same.
- Today `src/Validation/Request/ParameterCollector.php:74` drops the entry
  (correct) **and** pushes an error, so the request fails. "SHALL be ignored"
  means the runtime must not fail. Change it to a one-shot `E_USER_WARNING` in a
  new category `[OpenAPI parameter]` (deduplicated per spec, operation, and
  name), plus a doctor `warning`. A new warning category is a compatibility
  surface: add a row to the `docs/supported-features.md` diagnostics table and to
  `docs/versioning.md`.
- Files: `src/Validation/Request/ParameterCollector.php`,
  `src/Cli/DoctorCommand.php`, `docs/supported-features.md`, tests.
- Demand: league #165, plus Scramble and l5-swagger output that routinely
  declares `Authorization` / `Accept` headers.

### G3. Mixed literal and template path segments (FP, DOC≠RT) — M

- Spec: 3.2 §4.8.2 Path Templating ABNF,
  `path-segment = 1*( path-literal / template-expression )`. A segment may mix
  literals and several expressions (`/report.{format}`, `/v/{a}-{b}`).
- `src/Spec/OpenApiPathMatcher.php:71` recognises only whole-segment
  `^\{(.+)\}$`. Design: tokenize each segment with
  `preg_split('/(\{[^{}]+\})/', …, PREG_SPLIT_DELIM_CAPTURE)`, quote the literal
  parts, and emit one `([^/]+?)` per expression, anchored by the surrounding
  literals (the regex is already anchored with `$`, so lazy captures resolve left
  to right). Pure-literal paths already win (they go to `$literalPaths`). The tie
  that needs a new tiebreak is template-vs-template at the same segment count,
  such as `/{file}` vs `/report.{format}` (both have `literalSegments = 0`
  today); count literal characters inside mixed segments as the secondary sort
  key. Keep the duplicate-name and `MAX_PARAMETERS` guards. Reject adjacent
  expressions with no literal between them (`{a}{b}`) as ambiguous, and mirror
  that in doctor.
- `PathParameterValidator` needs no change if the matcher returns correct
  names, because its reverse check reads the captured names. Add a doctor check
  that compiles every template through the same matcher, so an uncompilable
  template is a doctor error.
- Files: `src/Spec/OpenApiPathMatcher.php`,
  `tests/Unit/Spec/OpenApiPathMatcherTest.php`, a fixture.
- Demand: `.{format}` suffixes (Rails, Laravel, Django REST); adjacent to
  Spectator #210.

### G4. Parameter styles: deepObject, non-exploded object, header/path array and object, matrix/label (FP) — M

- Spec: 3.2 §4.12.3 Style Values ("matrix and label styles produce a leading
  delimiter which is always a valid part of the serialization and MUST be
  included"). Defaults: path and header use `simple`, query and cookie use
  `form`; `explode` defaults to true only for `form` / `cookie`.
- Design: generalise `Validation/Support/QueryStyleDeserializer` into a
  `ParameterStyleDeserializer` keyed on `(in, style, explode, schemaType)`:
  - `simple` array `1,2` → list; `simple` object with `explode: false`
    `R,100,G,200` → map; with `explode: true` `R=100,G=200` → map.
  - `label`: strip the leading `.`; `explode: false` splits on `,`, `explode: true`
    on `.`.
  - `matrix`: strip `;name=`; arrays as `;id=1,2` or exploded `;id=1;id=2`;
    objects likewise.
  - `form` object with `explode: false`, `color=R,100,G,200` → map.
  - `deepObject`: the framework has already parsed `filter[age]` into a nested
    array, so the fix is that `TypeCoercer::coerceQuery` must coerce object
    properties (today it handles only arrays and primitives, which is why 9b
    fails).

  Then coerce with a new `TypeCoercer::coerceObject()` (per-property primitive
  coercion), and route the path, header, and query validators through it.
- Files: `src/Validation/Support/QueryStyleDeserializer.php` (renamed internally;
  it is `@internal`), `src/Validation/Support/TypeCoercer.php`,
  `src/Validation/Request/{Path,Header,Query}ParameterValidator.php`,
  `docs/supported-features.md`.
- Demand: a documented Gesso gap. FP failures push users to
  `withoutRequestValidation()`, which hides everything else too.

### G5. Cookie parameters (FN, DOC≠RT) — S/M

- `OpenApiRequestValidator` already receives `$cookies` (used for apiKey). Add a
  `CookieParameterValidator` with the same shape as `HeaderParameterValidator`
  (required, schema, primitive coercion; `form` / 3.2 `cookie` style for arrays
  and objects, using the G4 deserializer). PSR-7 adapter: parse the `Cookie`
  header when `getCookieParams()` is empty (client-side requests). Until this
  lands, doctor must report `skipped` for cookie parameters.
- Files: `src/Validation/Request/CookieParameterValidator.php` (new),
  `src/OpenApiRequestValidator.php`, cookie extraction in `src/Psr7/`.

### G6. `parameters[].content` outside querystring (FN, DOC≠RT) — S/M

- Spec: a parameter MUST have `schema` or `content`, and `content` holds exactly
  one media type.
- Today `src/Validation/Request/QueryParameterValidator.php:95` sees no `schema`
  and, for an optional parameter, `continue`s silently. Design: in each location
  validator, when `content` is present, take its single entry. If it is JSON-like
  (`ContentTypeMatcher::isJsonContentType`), `json_decode` the raw value (a
  decode failure is a validation error) and validate against
  `content[mt].schema`; otherwise return `Skipped` or warn. The querystring path
  already reads `content`, so reuse that helper.
- Files: `ParameterCollector.php` (malformed: both or neither of `schema` /
  `content`, multiple media types), the
  `{Query,Header,Path,Cookie}ParameterValidator.php` location validators.

### G7. readOnly / writeOnly through a property-level `allOf` wrapper (FN) — M

- Case 5p: `id: {allOf: [{$ref: Id}]}` (the 3.0 idiom for "ref plus
  description") is not enforced; whole-object `allOf` already works (5m/5o).
- Design: in the converter's readOnly/writeOnly property scan, treat a property
  as read-only if its own schema **or any `allOf` branch** (recursively, after
  eager ref resolution) carries `readOnly: true`. For `oneOf` / `anyOf`, mark it
  only when **every** branch is read-only (a conservative rule that cannot
  produce an FP). JSON Schema 2020-12 annotation semantics (`readOnly` collected
  from every successful applicator) support the `allOf` rule.
- Files: `src/Spec/OpenApiSchemaConverter.php` (same file as G1, so sequence it
  after G1). Check that `StrictRequiredSchemaWalker` uses the same predicate.

### G8. `$id`-scoped references (8g) — M/L

- JSON Schema 2020-12 Core §8.2.1: `$id` sets the base URI of a schema resource,
  and `$ref` resolves against it. An embedded resource referenced by its `$id`
  URI is not a remote fetch.
- Design: in `OpenApiRefResolver`, before treating an absolute `$ref` as HTTP,
  consult a per-document index of `$id` URI → node pointer built in a pre-pass.
  Track the current base URI while walking so `#/$defs/A` under an `$id`
  resolves inside that resource. A lower-risk alternative leaves `$id` subtrees
  unresolved for opis to resolve natively and resolves only
  `#/components/...` pointers eagerly, but it conflicts with the flattening that
  the readOnly and discriminator passes rely on, so prefer the index.
- Files: `src/Spec/OpenApiRefResolver.php`, `src/Spec/RefResolutionContext.php`.
- Demand: low today (no competitor complaint), but rising with schemas
  generated by zod, pydantic, and TypeSpec that carry `$id`.

### G9. Doctor ⇄ runtime parity sweep (DOC≠RT, ADR 0004 protected-core row 6) — S/M

- One PR, `src/Cli/DoctorCommand.php` plus tests. It emits:
  - `skipped/feature`: `itemSchema` media types, non-JSON media types that carry
    `schema`, multipart responses, cookie parameters, `parameters[].content`,
    `webhooks`, and `callbacks` (until G5, G6, §4.2, and §4.3 land; each later
    PR removes its own line);
  - `warning`: reserved header parameters (aligned with G2);
  - `error`: uncompilable path templates (aligned with G3) and schemas that opis
    rejects (the G1 guard).
- This is the cheapest way to get an honest signal. Ship it first and shrink it
  as the gaps close.

## 4. Beyond-competitor designs

### 4.1 In-suite keyword-level schema coverage (vs Schemathesis TraceCov) — L

- What: report, per `(method, path, status, content-type)` schema, which
  **keywords and branches** were exercised across the suite: each `oneOf` /
  `anyOf` branch matched, each `enum` value seen, each optional property present
  and absent, `nullable` null seen, each discriminator mapping hit, and the
  `additionalProperties` schema used. TraceCov does this for fuzzed traffic;
  Gesso can do it **for the real test suite**.
- How: generalise the existing parallel walk in `StrictRequiredBodyWalker` /
  `StrictRequiredSchemaWalker` (schema plus body walk for `required`) into a
  `SchemaKeywordObserver` that records `(schemaPointer, keyword, detail)` hits on
  every successful validation. `oneOf` branch selection re-validates the body
  against each branch through `SchemaValidatorRunner` (already cached), bounded
  by the existing [branch-enumeration bounds](../superpowers/specs/2026-08-03-branch-enumeration-bounds-contract-design.md).
  The keyword universe comes from the same walker over the resolved spec.
- Output: a new `Coverage/SchemaKeywordCoverageState`, a section in the console,
  HTML, JSON, and Markdown renderers, a sidecar field for paratest merges, and
  `coverage:gate --min-keyword-coverage`. The JSON and sidecar formats are
  compatibility surfaces, so add a **versioned optional field**
  (`schema_keyword_coverage`, with a `format_version` bump).
- Files: `src/Coverage/*` (new state and renderers),
  `src/Validation/Strict/StrictRequired*Walker.php` (extract the shared walker),
  the observer hook in `src/OpenApiResponseValidator.php` /
  `src/OpenApiRequestValidator.php`, the parameter in
  `src/PHPUnit/OpenApiCoverageExtension.php`, and `docs/coverage.md` /
  `docs/coverage-json-schema.md`.
- In scope under ADR 0004: it checks the implementation against the spec.

### 4.2 Webhooks and callbacks validation — M

- Spec: the 3.1/3.2 root `webhooks` is a map of name → Path Item ("The incoming
  webhooks that MAY be received as part of this API…"). It has no URL, so it is
  addressed by name. Callbacks are
  `operation.callbacks[name][runtime-expression] → Path Item` (3.2 §4.20.3
  Runtime Expressions).
- Direction: the validator is direction-agnostic, and both cases matter.
  (a) **Receiving**, the larger PHP case: a Laravel or Symfony app receives
  Stripe or GitHub webhooks. The test posts the payload to `/webhooks/stripe` and
  checks it against the provider document's
  `webhooks.payment_intent.succeeded`, the same "provider spec as a named spec"
  pattern as `docs/consumer-side-contract-testing.md`. (b) **Sending**: the app
  emits its own webhooks or callbacks, and the outgoing request (captured by
  `Http::fake()` / `MockHttpClient`) is checked against the app's own spec.
- API (additive):
  - `OpenApiRequestValidator::validateWebhook(string $spec, string $webhookName, string $method, array $headers, mixed $body, ?string $contentType)`,
    and `OpenApiResponseValidator::validateWebhookResponse(...)`;
  - `validateCallback(string $spec, string $operationPathTemplate, string $operationMethod, string $callbackName, string $expression, ...)`,
    where the expression key is matched literally. Gesso does not evaluate
    runtime expressions against a live request; evaluating
    `{$request.body#/url}` against a recorded originating request is an optional
    later step.
- Implementation: add `ResponseSchemaResolver::locateWebhook()` /
  `locateCallback()`, returning the same `ResponseOperationResolution` with
  `matchedPath = "webhook:<name>"`. Everything downstream (ParameterCollector,
  RequestBodyValidator, SecurityValidator, body and headers) is reused
  unchanged. Coverage rows use the `webhook:<name>` / `callback:<op>#<name>`
  pseudo-paths at the same granularity. Laravel helper:
  `assertWebhookMatchesOpenApi('newPet', …)` on top of `Http::fake()`.
- Files: `src/Validation/Response/ResponseSchemaResolver.php`,
  `src/OpenApiRequestValidator.php`, `src/OpenApiResponseValidator.php`,
  `src/Coverage/OpenApiCoverageTracker.php` (enumerate webhooks into the
  universe), `src/Laravel/*` (helper), docs. Doctor counts webhook operations
  (the conformance `webhook-example` goes from 0 operations to 1), so the
  **baseline moves** and needs its own review per ADR 0004.
- Demand: league #218; byjg and duyler advertise it.

### 4.3 Streaming `itemSchema` validation (SSE / JSONL / NDJSON / json-seq) — M

- Spec: the 3.2 Media Type `itemSchema` "MUST be applied to each item in the
  stream independently". JSONL (`application/jsonl`), NDJSON
  (`application/x-ndjson`), and JSON Text Sequences (`application/json-seq`,
  `+json-seq`) are sequential media types. For `text/event-stream`,
  "implementations MUST work with event data after it has been parsed according
  to the text/event-stream specification, including … ignoring certain fields
  (including comments) … and on combining values split across multiple lines";
  `retry` is an integer and every other field is a string (3.2 §4.14.4).
- Design: a new `@internal` `Validation/Support/SequentialBodyDecoder`:
  - JSONL / NDJSON: split on `\n` (tolerate `\r\n`, skip one trailing empty
    line) and `json_decode` each line (a decode failure is an error at
    `/<index>`);
  - json-seq: split on `\x1E` and trim the trailing LF;
  - SSE: the WHATWG parser (`data` lines joined with `\n`, `:` comments dropped,
    digits-only `retry` → int, field and value split on the first `:` plus an
    optional space). It yields `{event, data, id, retry}` objects, and `data`
    stays a **string**. Users who model JSON in `data` use `contentMediaType` /
    `contentSchema`; validate `contentSchema` when `contentMediaType` is JSON.
    That is a Gesso opt-in, flagged in the docs, because 2020-12 makes it
    annotation-only;
  - validate each item with `itemSchema` and prefix the instancePath with
    `/<itemIndex>`. When `schema` is also present, validate the whole array
    against `schema` (3.2: the complete content is "treated as an array").
- Wire it at the current skip site in `src/OpenApiResponseValidator.php`
  (around line 632) and in `ResponseBodyValidator`. The adapters must pass the
  **raw string**: Laravel `TestResponse::streamedContent()` for
  `StreamedResponse` (today `src/Laravel/ValidatesOpenApiSchema.php:529` fails
  loudly on `getContent() === false`), Symfony `StreamedResponse` through output
  buffering, PSR-7 by reading a seekable stream. A non-seekable stream keeps
  today's `Skipped`. `itemEncoding` / `prefixEncoding` (multipart streams) stay
  `Skipped`.
- Files: `src/Validation/Support/SequentialBodyDecoder.php` (new),
  `src/OpenApiResponseValidator.php`,
  `src/Validation/Response/ResponseBodyValidator.php`,
  `src/Validation/Response/ResponseSchemaResolver.php` (outcome),
  `src/Laravel/ValidatesOpenApiSchema.php`, `src/Symfony/*`, `src/Psr7/*`.
- Demand: byjg and duyler advertise streaming; LLM APIs (SSE token streams) are
  the fastest-growing case, and no PHP competitor implements the 3.2 SSE parsing
  rules.

### 4.4 `--breaking` via oasdiff — recommend not doing it

- ADR 0004 lists oasdiff-style spec diffing as a non-goal ("spec versus spec,
  with no implementation in the comparison"), and
  `src/Cli/CoverageGateCommand.php:59-70` says it is deliberately not a
  breaking-change detector. Delegating to oasdiff output does not change that
  classification.
- A thinner variant does fit: `coverage:gate --changed-from=<oasdiff.json>`,
  which *reads* an oasdiff changelog and requires every changed or breaking
  `(method, path, status)` to have been exercised by the suite. That answers
  "did the tests touch what changed?", an implementation check, and it would
  still need an ADR note. Effort S/M.

## 5. PR plan

Waves, with file overlaps called out rather than claimed disjoint. The open PRs
are linked; the rest are not opened yet.

| Wave | PR | Files | Effort | Depends on |
| --- | --- | --- | --- | --- |
| 1 | G1, [#648](https://github.com/studio-design/gesso/pull/648) | `src/Spec/OpenApiSchemaConverter.php`, converter test, fixture | S | — |
| 1 | G2, [#649](https://github.com/studio-design/gesso/pull/649) | `src/Validation/Request/ParameterCollector.php`, its tests, `docs/supported-features.md` (diagnostics table) | S | — |
| 1 | G3, [#652](https://github.com/studio-design/gesso/pull/652) | `src/Spec/OpenApiPathMatcher.php`, matcher tests, fixture | M | — |
| 1 | G9, [#650](https://github.com/studio-design/gesso/pull/650) | `src/Cli/DoctorCommand.php`, `tests/Unit/Cli/DoctorCommandTest.php`, `docs/doctor.md` | S/M | logically after G1–G3 (reuses the matcher compile and runner); disjoint file set |
| 2 | G4 `feat(validation): deserialize simple, label, matrix, and object parameter styles` | `src/Validation/Support/{QueryStyleDeserializer,TypeCoercer}.php`, `src/Validation/Request/{Path,Header,Query}ParameterValidator.php` | M | G3 (path tests) |
| 2 | G5 `feat(validation): validate cookie parameters` | new `CookieParameterValidator.php`, `src/OpenApiRequestValidator.php`, `src/Psr7/*` | S/M | G4 (cookie arrays and objects use its deserializer) |
| 2 | G6 `feat(validation): validate parameters declared with content` | `ParameterCollector.php` (**overlaps G2**), the four location validators (**overlaps G4/G5**) | S/M | after G2, G4, G5 |
| 2 | G7 `fix(spec): enforce readOnly/writeOnly through property allOf` | `OpenApiSchemaConverter.php` (**overlaps G1**), `StrictRequiredSchemaWalker.php` | M | after G1 |
| 3 | §4.3 `feat(validation): validate streaming itemSchema bodies` | new `SequentialBodyDecoder.php`, `OpenApiResponseValidator.php`, `ResponseBodyValidator.php`, adapters | M | — |
| 3 | §4.2 `feat(validation): validate webhooks and callbacks` | `ResponseSchemaResolver.php`, `OpenApiRequestValidator.php` (**overlaps G5**), `OpenApiResponseValidator.php` (**overlaps §4.3**), `OpenApiCoverageTracker.php`, conformance baseline | M | after G5 and §4.3 |
| 3 | §4.1 `feat(coverage): report keyword-level schema coverage` | `src/Coverage/*`, strict walkers (**overlaps G7**), validator observer hook (**overlaps §4.2/§4.3**), PHPUnit extension | L | last |
| 3 | G8 `fix(spec): resolve $id-scoped references in place` | `OpenApiRefResolver.php`, `RefResolutionContext.php` | M/L | independent |

Each later wave removes its own line from the G9 doctor `skipped` list in the
same PR, so doctor and runtime stay aligned at every merge (ADR 0004 row 6). The
wave 1 PRs have pairwise disjoint file sets.

`docs/supported-features.md` is touched by nearly every PR, because each one
removes its own limitation line. Treat that as an expected trivial rebase, not a
real overlap.
