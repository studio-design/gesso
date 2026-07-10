# Supported features and known limitations

This is a contract-testing tool: where we can't enforce a constraint precisely, we prefer a loud failure or an explicit "skipped" outcome over silently accepting non-compliant data. The list below pins down what does and does not get checked so you can decide whether the gaps matter for your spec.

- [OpenAPI 3.0, 3.1, and 3.2](#openapi-30-31-and-32)
  - [`readOnly` / `writeOnly` enforcement](#readonly--writeonly-enforcement)
- [Body validation](#body-validation)
- [Parameter styles](#parameter-styles)
- [Security schemes](#security-schemes)
- [Schema features](#schema-features)
- [HTTP methods](#http-methods)
- [Spec features not consulted](#spec-features-not-consulted)
- [Warning channel (`E_USER_WARNING` contract)](#warning-channel-e_user_warning-contract)

## OpenAPI 3.0, 3.1, and 3.2

The package accepts OpenAPI `3.0.x`, `3.1.x`, and `3.2.x`. The root `openapi` field must be a string in explicit `major.minor.patch` form (for example, `3.0.4`, `3.1.2`, or `3.2.0`). Patch releases within a supported minor use the same feature set, following the [OpenAPI version policy](https://spec.openapis.org/oas/latest.html#versions-and-deprecation).

Missing, empty, non-string, malformed, and unsupported values fail spec loading with `InvalidOpenApiSpecException`. This includes Swagger / OpenAPI 2.x, OpenAPI 3.3.x, and unknown future versions. They are never interpreted as 3.0.

For supported versions, the package detects the OAS feature family from the `openapi` field and handles schema conversion accordingly:

| Feature | 3.0 handling | 3.1 / 3.2 handling |
|---|---|---|
| `nullable: true` | Converted to type array `["string", "null"]`; `null` appended to `enum` if present | Not applicable (uses type arrays natively) |
| `prefixItems` | N/A | Preserved and enforced natively by JSON Schema 2020-12 |
| `$dynamicRef` / `$dynamicAnchor` | N/A | Preserved and enforced natively |
| `examples` (array) | Removed (Draft 2020-12 keyword, not Draft 07) | Removed (Draft 2020-12 keyword, not Draft 07) |
| `const` | N/A | Preserved and enforced natively |
| `readOnly` / `writeOnly` | Semantic enforcement (see below). Forbidden properties become boolean `false` subschemas; the keyword is dropped as OAS-only on surviving properties | Semantic enforcement (see below). Forbidden properties become boolean `false` subschemas; the keyword is preserved as an annotation on surviving properties |

For OpenAPI 3.1/3.2, the root `jsonSchemaDialect` supplies the default dialect and a resource-root Schema Object's `$schema` overrides it. The OpenAPI base dialect is evaluated as JSON Schema 2020-12 after OpenAPI-specific semantics (`readOnly` / `writeOnly` and `discriminator`) are applied. Opis-supported JSON Schema Draft 06, Draft 07, 2019-09, and 2020-12 declarations are accepted. Unknown custom dialects fail with `InvalidOpenApiSpecException` / `UnsupportedJsonSchemaDialect` instead of silently falling back to Draft 07.

OpenAPI 3.2 is backward compatible with 3.1, so ordinary 3.2 operations use the tested 3.1 conversion pipeline. The behavior below follows the official [OpenAPI 3.2 specification](https://spec.openapis.org/oas/v3.2.0.html) and [3.1-to-3.2 upgrade guide](https://learn.openapis.org/upgrading/v3.1-to-v3.2.html). The contract-relevant 3.2 additions have explicit behavior:

- `QUERY` works in direct validators, PSR-7, Laravel, Symfony, Pest, fuzz exploration, and coverage reports.
- Custom methods under `additionalOperations` resolve in direct request/response validators and the PSR-7 adapter, preserving their case-sensitive method spelling, and appear in coverage. The enum-based framework and fuzz adapters accept `QUERY` but not arbitrary custom method tokens; whole-spec exploration reports those operations as skipped with a reason instead of silently omitting them.
- One `in: querystring` parameter with `application/x-www-form-urlencoded` content validates the entire framework-parsed query map against its schema. Mixing it with `in: query`, declaring it more than once, or omitting its schema fails loudly. Other query-string media types emit `[OpenAPI 3.2 querystring]` because the public validator receives a parsed map rather than the original serialized query string.
- `discriminator.defaultMapping` is enforced for missing and unknown values when an explicit `mapping` is also present. With implicit mappings only, missing values use the fallback while unknown present values rely on the underlying `oneOf` / `anyOf`; `[OpenAPI 3.2 discriminator]` makes that residual limitation observable.
- `itemSchema` streaming bodies are returned as `Skipped` with a reason and matched content type. Framework adapters buffer a complete body, while the PSR-7 adapter refuses to consume a non-seekable JSON stream; neither path can safely apply a schema independently to each SSE, JSON Lines, JSON Text Sequence, or multipart stream item.
- A root `$self` emits `[OpenAPI 3.2 $self]`: relative references still resolve from the retrieved file path, so specs depending on a different `$self` base URI must be pre-bundled.

### `readOnly` / `writeOnly` enforcement

Both validators apply OpenAPI's asymmetric semantics instead of letting the keywords pass as no-ops:

- **Response validation** (`OpenApiResponseValidator`, Laravel trait): any property marked `writeOnly: true` must **not** appear in the response body. If it does, validation fails with the offending property named in the error. A `writeOnly + required` entry is treated as absent on the response side, so a compliant response that omits the property still validates.
- **Request validation** (`OpenApiRequestValidator`): any property marked `readOnly: true` must **not** appear in the request body. `readOnly + required` is treated as absent on the request side, so a compliant request that omits the property still validates.

Detection looks at each property schema's own top-level `readOnly` / `writeOnly`; markers nested inside the property's `allOf` / `oneOf` / `anyOf` children are not enforced in the current release.

## Body validation
- **Validated**: `application/json` and any `+json` structured-syntax suffix (RFC 6838), and content keys using ranges (`application/*`, `*/*`) — the matcher tries exact match first, then `<type>/*`, then `*/*`.
- **Multi-JSON-per-status specs** (e.g. `application/json` + `application/problem+json` for the same status): when the actual response Content-Type is supplied, schema validation prefers the spec key that exactly matches the response Content-Type before falling back to the first JSON key. A problem-details body served as `application/problem+json` is judged against its own schema, not the success-shape `application/json` schema. Vendor `+json` suffixes the spec doesn't enumerate (e.g. `application/vnd.example.v1+json`) still fall through to the first JSON key, preserving the legacy interchangeable-JSON behaviour for that case.
- **Presence-only** (no schema validation): every other media type, including `application/xml`, `multipart/form-data`, `application/x-www-form-urlencoded`, `text/plain`, and `application/octet-stream`. The validator confirms the spec declares the content type but does not check the body. When the matched media-type entry declares a `schema` (OpenAPI permits a schema on any media type, but this JSON Schema engine cannot evaluate a non-JSON one), the orchestrator marks the response/request as `Skipped` with a `skipReason` so the unvalidated body is surfaced in coverage rather than counted as a clean pass. A non-JSON entry with no `schema` has nothing to validate and stays a plain success.
- **OpenAPI 3.2 streaming `itemSchema`**: explicitly `Skipped`, never counted as a clean validation. `prefixEncoding` / `itemEncoding` are therefore not enforced either.
- **Multipart `encoding` object**: per-part `contentType` / `headers` / `style` / `explode` are not consulted.
- **Cascading `additionalProperties: false` errors** are stripped automatically. opis's `PropertiesKeyword` skips its `addCheckedProperties()` call whenever any sub-property fails its schema, leaving `$checked` empty in the validation context. The follow-on `additionalProperties: false` keyword then reports every property the data carries — including ones explicitly declared in the schema's `properties` — as "additional". The validator walks opis's `ValidationError` tree, reads the raw list of "additional" property names from `args()['properties']`, and filters out names that ARE declared in the schema's `properties` keyword at that path. A single failure shows as one error, not a paired pseudo-error naming declared properties as not-allowed. Genuine additional properties still surface; mixed cases keep only the real extras in the message. The walker descends through single-schema `items`, Draft 07 tuple-form `items`, and native 2020-12 `prefixItems`. Composition keywords and other ambiguous routing shapes fall through to keeping the original message untouched, so a real violation is never silently swallowed.

## Parameter styles
- **Query**: only `style: form` + `explode: true` (the OAS default). Specs declaring `pipeDelimited`, `spaceDelimited`, `deepObject`, or `form` + `explode: false` are not parsed; type-mismatch errors will surface but they will point at the wrong cause.
- **Query string (3.2)**: `in: querystring` with `application/x-www-form-urlencoded` validates the whole parsed query map. Other media types emit a categorized warning and skip query-string validation.
- **Header / Path**: only `style: simple` for scalar values. `type: array` and `type: object` parameters are not parsed (the raw string is fed to the schema, which then mismatches). `style: matrix` and `style: label` for path parameters are not handled — the prefix is not stripped before validation.
- **Cookie parameters** (`apiKey` security scheme aside): not validated.
- **`parameters[].content`**: read only for OpenAPI 3.2 `in: querystring`; other parameter locations still use `parameters[].schema` only.

## Security schemes
- **Validated**: `apiKey` (in `header` / `query` / `cookie`) and `http` + `bearer` — presence checks for the named header/query/cookie / RFC 6750 `Bearer` token.
- **Loud `E_USER_WARNING` on first encounter**: `oauth2`, `openIdConnect`, `mutualTLS`, and `http` schemes other than `bearer` (`basic`, `digest`). When every scheme in a security requirement is unsupported the requirement still passes (false-negative avoidance — blocking the test for a spec we cannot evaluate is worse than letting it through), but the validator fires a one-shot per-scheme-name warning so the silent pass does not stay invisible. The warning is emitted as a single line (shown wrapped here for readability):

  ```
  [security] OAuth2 scheme 'oauth2_user' is silently passed (no token check) — POST /v1/users. The opis/json-schema-based validator cannot verify oauth2 / openIdConnect / mutualTLS / http-basic / http-digest credentials. Your test will not detect a missing or invalid token. Workaround: split the bearer-token surface into a separate test, or assert the Authorization header presence manually.
  ```

  Under `phpunit.xml` `failOnWarning="true"` this surfaces as a test failure on first encounter — the recommended setting if your spec contains any of these scheme types, since green tests against unauthenticated requests are the worst-class silent failure for a contract-testing tool.

## Schema features
- **Validated in every supported dialect**: `type`, `enum`, `multipleOf`, `minimum`/`maximum`/`exclusiveMinimum`/`exclusiveMaximum`, `minLength`/`maxLength`/`pattern`, `minItems`/`maxItems`/`uniqueItems`, `minProperties`/`maxProperties`/`required`, `additionalProperties` (`true` / `false` / schema), `allOf` / `oneOf` / `anyOf` / `not`.
- **Native in OpenAPI 3.1/3.2**: `const`, `prefixItems`, `$dynamicRef`, `$dynamicAnchor`, `unevaluatedProperties`, `unevaluatedItems`, `dependentSchemas`, and `dependentRequired`. These are preserved and delegated to the selected JSON Schema dialect rather than lowered or discarded.
- **`format`** (validated by opis Draft 06+): the canonical 19-entry set (`email`, `uuid`, `date`, `date-time`, `uri`, `ipv4`, `ipv6`, `hostname`, `regex`, `json-pointer`, …). The full list is the authoritative `KNOWN_OPIS_FORMATS` constant in `src/Spec/OpenApiSchemaConverter.php` — keeping it in one place avoids drift when opis adds formats. Unknown values (e.g. `format: emial` typo for `email`) emit a one-shot `E_USER_WARNING` per format value, since opis silently accepts any value for unrecognised formats. Non-string `format` values fire a separate malformed-spec warning.
- **Advisory `format`** (deliberately not enforced, no warning): `int32`, `int64`, `float`, `double`, `byte`, `binary`, `password`. Treated as documentation hints per OAS conventions; see `ADVISORY_FORMATS` constant.
- **Lowered**: `discriminator` + `mapping` / `defaultMapping` → an `allOf` of `if`/`then` conditionals (default; see `discriminator` below). OpenAPI 3.0 `nullable` is lowered for Draft 07 compatibility.
- **Stripped**: `xml`, `externalDocs`, `example` / `examples`, `deprecated`, and OAS-only `nullable`/`readOnly`/`writeOnly` after enforcement (3.0). `discriminator` is also stripped when enforcement is turned off (`enforce_discriminator: false`).
- **Validated via opis (Draft 06+)**: `patternProperties`, `contentMediaType`, `contentEncoding`. These are JSON Schema keywords that opis implements natively, so your constraints are enforced.
- **OpenAPI 3.0 compatibility warnings**: `unevaluatedProperties`, `unevaluatedItems`, `dependentSchemas`, and `dependentRequired` still emit `E_USER_WARNING` when placed in a 3.0 Schema Object because its compatibility pipeline targets Draft 07.
- **`contentSchema`**: preserved in 3.1/3.2. JSON Schema defines the content vocabulary as annotation-only by default, so it is not treated as a decoded-body assertion unless the validator's content hooks support that media type.
- **`discriminator`** (enforced by default, #262): when a schema declares `discriminator` with a non-empty `mapping`, the converter lowers it into an `allOf` of an unknown-value guard (the discriminator property must be present and one of the mapping keys) plus one `if`/`then` per mapping value, where `then` is the resolved subtype schema. The discriminator value therefore steers validation toward a single branch — a body that lies about its type (e.g. `kty: RSA` carrying EC-only fields) fails instead of passing the underlying `oneOf` / `anyOf` union. This is stricter than the OAS spec strictly requires (the discriminator is officially a tooling hint), which is exactly what a contract-testing tool wants. No `E_USER_WARNING` is emitted.
  - **Opt out**: set `enforce_discriminator: false` (Laravel config) or `<parameter name="enforce_discriminator" value="false"/>` (the PHPUnit extension; `0` / `no` also work) to restore the historical behaviour — `discriminator` is stripped and the mapping is not enforced (and no warning is emitted).
  - **Malformed blocks**: with enforcement on, a structurally invalid `discriminator` (missing/non-string `propertyName`, non-array `mapping`, non-string mapping value, an unresolvable mapping pointer, or a pointer to a non-object) surfaces as a loud validation failure rather than silently passing.
  - **OpenAPI 3.2 `defaultMapping`**: with explicit mapping keys, absent and unknown discriminator values validate against the fallback schema. Without explicit keys, only the absent-value fallback can be reconstructed after eager `$ref` resolution; a categorized warning exposes the unknown-value limitation.
  - **Known limitation**: a self-referential discriminator chain (a subtype that, via `allOf` + `$ref`, re-contains the *same* base discriminator — the inheritance idiom) is enforced at the first recursion level; the inner re-appearance of that same discriminator is stripped without re-lowering (the outer branch already routes to and enforces that exact subtype). This terminates the lowering and avoids combinatorial blow-up while still enforcing the outer branch selection. Subtype-specific constraints (e.g. `required`) are unaffected — they live in the outer `then`.
  - **`nullable` + `discriminator`** (3.0): a `null` body fails the discriminated-object branch (the lowered guard requires the discriminator property). Model a null-tolerant polymorphic field with an explicit `oneOf` including `{type: 'null'}` if needed.
- **`readOnly` / `writeOnly`**: enforced at the property's own top level only (see [readOnly / writeOnly enforcement](#readonly--writeonly-enforcement)).

## HTTP methods
The PHPUnit coverage report counts `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, OpenAPI 3.2 `QUERY`, and every custom method declared under `additionalOperations`. Laravel, Symfony, Pest, and the fuzz explorer accept the six named enum methods including `QUERY`; arbitrary custom tokens are supported by the direct validators, PSR-7 adapter, and coverage tracker. Whole-spec exploration enumerates `HEAD`, `OPTIONS`, `TRACE`, and case-sensitive custom methods but records them as explicit skips because it cannot dispatch them through the enum-based generated-case API. Direct validator and PSR-7 adapter calls can still resolve them.

## Spec features not consulted
Webhooks (3.1+), Callbacks, Response `Links`, Server URL templating (`servers` with `variables`), Examples (`example` / `examples`, including 3.2 `dataValue` / `serializedValue` — not used for fuzzing or validation), 3.2 tag hierarchy (`summary` / `parent` / `kind`), `externalDocs`, and vendor extensions (`x-*` keys, ignored harmlessly). OAuth2 device authorization and other OAuth/OpenID schemes remain on the existing `[security]` warning path.

## Warning channel (`E_USER_WARNING` contract)

The library uses PHP's native `trigger_error(..., E_USER_WARNING)` as the loud-signal channel for silent-pass conditions the validator cannot enforce. **This is the v1.0 official API**: warnings are dedup'd per-process and prefixed with a category tag so callers can route or filter them mechanically.

| Category prefix | Source | Dedup key |
|---|---|---|
| `[security]` | `SecurityValidator` (`oauth2`, `openIdConnect`, `mutualTLS`, `http-basic`, `http-digest`) | scheme name |
| `[OpenAPI Schema]` | `OpenApiSchemaConverter` (3.0-only `unevaluated*` / `dependent*`, unknown / malformed `format`) | per-keyword / per-format-value |
| `[OpenAPI 3.2 querystring]` | `QueryParameterValidator` (serialized query media type cannot be reconstructed) | declared media-type set |
| `[OpenAPI 3.2 discriminator]` | `OpenApiSchemaConverter` (`defaultMapping` with implicit mappings only) | process-wide limitation key |
| `[OpenAPI 3.2 $self]` | `OpenApiSpecLoader` (`$self` base URI is not applied) | spec load/cache |

**How to consume:**

- **Default** (PHPUnit `failOnWarning="true"`): the first warning fails the test. Recommended for contract-testing pipelines, since silent-pass on auth or unknown formats is the worst-class failure mode.
- **Stay green, surface warnings in output**: omit `failOnWarning` (PHPUnit 10+ default is `false`). Warnings show in the test report but do not fail.
- **Capture programmatically** (e.g. for a custom report):
  ```php
  set_error_handler(static function (int $errno, string $errstr): bool {
      if ($errno === E_USER_WARNING && str_starts_with($errstr, '[security]')) {
          MyReport::record($errstr);
          return true; // suppress
      }
      return false; // bubble
  });
  ```
- **Suppress one category** (e.g. acknowledged limitation): match on the category prefix in your error handler. Do not blanket-suppress all `E_USER_WARNING`s — unrelated warnings would silently disappear.

**Why not exceptions / PSR-3 logger / structured payload on `OpenApiValidationResult`?** The simple channel is zero-dep, integrates with every PHP framework's existing error handler, and stays out of the v1.0 SemVer surface. A structured channel (`WarningCollector`, PSR-3 sink, or `result->warnings()`) can be added in v1.x as additive without breaking — we are deliberately deferring until real-world usage demands it. See [issue #149](https://github.com/studio-design/openapi-contract-testing/issues/149) for the design discussion.

**Per-process dedup vs per-test:** the dedup state is process-global. PHPUnit runs all tests in one process by default, so a warning fired in test A is not fired again in test B even if both schemas exhibit the issue. The `*::resetWarningStateForTesting()` helpers (annotated `@internal`) exist as test seams for the converter / security validator's own tests; downstream tests rarely need them.
