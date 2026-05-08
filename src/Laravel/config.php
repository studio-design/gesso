<?php

declare(strict_types=1);

use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;

return [
    'default_spec' => '',

    // Maximum number of validation errors to report per response.
    // 0 = unlimited (reports all errors).
    'max_errors' => 20,

    // When true, every TestResponse produced by Laravel HTTP test helpers
    // (get(), post(), etc.) is validated against the OpenAPI spec at creation
    // time, without requiring an explicit assertResponseMatchesOpenApiSchema()
    // call in each test. Defaults to false for backward compatibility.
    'auto_assert' => false,

    // When true, every Request dispatched by Laravel HTTP test helpers is
    // validated against the OpenAPI spec (path / query / headers / body /
    // security) alongside the response. Independent of `auto_assert` — either
    // side can be enabled on its own. Defaults to false for backward
    // compatibility.
    'auto_validate_request' => false,

    // When true (and `auto_validate_request` is on), endpoints whose spec
    // security declares any inject-eligible scheme (http+bearer, apiKey in
    // header / cookie / query) automatically receive a fixed dummy value in
    // the validator's view when the test did not set one. The Symfony Request
    // itself is not modified — this only prevents the security check from
    // false-failing on tests that authenticate via actingAs() or middleware
    // bypass. oauth2 / openIdConnect / mutualTLS / http-basic are
    // silent-passed by the validator and therefore not auto-injected.
    // Defaults to false for backward compatibility.
    'auto_inject_dummy_credentials' => false,

    // Bearer-only predecessor of `auto_inject_dummy_credentials`, kept for
    // existing consumers. Same gating (auto_validate_request must also be
    // on) and same view-only injection, but limited to endpoints whose spec
    // security requires `http` + `bearer`. Bypassed when the superset key
    // above is true.
    'auto_inject_dummy_bearer' => false,

    // Regex patterns (without delimiters or anchors) matched against the
    // response status code. Matching codes short-circuit body validation and
    // return a "skipped" result — the test is not failed, and the endpoint is
    // still recorded as covered. The default skips every 5xx because specs
    // typically do not document production error responses.
    // Set to [] to disable and validate every status code against the spec.
    'skip_response_codes' => OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,

    // Regex patterns matched against the response status code that the
    // current HTTP test produced. When `auto_validate_request: true` is on
    // and the response status matches one of these patterns AND the spec
    // documents that status for the operation, a request-validation failure
    // is downgraded to "skipped" instead of failing the test. This rescues
    // the dataProvider-driven "send invalid input → assert 422" pattern from
    // false-failing under request validation while keeping spec gaps loud
    // (an undocumented 4xx still fails — it's a real contract drift).
    // Default `['422', '400']` aligns with the common documented client-error
    // codes; set to [] to disable and keep request validation strict.
    'skip_request_validation_response_codes' => OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES,
];
