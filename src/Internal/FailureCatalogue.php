<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use function array_keys;
use function array_slice;
use function asort;
use function implode;
use function ksort;
use function levenshtein;
use function str_starts_with;
use function strtolower;
use function strtoupper;

/**
 * The one catalogue of stable `GSO` failure codes: validator issues, doctor
 * diagnostics, and coverage / strict gate verdicts (v3 vision item A1).
 *
 * The codes, and what each one means, are a compatibility surface (see
 * docs/versioning.md). Every code is listed here by hand and looked up, never
 * computed, so adding a category can never renumber an existing code. A code
 * is never reused for a different meaning; a retired code stays listed.
 * `docs/failure-codes.md` is generated from this class
 * (`php scripts/generate-failure-codes-doc.php`).
 *
 * @phpstan-type FailureCode array{title: string, summary: string, causes: list<string>, fix: list<string>, do_not: string}
 *
 * @internal The codes, `gesso explain`, and `docs/failure-codes.md` are the supported surface; this class is not.
 */
final class FailureCatalogue
{
    public const DOCS_URL = 'https://studio-design.github.io/gesso/failure-codes';

    /**
     * Range headings, in the order the generated page lists them.
     */
    public const RANGES = [
        0 => 'General (GSO0xx)',
        1 => 'Request validation (GSO1xx)',
        2 => 'Response validation (GSO2xx)',
        3 => 'Doctor diagnostics (GSO3xx)',
        4 => 'Coverage gates and merge (GSO4xx)',
        5 => 'Strict contract gates (GSO5xx)',
    ];

    /**
     * Validator issue category => keyword => code. The `''` keyword is the
     * fallback for every keyword without a code of its own.
     */
    private const ISSUE_CODES = [
        'unknown' => ['' => 'GSO001'],
        'request.path_match' => ['' => 'GSO101'],
        'request.method' => ['' => 'GSO102'],
        'request.spec' => ['' => 'GSO103'],
        'request.parameter.path' => ['' => 'GSO104'],
        'request.parameter.query' => ['' => 'GSO105'],
        'request.parameter.header' => ['' => 'GSO106'],
        'request.security' => ['' => 'GSO107'],
        'request.body' => [
            '' => 'GSO110',
            'required' => 'GSO111',
            'type' => 'GSO112',
            'additionalProperties' => 'GSO113',
            'unevaluatedProperties' => 'GSO113',
            'enum' => 'GSO114',
            'const' => 'GSO114',
            'format' => 'GSO115',
            'parse' => 'GSO116',
        ],
        'response.request_context' => ['' => 'GSO201'],
        'response.spec' => ['' => 'GSO202'],
        'response.status' => ['' => 'GSO203'],
        'response.header' => ['' => 'GSO204'],
        'response.content_type' => ['' => 'GSO205'],
        'response.body' => [
            '' => 'GSO210',
            'required' => 'GSO211',
            'type' => 'GSO212',
            'additionalProperties' => 'GSO213',
            'unevaluatedProperties' => 'GSO213',
            'enum' => 'GSO214',
            'const' => 'GSO214',
            'format' => 'GSO215',
            'parse' => 'GSO216',
        ],
    ];

    /** Doctor issue category => code. */
    private const DOCTOR_CODES = [
        'io' => 'GSO301',
        'parser' => 'GSO302',
        'version' => 'GSO303',
        'dialect' => 'GSO304',
        'references' => 'GSO305',
        'structure' => 'GSO306',
        'keyword' => 'GSO307',
        'feature' => 'GSO308',
        'dependency' => 'GSO309',
        'configuration' => 'GSO310',
        'spec' => 'GSO311',
        'compatibility' => 'GSO312',
        'internal' => 'GSO313',
    ];

    /** Where a code that is not an issue or doctor category is emitted. */
    private const SOURCES = [
        'GSO401' => '`gesso coverage:gate`',
        'GSO402' => 'the coverage threshold gate (PHPUnit extension and `gesso coverage:merge`)',
        'GSO403' => 'the coverage baseline (PHPUnit extension and `gesso coverage:merge`)',
        'GSO404' => 'the coverage baseline (PHPUnit extension and `gesso coverage:merge`)',
        'GSO405' => '`gesso coverage:merge`',
        'GSO406' => 'the coverage threshold gate and the coverage baseline (PHPUnit extension and `gesso coverage:merge`)',
        'GSO501' => 'the strict-required gate (PHPUnit extension and `gesso coverage:merge`)',
        'GSO502' => 'the strict additional-properties gate (PHPUnit extension and `gesso coverage:merge`)',
    ];

    private const NO_SKIP = 'Do not add `#[SkipOpenApi]`, list the status in `validation.skip_response_codes`, or regenerate the violation baseline to make this pass.';

    /** @var array<string, FailureCode> */
    private const CODES = [
        'GSO001' => [
            'title' => 'Unclassified validation failure',
            'summary' => 'The result was built by code that only passed error strings, so Gesso cannot say which validator produced it.',
            'causes' => ['A custom integration calls `OpenApiValidationResult::failure()` without `issues:`.'],
            'fix' => ['Read the message for the actual problem.', 'If you own the integration, pass `ValidationIssue` objects so the failure gets a specific code.'],
            'do_not' => 'Do not match on the message text in CI; it is not a compatibility surface.',
        ],
        'GSO101' => [
            'title' => 'Request path is not in the spec',
            'summary' => 'No path template in the spec matches the request path, so the request cannot be validated.',
            'causes' => [
                'The endpoint is new and not documented yet.',
                'A base path or version prefix (`/api`, `/v1`) is in the request but not in the spec paths.',
                'A trailing slash or a typo differs between the route and the spec.',
            ],
            'fix' => [
                'Spec wrong: add the path to `paths`.',
                'Prefix mismatch: set `spec.strip_prefixes` in `gesso.php`.',
                'Test wrong: fix the URL the test calls. The failure lists the closest spec paths.',
            ],
            'do_not' => 'Do not skip the test with `#[SkipOpenApi]`; an undocumented endpoint is the drift contract testing exists to catch.',
        ],
        'GSO102' => [
            'title' => 'Request method is not defined for the path',
            'summary' => 'The path exists in the spec, but it declares no operation for this HTTP method.',
            'causes' => ['The operation is missing from the spec.', 'The test uses the wrong method (for example `PUT` instead of `PATCH`).'],
            'fix' => ['Spec wrong: add the operation under the path item.', 'Test wrong: call the documented method.'],
            'do_not' => 'Do not skip the test; an undocumented operation is real drift.',
        ],
        'GSO103' => [
            'title' => 'Request side of the spec is malformed',
            'summary' => 'A node the request validator needs (an operation, parameter, or request body) is not shaped as OpenAPI requires, so it cannot be enforced.',
            'causes' => ['A parameter is missing `name` or `in`, or has a non-object schema.', 'A `$ref` resolved to the wrong kind of object.'],
            'fix' => ['Run `vendor/bin/gesso doctor` for the exact node and fix the spec.'],
            'do_not' => 'Do not skip the test; a malformed spec node fails every request to this operation.',
        ],
        'GSO104' => [
            'title' => 'Path parameter does not match its schema',
            'summary' => 'A path parameter value does not satisfy the schema the spec declares for it.',
            'causes' => ['The test passes a value of the wrong shape (a slug where the spec says integer).', 'The spec schema is narrower than the application accepts.'],
            'fix' => ['Test wrong: use a valid value.', 'Spec wrong: widen the parameter schema.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO105' => [
            'title' => 'Query parameter is missing or does not match its schema',
            'summary' => 'A required query parameter is absent, or a query value does not satisfy its schema. `keyword` is `required` when the parameter is missing.',
            'causes' => ['The test omits a required parameter.', 'A value has the wrong type or is outside an `enum`.', 'The `style` / `explode` serialization differs from what the client sends.'],
            'fix' => ['Test wrong: send the documented parameter.', 'Spec wrong: fix `required`, the schema, or the serialization fields.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO106' => [
            'title' => 'Header parameter is missing or does not match its schema',
            'summary' => 'A required request header is absent, or its value does not satisfy its schema. `keyword` is `required` when the header is missing.',
            'causes' => ['The test does not send the header.', 'The value has the wrong format.'],
            'fix' => ['Test wrong: send the header.', 'Spec wrong: fix `required` or the schema.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO107' => [
            'title' => 'Security requirement is not satisfied',
            'summary' => 'No security requirement of the operation is satisfied. `keyword` is `required` when credentials are absent and `format` when they are present but unusable.',
            'causes' => ['The test sends no credentials.', 'The credential goes to a different header, query, or cookie than the scheme declares.', 'The operation should be public but inherits root-level `security`.'],
            'fix' => [
                'Test wrong: send credentials (Laravel: `laravel.auto_inject_dummy_credentials`).',
                'Spec wrong: fix the scheme, or set `security: []` on a public operation.',
                'A scheme Gesso cannot check (for example `mutualTLS`): list it in `validation.acknowledged_unvalidatable_schemes`.',
            ],
            'do_not' => 'Do not remove `security` from the spec to make the test pass.',
        ],
        'GSO110' => [
            'title' => 'Request body does not match its schema',
            'summary' => 'The request body violates a schema keyword that has no more specific code. `keyword` names the failing keyword.',
            'causes' => ['The test sends a body the spec does not allow.', 'The spec schema is stricter than the application.'],
            'fix' => ['Use `instance_path` and `keyword` to find the field.', 'Test wrong: send a valid body.', 'Spec wrong: fix the schema.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO111' => [
            'title' => 'Request body is missing a required property',
            'summary' => 'An object in the request body lacks a property listed in `required`.',
            'causes' => ['The test omits a field.', 'The spec marks a field required that the application treats as optional.'],
            'fix' => ['Test wrong: send the field.', 'Spec wrong: remove it from `required`.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO112' => [
            'title' => 'Request body value has the wrong type',
            'summary' => 'A request body value has a JSON type the schema does not allow.',
            'causes' => ['A number is sent as a string, or the reverse.', 'A value is `null` but the schema is not nullable.'],
            'fix' => ['Test wrong: send the documented type.', 'Spec wrong: widen `type` (OpenAPI 3.0: `nullable: true`; 3.1+: `type: [x, "null"]`).'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO113' => [
            'title' => 'Request body has an undeclared property',
            'summary' => 'The request body has a property the schema forbids through `additionalProperties: false` or `unevaluatedProperties: false`.',
            'causes' => ['The client sends a field the spec does not document.', 'A typo in a property name.'],
            'fix' => ['Spec wrong: document the property.', 'Test wrong: stop sending it.'],
            'do_not' => 'Do not set `additionalProperties: true` only to silence this.',
        ],
        'GSO114' => [
            'title' => 'Request body value is not an allowed value',
            'summary' => 'A request body value is not in the schema\'s `enum`, or differs from its `const`.',
            'causes' => ['A new enum value was added in code but not in the spec.', 'Case or spelling differs.'],
            'fix' => ['Spec wrong: add the value to `enum`.', 'Test wrong: send an allowed value.'],
            'do_not' => 'Do not drop the `enum` from the spec only to silence this.',
        ],
        'GSO115' => [
            'title' => 'Request body value does not match its format',
            'summary' => 'A request body string does not satisfy the schema `format` (`date-time`, `uuid`, `email`, ...).',
            'causes' => ['A date is sent without a time zone or in another layout.', 'The spec declares a stricter format than the application uses.'],
            'fix' => ['Test wrong: send a value in the documented format.', 'Spec wrong: change or remove `format`.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO116' => [
            'title' => 'Request body cannot be decoded',
            'summary' => 'The request body could not be read or parsed for its content type (for example invalid JSON), so its schema could not be checked.',
            'causes' => ['The test sends malformed JSON.', 'The `Content-Type` header does not match the body.'],
            'fix' => ['Send a body that parses as its declared content type.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO201' => [
            'title' => 'Response request context is not in the spec',
            'summary' => 'The path or method of the request that produced this response is not in the spec, so the response has no operation to check against.',
            'causes' => ['The endpoint is undocumented.', 'A path prefix is not stripped (`spec.strip_prefixes`).'],
            'fix' => ['Spec wrong: add the path or operation.', 'Prefix mismatch: set `spec.strip_prefixes`.', 'Test wrong: call the documented path.'],
            'do_not' => 'Do not skip the test with `#[SkipOpenApi]`; an undocumented endpoint is real drift.',
        ],
        'GSO202' => [
            'title' => 'Response side of the spec is malformed',
            'summary' => 'A node the response validator needs (`responses`, a response object, or its content) is not shaped as OpenAPI requires.',
            'causes' => ['`responses` is not an object.', 'A `$ref` resolved to the wrong kind of object.'],
            'fix' => ['Run `vendor/bin/gesso doctor` for the exact node and fix the spec.'],
            'do_not' => 'Do not skip the test; a malformed spec node fails every response of this operation.',
        ],
        'GSO203' => [
            'title' => 'Response status is not documented',
            'summary' => 'The operation declares no response for this status code, no matching range (`4XX`), and no `default`.',
            'causes' => ['An error path returns a status the spec does not list (often 401, 403, 404, 422).', 'The application returns the wrong status.'],
            'fix' => ['Spec wrong: document the response.', 'Implementation wrong: return the documented status.'],
            'do_not' => 'Do not list the status in `validation.skip_response_codes` to hide an undocumented response.',
        ],
        'GSO204' => [
            'title' => 'Response header is missing or does not match its schema',
            'summary' => 'A required response header is absent, or its value does not satisfy its schema. `keyword` is `required` when the header is missing.',
            'causes' => ['The application does not set the header on this path.', 'The value has the wrong format.'],
            'fix' => ['Implementation wrong: set the header.', 'Spec wrong: fix `required` or the schema.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO205' => [
            'title' => 'Response media type is not declared',
            'summary' => 'The response arrived as a JSON media type the spec does not declare for this status, so the body was checked against another JSON entry. Read it together with the body failure next to it.',
            'causes' => ['The application returns `application/problem+json` but the spec only declares `application/json`, or the reverse.'],
            'fix' => ['Spec wrong: declare the media type.', 'Implementation wrong: send the documented `Content-Type`.'],
            'do_not' => 'Do not delete the `content` entry to make the body check go away.',
        ],
        'GSO210' => [
            'title' => 'Response body does not match its schema',
            'summary' => 'The response body violates a schema keyword that has no more specific code. `keyword` names the failing keyword.',
            'causes' => ['The application returns a body the spec does not allow.', 'The spec is out of date.'],
            'fix' => ['Use `instance_path` and `keyword` to find the field.', 'Decide which side is wrong, then fix the implementation or the schema.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO211' => [
            'title' => 'Response body is missing a required property',
            'summary' => 'An object in the response body lacks a property listed in `required`.',
            'causes' => ['The serializer omits a field when it is null or empty.', 'The spec marks a field required that is optional in practice.'],
            'fix' => ['Implementation wrong: always serialize the field (as `null` if the schema allows it).', 'Spec wrong: remove it from `required`.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO212' => [
            'title' => 'Response body value has the wrong type',
            'summary' => 'A response body value has a JSON type the schema does not allow.',
            'causes' => [
                'A value is `null` but the schema is not nullable.',
                'A numeric string is returned where the spec says number, or the reverse.',
                'An empty PHP array serializes as `[]` where the schema expects an object.',
            ],
            'fix' => [
                'Implementation wrong: cast the value.',
                'Spec wrong: widen `type` (OpenAPI 3.0: `nullable: true`; 3.1+: `type: [x, "null"]`).',
            ],
            'do_not' => self::NO_SKIP,
        ],
        'GSO213' => [
            'title' => 'Response body has an undeclared property',
            'summary' => 'The response body has a property the schema forbids through `additionalProperties: false` or `unevaluatedProperties: false`.',
            'causes' => ['The application returns a field the spec does not document yet.', 'A resource leaks an internal field.'],
            'fix' => ['Spec wrong: document the property.', 'Implementation wrong: stop returning it.'],
            'do_not' => 'Do not set `additionalProperties: true` only to silence this.',
        ],
        'GSO214' => [
            'title' => 'Response body value is not an allowed value',
            'summary' => 'A response body value is not in the schema\'s `enum`, or differs from its `const`.',
            'causes' => ['A PHP enum gained a case the spec does not list (see enum drift).'],
            'fix' => ['Spec wrong: add the value to `enum`.', 'Implementation wrong: return an allowed value.'],
            'do_not' => 'Do not drop the `enum` from the spec only to silence this.',
        ],
        'GSO215' => [
            'title' => 'Response body value does not match its format',
            'summary' => 'A response body string does not satisfy the schema `format` (`date-time`, `uuid`, `email`, ...).',
            'causes' => ['A date is serialized without a time zone or in another layout.'],
            'fix' => ['Implementation wrong: serialize in the documented format.', 'Spec wrong: change or remove `format`.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO216' => [
            'title' => 'Response body cannot be decoded',
            'summary' => 'The response body could not be read or parsed for its content type (for example invalid JSON), so its schema could not be checked.',
            'causes' => ['The application emits debug output or a PHP warning before the JSON.', 'The `Content-Type` header does not match the body.'],
            'fix' => ['Implementation wrong: return a body that parses as its declared content type.'],
            'do_not' => self::NO_SKIP,
        ],
        'GSO301' => [
            'title' => 'Spec file cannot be read',
            'summary' => 'The spec path does not name a readable file.',
            'causes' => ['A wrong path or `spec.base_path`.', 'File permissions.'],
            'fix' => ['Check the path and the permissions.'],
            'do_not' => 'Do not remove the spec from `spec.names` to make doctor pass.',
        ],
        'GSO302' => [
            'title' => 'Spec cannot be parsed',
            'summary' => 'The spec is not valid JSON or YAML, has an unsupported extension, or its root is not a mapping.',
            'causes' => ['A syntax error.', 'A YAML spec without `symfony/yaml` installed.'],
            'fix' => ['Fix the syntax.', 'For YAML: `composer require --dev symfony/yaml`.'],
            'do_not' => 'Do not convert the spec by hand to dodge the parser; fix the source.',
        ],
        'GSO303' => [
            'title' => 'OpenAPI version is missing or unsupported',
            'summary' => 'The `openapi` field is missing, or names a version Gesso does not support (3.0, 3.1, and 3.2 are supported).',
            'causes' => ['A Swagger 2.0 document.', 'A typo in the `openapi` field.'],
            'fix' => ['Convert Swagger 2.0 to OpenAPI 3.x.', 'Set `openapi` to the version the document follows.'],
            'do_not' => 'Do not change the version number without converting the document.',
        ],
        'GSO304' => [
            'title' => 'JSON Schema dialect is unsupported',
            'summary' => '`jsonSchemaDialect` or a schema `$schema` names a dialect Gesso cannot enforce.',
            'causes' => ['A custom or draft dialect URI.'],
            'fix' => ['Use the OpenAPI default dialect for the document version.'],
            'do_not' => 'Do not remove the dialect declaration if the schemas really depend on it.',
        ],
        'GSO305' => [
            'title' => 'Reference cannot be resolved',
            'summary' => 'A `$ref` points to a missing target, a file outside the allowed root, a remote document that is not allowed, or forms a cycle Gesso cannot follow.',
            'causes' => ['A typo in the pointer.', 'A remote `$ref` without `--allow-remote-refs`.', 'A local file outside `--local-ref-root`.'],
            'fix' => ['Fix the pointer.', 'Bundle the spec, or allow the exact remote host or local root.'],
            'do_not' => 'Do not widen the allowed reference root beyond the directory you trust.',
        ],
        'GSO306' => [
            'title' => 'Spec structure is invalid',
            'summary' => 'A node has the wrong shape for OpenAPI (for example `paths` is not an object, or a discriminator is inconsistent). Runtime validation fails on the same node.',
            'causes' => ['A hand edit broke a node.', 'A generator emitted an invalid node.'],
            'fix' => ['Fix the node the message names.'],
            'do_not' => 'Do not disable discriminator enforcement only to make doctor pass.',
        ],
        'GSO307' => [
            'title' => 'Schema keyword or format is not enforced',
            'summary' => 'The spec uses a keyword or format Gesso does not enforce, so values that violate it pass.',
            'causes' => ['A vendor format, or a keyword outside the selected dialect.'],
            'fix' => ['Accept the gap knowingly, or express the rule with a keyword Gesso enforces (`pattern`, `enum`, ...).'],
            'do_not' => 'Do not assume the constraint is tested.',
        ],
        'GSO308' => [
            'title' => 'Spec feature is not validated',
            'summary' => 'Doctor reports a feature Gesso skips (for example webhooks or an unvalidatable security scheme). This is reported as `skipped`, not as an error.',
            'causes' => ['The spec uses a feature outside what runtime validation checks.'],
            'fix' => ['See the supported-features page. For security schemes, acknowledge them in `validation.acknowledged_unvalidatable_schemes`.'],
            'do_not' => 'Do not read a clean doctor run as proof that skipped features are tested.',
        ],
        'GSO309' => [
            'title' => 'Optional dependency is missing',
            'summary' => 'An option needs a package that is not installed.',
            'causes' => ['`--allow-remote-refs` without a PSR-18 HTTP client.'],
            'fix' => ['Install the package the message names.'],
            'do_not' => 'Do not drop the option if the spec needs it; bundle the spec instead.',
        ],
        'GSO310' => [
            'title' => 'Configuration is inconsistent with the spec',
            'summary' => '`gesso.php` or the doctor options name a spec, prefix, or setting the runtime would reject or never use.',
            'causes' => ['A `spec.names` entry that leaves `spec.base_path`.', 'A stale `spec.strip_prefixes` or `spec.default`.'],
            'fix' => ['Fix the configuration key the message names.'],
            'do_not' => 'Do not delete the key without checking what it was for.',
        ],
        'GSO311' => [
            'title' => 'Spec is invalid',
            'summary' => 'The loader rejected the spec for a reason that has no more specific code.',
            'causes' => ['See the message.'],
            'fix' => ['Fix the spec as the message describes.'],
            'do_not' => 'Do not skip the spec; runtime validation rejects it the same way.',
        ],
        'GSO312' => [
            'title' => 'Spec uses a construct with limited support',
            'summary' => 'The spec loads, but uses a construct Gesso handles with a documented limitation.',
            'causes' => ['A construct listed under known limitations.'],
            'fix' => ['Read the warning and the supported-features page.'],
            'do_not' => 'Do not ignore the warning without reading the limitation.',
        ],
        'GSO313' => [
            'title' => 'Doctor failed internally',
            'summary' => 'Doctor hit an unexpected error while analysing the spec.',
            'causes' => ['A bug in Gesso.'],
            'fix' => ['Report it with the spec and the stack trace at <https://github.com/studio-design/gesso/issues>.'],
            'do_not' => 'Do not assume the spec is fine; the check did not complete.',
        ],
        'GSO401' => [
            'title' => 'Changed operation is not covered',
            'summary' => '`coverage:gate` found a response whose operation changed against the base spec, and no test validated it in this run.',
            'causes' => ['The pull request changed an operation and added no test for it.', 'The coverage document is from another run or another spec (`--spec-name`).'],
            'fix' => ['Add a test that validates the listed response.', 'Check that `--coverage` comes from this run.'],
            'do_not' => 'Do not exclude the operation or point `--base-spec` at the changed spec to pass the gate.',
        ],
        'GSO402' => [
            'title' => 'Coverage is below the threshold',
            'summary' => 'Endpoint, response, or SDK exercise coverage is below the configured `coverage.min_coverage` threshold.',
            'causes' => ['New operations without tests.', 'A partial test run.'],
            'fix' => ['Add tests for the uncovered responses the report lists.', 'Run the full suite before gating.'],
            'do_not' => 'Do not lower the threshold to the current number; consider a coverage baseline, which fails on new gaps by name.',
        ],
        'GSO403' => [
            'title' => 'Response is uncovered and not in the coverage baseline',
            'summary' => 'A declared response was not validated in this run and is not listed in the coverage baseline file.',
            'causes' => ['A new response in the spec without a test.', 'A test that used to cover it was removed or skipped.'],
            'fix' => ['Add a test that validates the response.'],
            'do_not' => 'Do not regenerate the coverage baseline (`GESSO_BASELINE_GENERATE=1`) only to accept the gap.',
        ],
        'GSO404' => [
            'title' => 'Coverage baseline entry is covered now',
            'summary' => 'A response listed in the coverage baseline was validated in this run, so the entry is stale.',
            'causes' => ['A test now covers the response.'],
            'fix' => ['Remove the entry, or regenerate the baseline with `GESSO_BASELINE_GENERATE=1`.'],
            'do_not' => 'Do not set `baseline_stale.coverage` to `off` to hide stale entries.',
        ],
        'GSO405' => [
            'title' => 'Parallel run is incomplete',
            'summary' => 'A worker failed to write its coverage sidecar, or the number of sidecars differs from `--expect-sidecars`, so the merged report would be wrong.',
            'causes' => ['A worker crashed or was cancelled.', 'Sidecars from another run are in the directory.', 'A wrong `--expect-sidecars` count.'],
            'fix' => ['Re-run the failed shard.', 'Use `GESSO_RUN_ID` to keep runs apart.', 'Correct `--expect-sidecars`.'],
            'do_not' => 'Do not delete the failure markers or drop `--expect-sidecars` to make the merge pass.',
        ],
        'GSO406' => [
            'title' => 'No coverage was recorded',
            'summary' => 'A coverage threshold or the coverage baseline is configured, but the run validated no response, so the gate cannot be evaluated.',
            'causes' => ['The PHPUnit extension is not registered.', 'The workers wrote no sidecars, or the merge reads the wrong directory.', 'No test calls an assertion.'],
            'fix' => ['Register the extension and check `coverage.sidecar_dir`.'],
            'do_not' => 'Do not turn off `coverage.min_coverage.strict` to make this pass.',
        ],
        'GSO501' => [
            'title' => 'Always-present response field is not required',
            'summary' => 'Strict-required detection saw a field in every observed response, but the schema does not list it in `required`.',
            'causes' => ['The spec under-declares `required`.'],
            'fix' => ['Spec wrong: add the field to `required`.', 'If the field is really optional, add a test whose response omits it.'],
            'do_not' => 'Do not switch `strict.required.run` to `off` to silence the report.',
        ],
        'GSO502' => [
            'title' => 'Undocumented response property was observed',
            'summary' => 'Strict additional-properties detection saw a response property the schema does not declare.',
            'causes' => ['The application returns fields the spec does not document.'],
            'fix' => ['Spec wrong: document the property.', 'Implementation wrong: stop returning it.'],
            'do_not' => 'Do not switch `strict.additional_properties.run` to `off` to silence the report.',
        ],
    ];

    private function __construct() {}

    /** The code for a validator issue; a keyword without its own code falls back to the category's. */
    public static function forIssue(string $category, ?string $keyword): ?string
    {
        $codes = self::ISSUE_CODES[$category] ?? null;
        if ($codes === null) {
            return null;
        }

        return $codes[$keyword ?? ''] ?? $codes[''];
    }

    public static function forDoctor(string $category): ?string
    {
        return self::DOCTOR_CODES[$category] ?? null;
    }

    /** @return null|FailureCode */
    public static function find(string $code): ?array
    {
        return self::CODES[strtoupper($code)] ?? null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CODES);
    }

    public static function docsUrl(string $code): string
    {
        return self::DOCS_URL . '#' . strtolower($code);
    }

    /** How a code is shown in human-readable output: `[GSO212]`. */
    public static function tag(string $code): string
    {
        return '[' . $code . ']';
    }

    /** What emits a code, for `gesso explain` and the generated page. */
    public static function source(string $code): string
    {
        $issue = [];
        foreach (self::ISSUE_CODES as $category => $keywords) {
            foreach ($keywords as $keyword => $candidate) {
                if ($candidate === $code) {
                    $issue[] = $keyword === '' ? "`{$category}`" : "`{$category}` with keyword `{$keyword}`";
                }
            }
        }
        if ($issue !== []) {
            return 'Validator issue category ' . implode(' or ', $issue) . '.';
        }
        $doctor = array_keys(self::DOCTOR_CODES, $code, true);
        if ($doctor !== []) {
            return "`gesso doctor` issue category `{$doctor[0]}`.";
        }

        return 'Emitted by ' . (self::SOURCES[$code] ?? 'Gesso') . '.';
    }

    /**
     * The `docs/failure-codes.md` page. A test fails when the committed page drifts from this.
     */
    public static function markdown(): string
    {
        $lines = [
            '# Failure codes',
            '',
            '<!-- Generated from src/Internal/FailureCatalogue.php by `php scripts/generate-failure-codes-doc.php`. Do not edit by hand. -->',
            '',
            'Validator issues, `gesso doctor` diagnostics, and the coverage and strict gate verdicts',
            'carry a stable failure code. Text output shows it as `[GSO212]`;',
            'JSON output has `code` and `docs_url` fields. `vendor/bin/gesso explain GSO212` prints',
            'the entry below in the terminal, and `--format=json` prints it for tools and agents.',
            '',
            'Codes are a compatibility surface ([versioning](versioning.md)). A code keeps its',
            'meaning for the whole major line and is never reused; new codes may appear in minor',
            'releases. Ranges group the codes: `GSO0xx` general, `GSO1xx` request validation,',
            '`GSO2xx` response validation, `GSO3xx` doctor, `GSO4xx` coverage gates and merge,',
            '`GSO5xx` strict contract gates. `GSO6xx`-`GSO9xx` are reserved.',
            '',
            'Usage errors (exit code `2`) have no code: the message and the usage text say what to fix.',
        ];

        $byRange = [];
        foreach (self::CODES as $code => $entry) {
            $byRange[(int) $code[3]][$code] = $entry;
        }
        ksort($byRange);

        foreach ($byRange as $range => $entries) {
            $lines[] = '';
            $lines[] = '## ' . self::RANGES[$range];
            foreach ($entries as $code => $entry) {
                $lines[] = '';
                $lines[] = '### ' . $code;
                $lines[] = '';
                $lines[] = "**{$entry['title']}.** {$entry['summary']}";
                $lines[] = '';
                $lines[] = self::source($code);
                $lines[] = '';
                $lines[] = 'Common causes:';
                $lines[] = '';
                foreach ($entry['causes'] as $cause) {
                    $lines[] = '- ' . $cause;
                }
                $lines[] = '';
                $lines[] = 'How to fix:';
                $lines[] = '';
                foreach ($entry['fix'] as $fix) {
                    $lines[] = '- ' . $fix;
                }
                $lines[] = '';
                $lines[] = $entry['do_not'];
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Codes close to an unknown one, for `gesso explain`'s exit-2 message.
     *
     * @return list<string>
     */
    public static function suggest(string $code): array
    {
        $code = strtoupper($code);
        if (!str_starts_with($code, 'GSO')) {
            $code = 'GSO' . $code;
        }

        $distances = [];
        foreach (self::codes() as $candidate) {
            $distance = levenshtein($candidate, $code);
            if ($distance <= 2) {
                $distances[$candidate] = $distance;
            }
        }
        asort($distances);

        return array_slice(array_keys($distances), 0, 3);
    }
}
