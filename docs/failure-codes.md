# Failure codes

<!-- Generated from src/Internal/FailureCatalogue.php by `php scripts/generate-failure-codes-doc.php`. Do not edit by hand. -->

Validator issues, `gesso doctor` diagnostics, and the coverage and strict gate verdicts
carry a stable failure code. Text output shows it as `[GSO212]`;
JSON output has `code` and `docs_url` fields. `vendor/bin/gesso explain GSO212` prints
the entry below in the terminal, and `--format=json` prints it for tools and agents.

Codes are a compatibility surface ([versioning](versioning.md)). A code keeps its
meaning for the whole major line and is never reused; new codes may appear in minor
releases. Ranges group the codes: `GSO0xx` general, `GSO1xx` request validation,
`GSO2xx` response validation, `GSO3xx` doctor, `GSO4xx` coverage gates and merge,
`GSO5xx` strict contract gates. `GSO6xx`-`GSO9xx` are reserved.

Usage errors (exit code `2`) have no code: the message and the usage text say what to fix.

## General (GSO0xx)

### GSO001

**Unclassified validation failure.** The result was built by code that only passed error strings, so Gesso cannot say which validator produced it.

Validator issue category `unknown`.

Common causes:

- A custom integration calls `OpenApiValidationResult::failure()` without `issues:`.

How to fix:

- Read the message for the actual problem.
- If you own the integration, pass `ValidationIssue` objects so the failure gets a specific code.

Do not match on the message text in CI; it is not a compatibility surface.

## Request validation (GSO1xx)

### GSO101

**Request path is not in the spec.** No path template in the spec matches the request path, so the request cannot be validated.

Validator issue category `request.path_match`.

Common causes:

- The endpoint is new and not documented yet.
- A base path or version prefix (`/api`, `/v1`) is in the request but not in the spec paths.
- A trailing slash or a typo differs between the route and the spec.

How to fix:

- Spec wrong: add the path to `paths`.
- Prefix mismatch: set `spec.strip_prefixes` in `gesso.php`.
- Test wrong: fix the URL the test calls. The failure lists the closest spec paths.

Do not skip the test with `#[SkipOpenApi]`; an undocumented endpoint is the drift contract testing exists to catch.

### GSO102

**Request method is not defined for the path.** The path exists in the spec, but it declares no operation for this HTTP method.

Validator issue category `request.method`.

Common causes:

- The operation is missing from the spec.
- The test uses the wrong method (for example `PUT` instead of `PATCH`).

How to fix:

- Spec wrong: add the operation under the path item.
- Test wrong: call the documented method.

Do not skip the test; an undocumented operation is real drift.

### GSO103

**Request side of the spec is malformed.** A node the request validator needs (an operation, parameter, or request body) is not shaped as OpenAPI requires, so it cannot be enforced.

Validator issue category `request.spec`.

Common causes:

- A parameter is missing `name` or `in`, or has a non-object schema.
- A `$ref` resolved to the wrong kind of object.

How to fix:

- Run `vendor/bin/gesso doctor` for the exact node and fix the spec.

Do not skip the test; a malformed spec node fails every request to this operation.

### GSO104

**Path parameter does not match its schema.** A path parameter value does not satisfy the schema the spec declares for it.

Validator issue category `request.parameter.path`.

Common causes:

- The test passes a value of the wrong shape (a slug where the spec says integer).
- The spec schema is narrower than the application accepts.

How to fix:

- Test wrong: use a valid value.
- Spec wrong: widen the parameter schema.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO105

**Query parameter is missing or does not match its schema.** A required query parameter is absent, or a query value does not satisfy its schema. `keyword` is `required` when the parameter is missing.

Validator issue category `request.parameter.query`.

Common causes:

- The test omits a required parameter.
- A value has the wrong type or is outside an `enum`.
- The `style` / `explode` serialization differs from what the client sends.

How to fix:

- Test wrong: send the documented parameter.
- Spec wrong: fix `required`, the schema, or the serialization fields.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO106

**Header parameter is missing or does not match its schema.** A required request header is absent, or its value does not satisfy its schema. `keyword` is `required` when the header is missing.

Validator issue category `request.parameter.header`.

Common causes:

- The test does not send the header.
- The value has the wrong format.

How to fix:

- Test wrong: send the header.
- Spec wrong: fix `required` or the schema.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO107

**Security requirement is not satisfied.** No security requirement of the operation is satisfied. `keyword` is `required` when credentials are absent and `format` when they are present but unusable.

Validator issue category `request.security`.

Common causes:

- The test sends no credentials.
- The credential goes to a different header, query, or cookie than the scheme declares.
- The operation should be public but inherits root-level `security`.

How to fix:

- Test wrong: send credentials (Laravel: `laravel.auto_inject_dummy_credentials`).
- Spec wrong: fix the scheme, or set `security: []` on a public operation.
- A scheme Gesso cannot check (for example `mutualTLS`): list it in `validation.acknowledged_unvalidatable_schemes`.

Do not remove `security` from the spec to make the test pass.

### GSO110

**Request body does not match its schema.** The request body violates a schema keyword that has no more specific code. `keyword` names the failing keyword.

Validator issue category `request.body`.

Common causes:

- The test sends a body the spec does not allow.
- The spec schema is stricter than the application.

How to fix:

- Use `instance_path` and `keyword` to find the field.
- Test wrong: send a valid body.
- Spec wrong: fix the schema.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO111

**Request body is missing a required property.** An object in the request body lacks a property listed in `required`.

Validator issue category `request.body` with keyword `required`.

Common causes:

- The test omits a field.
- The spec marks a field required that the application treats as optional.

How to fix:

- Test wrong: send the field.
- Spec wrong: remove it from `required`.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO112

**Request body value has the wrong type.** A request body value has a JSON type the schema does not allow.

Validator issue category `request.body` with keyword `type`.

Common causes:

- A number is sent as a string, or the reverse.
- A value is `null` but the schema is not nullable.

How to fix:

- Test wrong: send the documented type.
- Spec wrong: widen `type` (OpenAPI 3.0: `nullable: true`; 3.1+: `type: [x, "null"]`).

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO113

**Request body has an undeclared property.** The request body has a property the schema forbids through `additionalProperties: false` or `unevaluatedProperties: false`.

Validator issue category `request.body` with keyword `additionalProperties` or `request.body` with keyword `unevaluatedProperties`.

Common causes:

- The client sends a field the spec does not document.
- A typo in a property name.

How to fix:

- Spec wrong: document the property.
- Test wrong: stop sending it.

Do not set `additionalProperties: true` only to silence this.

### GSO114

**Request body value is not an allowed value.** A request body value is not in the schema's `enum`, or differs from its `const`.

Validator issue category `request.body` with keyword `enum` or `request.body` with keyword `const`.

Common causes:

- A new enum value was added in code but not in the spec.
- Case or spelling differs.

How to fix:

- Spec wrong: add the value to `enum`.
- Test wrong: send an allowed value.

Do not drop the `enum` from the spec only to silence this.

### GSO115

**Request body value does not match its format.** A request body string does not satisfy the schema `format` (`date-time`, `uuid`, `email`, ...).

Validator issue category `request.body` with keyword `format`.

Common causes:

- A date is sent without a time zone or in another layout.
- The spec declares a stricter format than the application uses.

How to fix:

- Test wrong: send a value in the documented format.
- Spec wrong: change or remove `format`.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO116

**Request body cannot be decoded.** The request body could not be read or parsed for its content type (for example invalid JSON), so its schema could not be checked.

Validator issue category `request.body` with keyword `parse`.

Common causes:

- The test sends malformed JSON.
- The `Content-Type` header does not match the body.

How to fix:

- Send a body that parses as its declared content type.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

## Response validation (GSO2xx)

### GSO201

**Response request context is not in the spec.** The path or method of the request that produced this response is not in the spec, so the response has no operation to check against.

Validator issue category `response.request_context`.

Common causes:

- The endpoint is undocumented.
- A path prefix is not stripped (`spec.strip_prefixes`).

How to fix:

- Spec wrong: add the path or operation.
- Prefix mismatch: set `spec.strip_prefixes`.
- Test wrong: call the documented path.

Do not skip the test with `#[SkipOpenApi]`; an undocumented endpoint is real drift.

### GSO202

**Response side of the spec is malformed.** A node the response validator needs (`responses`, a response object, or its content) is not shaped as OpenAPI requires.

Validator issue category `response.spec`.

Common causes:

- `responses` is not an object.
- A `$ref` resolved to the wrong kind of object.

How to fix:

- Run `vendor/bin/gesso doctor` for the exact node and fix the spec.

Do not skip the test; a malformed spec node fails every response of this operation.

### GSO203

**Response status is not documented.** The operation declares no response for this status code, no matching range (`4XX`), and no `default`.

Validator issue category `response.status`.

Common causes:

- An error path returns a status the spec does not list (often 401, 403, 404, 422).
- The application returns the wrong status.

How to fix:

- Spec wrong: document the response.
- Implementation wrong: return the documented status.

Do not list the status in `validation.skip_response_codes` to hide an undocumented response.

### GSO204

**Response header is missing or does not match its schema.** A required response header is absent, or its value does not satisfy its schema. `keyword` is `required` when the header is missing.

Validator issue category `response.header`.

Common causes:

- The application does not set the header on this path.
- The value has the wrong format.

How to fix:

- Implementation wrong: set the header.
- Spec wrong: fix `required` or the schema.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO205

**Response media type is not declared.** The response arrived as a JSON media type the spec does not declare for this status, so the body was checked against another JSON entry. Read it together with the body failure next to it.

Validator issue category `response.content_type`.

Common causes:

- The application returns `application/problem+json` but the spec only declares `application/json`, or the reverse.

How to fix:

- Spec wrong: declare the media type.
- Implementation wrong: send the documented `Content-Type`.

Do not delete the `content` entry to make the body check go away.

### GSO210

**Response body does not match its schema.** The response body violates a schema keyword that has no more specific code. `keyword` names the failing keyword.

Validator issue category `response.body`.

Common causes:

- The application returns a body the spec does not allow.
- The spec is out of date.

How to fix:

- Use `instance_path` and `keyword` to find the field.
- Decide which side is wrong, then fix the implementation or the schema.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO211

**Response body is missing a required property.** An object in the response body lacks a property listed in `required`.

Validator issue category `response.body` with keyword `required`.

Common causes:

- The serializer omits a field when it is null or empty.
- The spec marks a field required that is optional in practice.

How to fix:

- Implementation wrong: always serialize the field (as `null` if the schema allows it).
- Spec wrong: remove it from `required`.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO212

**Response body value has the wrong type.** A response body value has a JSON type the schema does not allow.

Validator issue category `response.body` with keyword `type`.

Common causes:

- A value is `null` but the schema is not nullable.
- A numeric string is returned where the spec says number, or the reverse.
- An empty PHP array serializes as `[]` where the schema expects an object.

How to fix:

- Implementation wrong: cast the value.
- Spec wrong: widen `type` (OpenAPI 3.0: `nullable: true`; 3.1+: `type: [x, "null"]`).

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO213

**Response body has an undeclared property.** The response body has a property the schema forbids through `additionalProperties: false` or `unevaluatedProperties: false`.

Validator issue category `response.body` with keyword `additionalProperties` or `response.body` with keyword `unevaluatedProperties`.

Common causes:

- The application returns a field the spec does not document yet.
- A resource leaks an internal field.

How to fix:

- Spec wrong: document the property.
- Implementation wrong: stop returning it.

Do not set `additionalProperties: true` only to silence this.

### GSO214

**Response body value is not an allowed value.** A response body value is not in the schema's `enum`, or differs from its `const`.

Validator issue category `response.body` with keyword `enum` or `response.body` with keyword `const`.

Common causes:

- A PHP enum gained a case the spec does not list (see enum drift).

How to fix:

- Spec wrong: add the value to `enum`.
- Implementation wrong: return an allowed value.

Do not drop the `enum` from the spec only to silence this.

### GSO215

**Response body value does not match its format.** A response body string does not satisfy the schema `format` (`date-time`, `uuid`, `email`, ...).

Validator issue category `response.body` with keyword `format`.

Common causes:

- A date is serialized without a time zone or in another layout.

How to fix:

- Implementation wrong: serialize in the documented format.
- Spec wrong: change or remove `format`.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

### GSO216

**Response body cannot be decoded.** The response body could not be read or parsed for its content type (for example invalid JSON), so its schema could not be checked.

Validator issue category `response.body` with keyword `parse`.

Common causes:

- The application emits debug output or a PHP warning before the JSON.
- The `Content-Type` header does not match the body.

How to fix:

- Implementation wrong: return a body that parses as its declared content type.

Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.

## Doctor diagnostics (GSO3xx)

### GSO301

**Spec file cannot be read.** The spec path does not name a readable file.

`gesso doctor` issue category `io`.

Common causes:

- A wrong path or `spec.base_path`.
- File permissions.

How to fix:

- Check the path and the permissions.

Do not remove the spec from `spec.names` to make doctor pass.

### GSO302

**Spec cannot be parsed.** The spec is not valid JSON or YAML, has an unsupported extension, or its root is not a mapping.

`gesso doctor` issue category `parser`.

Common causes:

- A syntax error.
- A YAML spec without `symfony/yaml` installed.

How to fix:

- Fix the syntax.
- For YAML: `composer require --dev symfony/yaml`.

Do not convert the spec by hand to dodge the parser; fix the source.

### GSO303

**OpenAPI version is missing or unsupported.** The `openapi` field is missing, or names a version Gesso does not support (3.0, 3.1, and 3.2 are supported).

`gesso doctor` issue category `version`.

Common causes:

- A Swagger 2.0 document.
- A typo in the `openapi` field.

How to fix:

- Convert Swagger 2.0 to OpenAPI 3.x.
- Set `openapi` to the version the document follows.

Do not change the version number without converting the document.

### GSO304

**JSON Schema dialect is unsupported.** `jsonSchemaDialect` or a schema `$schema` names a dialect Gesso cannot enforce.

`gesso doctor` issue category `dialect`.

Common causes:

- A custom or draft dialect URI.

How to fix:

- Use the OpenAPI default dialect for the document version.

Do not remove the dialect declaration if the schemas really depend on it.

### GSO305

**Reference cannot be resolved.** A `$ref` points to a missing target, a file outside the allowed root, a remote document that is not allowed, or forms a cycle Gesso cannot follow.

`gesso doctor` issue category `references`.

Common causes:

- A typo in the pointer.
- A remote `$ref` without `--allow-remote-refs`.
- A local file outside `--local-ref-root`.

How to fix:

- Fix the pointer.
- Bundle the spec, or allow the exact remote host or local root.

Do not widen the allowed reference root beyond the directory you trust.

### GSO306

**Spec structure is invalid.** A node has the wrong shape for OpenAPI (for example `paths` is not an object, or a discriminator is inconsistent). Runtime validation fails on the same node.

`gesso doctor` issue category `structure`.

Common causes:

- A hand edit broke a node.
- A generator emitted an invalid node.

How to fix:

- Fix the node the message names.

Do not disable discriminator enforcement only to make doctor pass.

### GSO307

**Schema keyword or format is not enforced.** The spec uses a keyword or format Gesso does not enforce, so values that violate it pass.

`gesso doctor` issue category `keyword`.

Common causes:

- A vendor format, or a keyword outside the selected dialect.

How to fix:

- Accept the gap knowingly, or express the rule with a keyword Gesso enforces (`pattern`, `enum`, ...).

Do not assume the constraint is tested.

### GSO308

**Spec feature is not validated.** Doctor reports a feature Gesso skips (for example webhooks or an unvalidatable security scheme). This is reported as `skipped`, not as an error.

`gesso doctor` issue category `feature`.

Common causes:

- The spec uses a feature outside what runtime validation checks.

How to fix:

- See the supported-features page. For security schemes, acknowledge them in `validation.acknowledged_unvalidatable_schemes`.

Do not read a clean doctor run as proof that skipped features are tested.

### GSO309

**Optional dependency is missing.** An option needs a package that is not installed.

`gesso doctor` issue category `dependency`.

Common causes:

- `--allow-remote-refs` without a PSR-18 HTTP client.

How to fix:

- Install the package the message names.

Do not drop the option if the spec needs it; bundle the spec instead.

### GSO310

**Configuration is inconsistent with the spec.** `gesso.php` or the doctor options name a spec, prefix, or setting the runtime would reject or never use.

`gesso doctor` issue category `configuration`.

Common causes:

- A `spec.names` entry that leaves `spec.base_path`.
- A stale `spec.strip_prefixes` or `spec.default`.

How to fix:

- Fix the configuration key the message names.

Do not delete the key without checking what it was for.

### GSO311

**Spec is invalid.** The loader rejected the spec for a reason that has no more specific code.

`gesso doctor` issue category `spec`.

Common causes:

- See the message.

How to fix:

- Fix the spec as the message describes.

Do not skip the spec; runtime validation rejects it the same way.

### GSO312

**Spec uses a construct with limited support.** The spec loads, but uses a construct Gesso handles with a documented limitation.

`gesso doctor` issue category `compatibility`.

Common causes:

- A construct listed under known limitations.

How to fix:

- Read the warning and the supported-features page.

Do not ignore the warning without reading the limitation.

### GSO313

**Doctor failed internally.** Doctor hit an unexpected error while analysing the spec.

`gesso doctor` issue category `internal`.

Common causes:

- A bug in Gesso.

How to fix:

- Report it with the spec and the stack trace at <https://github.com/studio-design/gesso/issues>.

Do not assume the spec is fine; the check did not complete.

## Coverage gates and merge (GSO4xx)

### GSO401

**Changed operation is not covered.** `coverage:gate` found a response whose operation changed against the base spec, and no test validated it in this run.

Emitted by `gesso coverage:gate`.

Common causes:

- The pull request changed an operation and added no test for it.
- The coverage document is from another run or another spec (`--spec-name`).

How to fix:

- Add a test that validates the listed response.
- Check that `--coverage` comes from this run.

Do not exclude the operation or point `--base-spec` at the changed spec to pass the gate.

### GSO402

**Coverage is below the threshold.** Endpoint, response, or SDK exercise coverage is below the configured `coverage.min_coverage` threshold.

Emitted by the coverage threshold gate (PHPUnit extension and `gesso coverage:merge`).

Common causes:

- New operations without tests.
- A partial test run.

How to fix:

- Add tests for the uncovered responses the report lists.
- Run the full suite before gating.

Do not lower the threshold to the current number; consider a coverage baseline, which fails on new gaps by name.

### GSO403

**Response is uncovered and not in the coverage baseline.** A declared response was not validated in this run and is not listed in the coverage baseline file.

Emitted by the coverage baseline (PHPUnit extension and `gesso coverage:merge`).

Common causes:

- A new response in the spec without a test.
- A test that used to cover it was removed or skipped.

How to fix:

- Add a test that validates the response.

Do not regenerate the coverage baseline (`GESSO_BASELINE_GENERATE=1`) only to accept the gap.

### GSO404

**Coverage baseline entry is covered now.** A response listed in the coverage baseline was validated in this run, so the entry is stale.

Emitted by the coverage baseline (PHPUnit extension and `gesso coverage:merge`).

Common causes:

- A test now covers the response.

How to fix:

- Remove the entry, or regenerate the baseline with `GESSO_BASELINE_GENERATE=1`.

Do not set `baseline_stale.coverage` to `off` to hide stale entries.

### GSO405

**Parallel run is incomplete.** A worker failed to write its coverage sidecar, or the number of sidecars differs from `--expect-sidecars`, so the merged report would be wrong.

Emitted by `gesso coverage:merge`.

Common causes:

- A worker crashed or was cancelled.
- Sidecars from another run are in the directory.
- A wrong `--expect-sidecars` count.

How to fix:

- Re-run the failed shard.
- Use `GESSO_RUN_ID` to keep runs apart.
- Correct `--expect-sidecars`.

Do not delete the failure markers or drop `--expect-sidecars` to make the merge pass.

### GSO406

**No coverage was recorded.** The run validated no response, so no coverage gate can be evaluated. It fails the run when a strict coverage threshold or the coverage baseline is configured, and is a warning otherwise.

Emitted by the coverage threshold gate and the coverage baseline (PHPUnit extension and `gesso coverage:merge`).

Common causes:

- The PHPUnit extension is not registered.
- The workers wrote no sidecars, or the merge reads the wrong directory.
- No test calls an assertion.

How to fix:

- Register the extension and check `coverage.sidecar_dir`.

Do not turn off `coverage.min_coverage.strict` to make this pass.

## Strict contract gates (GSO5xx)

### GSO501

**Always-present response field is not required.** Strict-required detection saw a field in every observed response, but the schema does not list it in `required`.

Emitted by the strict-required gate (PHPUnit extension and `gesso coverage:merge`).

Common causes:

- The spec under-declares `required`.

How to fix:

- Spec wrong: add the field to `required`.
- If the field is really optional, add a test whose response omits it.

Do not switch `strict.required.run` to `off` to silence the report.

### GSO502

**Undocumented response property was observed.** Strict additional-properties detection saw a response property the schema does not declare.

Emitted by the strict additional-properties gate (PHPUnit extension and `gesso coverage:merge`).

Common causes:

- The application returns fields the spec does not document.

How to fix:

- Spec wrong: document the property.
- Implementation wrong: stop returning it.

Do not switch `strict.additional_properties.run` to `off` to silence the report.
