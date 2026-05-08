<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Request\SecuritySchemeIntrospector;
use Studio\OpenApiContractTesting\Validation\Request\SecurityValidator;

/**
 * Covers the spec-side "does this endpoint accept a bearer credential?" probe
 * used by the auto-inject-dummy-bearer path in the Laravel
 * `ValidatesOpenApiSchema` trait. The rules mirror
 * {@see SecurityValidator::classifyScheme()} exactly — any discrepancy would
 * cause the trait to inject a token that the validator then considers out of
 * spec.
 */
class SecuritySchemeIntrospectorTest extends TestCase
{
    private SecuritySchemeIntrospector $introspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->introspector = new SecuritySchemeIntrospector();
    }

    #[Test]
    public function detects_bearer_on_operation_with_bearer_auth_requirement(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['bearerAuth' => []]]];

        $this->assertTrue($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function accepts_uppercase_bearer_scheme_value(): void
    {
        // RFC 7235 makes the HTTP auth scheme name case-insensitive. The
        // introspector must mirror SecurityValidator's `strtolower()` handling
        // so a spec author writing "Bearer" (capitalized) does not quietly
        // disable the auto-inject path for their bearer endpoints.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'Bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['bearerAuth' => []]]];

        $this->assertTrue($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_for_apikey_only_endpoint(): void
    {
        // apiKey schemes are intentionally NOT auto-injectable — the header
        // name (e.g. "X-API-Key") is arbitrary per spec and we will not guess
        // a reasonable dummy value for it.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'apiKeyHeader' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                    ],
                ],
            ],
        ];
        $operation = ['security' => [['apiKeyHeader' => []]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_for_oauth2_only_endpoint(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'oauth2Flow' => [
                        'type' => 'oauth2',
                        'flows' => ['implicit' => ['authorizationUrl' => 'https://example.com/oauth', 'scopes' => []]],
                    ],
                ],
            ],
        ];
        $operation = ['security' => [['oauth2Flow' => ['read']]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_when_security_is_empty_array_opt_out(): void
    {
        // Explicit `security: []` opts the operation out of authentication.
        // No injection needed even if a root-level bearer requirement exists.
        $spec = [
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => []];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_when_neither_operation_nor_root_security_defined(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = [];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function inherits_root_level_security_when_operation_silent(): void
    {
        // OpenAPI 3.x: operations without a `security` key inherit the root
        // array. The trait relies on this to auto-inject on endpoints whose
        // spec authors only declared bearer once at the top.
        $spec = [
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = [];

        $this->assertTrue($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function operation_level_security_overrides_root(): void
    {
        // Root says bearer, operation says apiKey. OpenAPI override semantics
        // mean the operation wins — no bearer involved, no inject.
        $spec = [
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'apiKeyHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
        ];
        $operation = ['security' => [['apiKeyHeader' => []]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function detects_bearer_in_or_requirement_with_unsupported_peer(): void
    {
        // Mirrors petstore-3.0 fixture /v1/secure/bearer-or-oauth2. Bearer is
        // one OR-alternative alongside oauth2. Injecting bearer lets the
        // bearer branch succeed; the oauth2 branch stays unsupported.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'oauth2Flow' => ['type' => 'oauth2', 'flows' => ['implicit' => []]],
                ],
            ],
        ];
        $operation = [
            'security' => [
                ['bearerAuth' => []],
                ['oauth2Flow' => ['read']],
            ],
        ];

        $this->assertTrue($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function detects_bearer_in_and_requirement_mixed_with_apikey(): void
    {
        // AND-entry: bearer + apiKey. Injecting bearer alone won't satisfy
        // validation but it WILL remove the "Authorization header is missing"
        // noise so the user sees only the apiKey error — the actionable one.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'apiKeyHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
        ];
        $operation = ['security' => [['bearerAuth' => [], 'apiKeyHeader' => []]]];

        $this->assertTrue($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_when_http_scheme_is_basic_not_bearer(): void
    {
        // http+basic / http+digest are the other validatable http schemes.
        // Phase 1 treats them as Unsupported in SecurityValidator and we
        // likewise refuse to auto-inject anything for them.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'basicAuth' => ['type' => 'http', 'scheme' => 'basic'],
                ],
            ],
        ];
        $operation = ['security' => [['basicAuth' => []]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_when_scheme_reference_is_undefined(): void
    {
        // Broken spec (dangling reference). The introspector must not crash;
        // SecurityValidator will surface the hard error during validation.
        $spec = ['components' => ['securitySchemes' => []]];
        $operation = ['security' => [['bearerAuth' => []]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_when_components_security_schemes_is_non_array(): void
    {
        // Spec is malformed (scalar securitySchemes). We refuse to inject
        // rather than risk masking a real spec bug — the validator will
        // surface this as a hard error on its own.
        $spec = ['components' => ['securitySchemes' => 'not-an-array']];
        $operation = ['security' => [['bearerAuth' => []]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_when_security_is_non_array_malformed(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => 'not-an-array'];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function ignores_non_string_scheme_name_keys(): void
    {
        // Scheme names must be strings; a numeric/boolean-ish key would be
        // malformed spec. Skip and continue — do not match bearer off it.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [[0 => []]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function returns_false_when_http_scheme_field_is_missing(): void
    {
        // Malformed http scheme entry (no `scheme` field). SecurityValidator
        // would classify this as Malformed and raise a hard error — the
        // introspector stays quiet and lets the validator handle it.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http'],
                ],
            ],
        ];
        $operation = ['security' => [['bearerAuth' => []]]];

        $this->assertFalse($this->introspector->endpointAcceptsBearer($spec, $operation));
    }

    #[Test]
    public function injectable_credentials_for_returns_bearer_only_for_bearer_endpoint(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['bearerAuth' => []]]];

        $this->assertSame(
            [['kind' => 'bearer']],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_returns_apikey_header_entry(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'apiKeyHeader' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'X-API-Key',
                    ],
                ],
            ],
        ];
        $operation = ['security' => [['apiKeyHeader' => []]]];

        $this->assertSame(
            [['kind' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key']],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_returns_apikey_cookie_entry(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'CookieAuth' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'front_session',
                    ],
                ],
            ],
        ];
        $operation = ['security' => [['CookieAuth' => []]]];

        $this->assertSame(
            [['kind' => 'apiKey', 'in' => 'cookie', 'name' => 'front_session']],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_returns_apikey_query_entry(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'apiKeyQuery' => [
                        'type' => 'apiKey',
                        'in' => 'query',
                        'name' => 'api_key',
                    ],
                ],
            ],
        ];
        $operation = ['security' => [['apiKeyQuery' => []]]];

        $this->assertSame(
            [['kind' => 'apiKey', 'in' => 'query', 'name' => 'api_key']],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_returns_bearer_and_apikey_for_and_entry(): void
    {
        // AND-entry: both schemes appear in a single requirement object. The
        // trait must inject both so the validator sees a satisfied entry.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'apiKeyHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
        ];
        $operation = ['security' => [['bearerAuth' => [], 'apiKeyHeader' => []]]];

        $this->assertSame(
            [
                ['kind' => 'bearer'],
                ['kind' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_returns_bearer_and_apikey_for_or_entries(): void
    {
        // OR-entries: separate requirement objects. Either branch satisfied is
        // enough at validation time, but inject populates both — over-injection
        // is harmless because the spec already accepts either.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'apiKeyHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
        ];
        $operation = [
            'security' => [
                ['bearerAuth' => []],
                ['apiKeyHeader' => []],
            ],
        ];

        $this->assertSame(
            [
                ['kind' => 'bearer'],
                ['kind' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_skips_oauth2_openidconnect_mutualtls_and_http_basic(): void
    {
        // SecurityValidator silent-passes these schemes (Unsupported), so
        // injecting a dummy value would be a lie. Skip them entirely.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'oauth2Flow' => ['type' => 'oauth2', 'flows' => ['implicit' => []]],
                    'oidc' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://example.com/.well-known'],
                    'mtls' => ['type' => 'mutualTLS'],
                    'basicAuth' => ['type' => 'http', 'scheme' => 'basic'],
                ],
            ],
        ];
        $operation = [
            'security' => [
                ['oauth2Flow' => ['read']],
                ['oidc' => []],
                ['mtls' => []],
                ['basicAuth' => []],
            ],
        ];

        $this->assertSame([], $this->introspector->injectableCredentialsFor($spec, $operation));
    }

    #[Test]
    public function injectable_credentials_for_skips_undefined_scheme_reference(): void
    {
        // Dangling reference. Must not crash; SecurityValidator surfaces the
        // hard error during validation.
        $spec = ['components' => ['securitySchemes' => []]];
        $operation = ['security' => [['ghostAuth' => []]]];

        $this->assertSame([], $this->introspector->injectableCredentialsFor($spec, $operation));
    }

    #[Test]
    public function injectable_credentials_for_dedups_duplicate_definitions_across_entries(): void
    {
        // Pathological spec where the same scheme appears in multiple OR
        // entries. We must not double-inject the same Authorization or the
        // same cookie name — once is enough.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'apiKeyHeader' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
                ],
            ],
        ];
        $operation = [
            'security' => [
                ['bearerAuth' => [], 'apiKeyHeader' => []],
                ['bearerAuth' => []],
                ['apiKeyHeader' => []],
            ],
        ];

        $this->assertSame(
            [
                ['kind' => 'bearer'],
                ['kind' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key'],
            ],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_inherits_root_security_when_operation_silent(): void
    {
        $spec = [
            'security' => [['CookieAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'CookieAuth' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'front_session',
                    ],
                ],
            ],
        ];
        $operation = [];

        $this->assertSame(
            [['kind' => 'apiKey', 'in' => 'cookie', 'name' => 'front_session']],
            $this->introspector->injectableCredentialsFor($spec, $operation),
        );
    }

    #[Test]
    public function injectable_credentials_for_returns_empty_for_explicit_security_optout(): void
    {
        // `security: []` is the explicit "no auth required" override. Inject
        // nothing; the validator will not complain either.
        $spec = [
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => []];

        $this->assertSame([], $this->introspector->injectableCredentialsFor($spec, $operation));
    }

    #[Test]
    public function injectable_credentials_for_handles_malformed_security_array(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => 'not-an-array'];

        $this->assertSame([], $this->introspector->injectableCredentialsFor($spec, $operation));
    }

    #[Test]
    public function injectable_credentials_for_skips_malformed_apikey_definition(): void
    {
        // apiKey scheme missing `in` field — Malformed. SecurityValidator
        // surfaces a hard error; the introspector skips silently.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'brokenApiKey' => ['type' => 'apiKey', 'name' => 'X-API-Key'],
                ],
            ],
        ];
        $operation = ['security' => [['brokenApiKey' => []]]];

        $this->assertSame([], $this->introspector->injectableCredentialsFor($spec, $operation));
    }
}
