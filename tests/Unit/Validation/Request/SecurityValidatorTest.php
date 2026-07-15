<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Request;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Request\SecurityValidator;

use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;

class SecurityValidatorTest extends TestCase
{
    private SecurityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SecurityValidator();
        // Reset the per-process "warned-once" set so tests are deterministic
        // regardless of run order or filter selection. Same convention as
        // OpenApiSchemaConverterTest.
        SecurityValidator::resetWarningStateForTesting();
    }

    protected function tearDown(): void
    {
        SecurityValidator::resetWarningStateForTesting();
        parent::tearDown();
    }

    #[Test]
    public function validate_returns_empty_when_no_security_defined(): void
    {
        $errors = $this->validator->validate('GET', '/pets', [], [], [], [], []);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_passes_bearer_auth_with_valid_header(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['BearerAuth' => []]]];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $spec,
            $operation,
            ['Authorization' => 'Bearer xyz.token'],
            [],
            [],
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_missing_bearer_header(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['BearerAuth' => []]]];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Authorization header is missing', $errors[0]);
    }

    #[Test]
    public function validate_surfaces_hard_error_for_undefined_scheme(): void
    {
        $spec = ['components' => ['securitySchemes' => []]];
        $operation = ['security' => [['MissingScheme' => []]]];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("references undefined scheme 'MissingScheme'", $errors[0]);
    }

    #[Test]
    public function validate_surfaces_hard_error_for_malformed_scheme_type(): void
    {
        // A typo like `type: htpp` must be surfaced as a hard error — otherwise
        // the library would silently pass every request for the endpoint.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Oops' => ['type' => 'htpp'],
                ],
            ],
        ];
        $operation = ['security' => [['Oops' => []]]];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is malformed', $errors[0]);
        $this->assertStringContainsString("unknown type 'htpp'", $errors[0]);
    }

    #[Test]
    public function validate_passes_api_key_in_header(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
            ],
        ];
        $operation = ['security' => [['ApiKey' => []]]];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $spec,
            $operation,
            ['X-Api-Key' => 'secret'],
            [],
            [],
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_treats_empty_security_as_opt_out(): void
    {
        $spec = [
            'security' => [['BearerAuth' => []]],
            'components' => [
                'securitySchemes' => ['BearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
            ],
        ];
        // Operation explicitly opts out with `security: []`.
        $operation = ['security' => []];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_rejects_associative_security_container(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => ['BearerAuth' => []]];

        $errors = $this->validator->validate('GET', '/pets', $spec, $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('must be a list of requirement objects', $errors[0]);
    }

    #[Test]
    public function validate_accepts_empty_requirement_object_for_anonymous_access(): void
    {
        $operation = ['security' => [[]]];

        $errors = $this->validator->validate('GET', '/pets', [], $operation, [], [], []);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_rejects_list_shaped_security_requirement(): void
    {
        $operation = ['security' => [[['BearerAuth' => []]]]];

        $errors = $this->validator->validate('GET', '/pets', [], $operation, [], [], []);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('security scheme name must be a string', $errors[0]);
    }

    #[Test]
    public function validate_rejects_non_list_scopes(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = ['security' => [['BearerAuth' => 'not-a-list']]];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $spec,
            $operation,
            ['Authorization' => 'Bearer token'],
            [],
            [],
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("requirement 'BearerAuth' scopes must be a list of strings", $errors[0]);
    }

    #[Test]
    public function validate_rejects_associative_scopes(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => ['scope' => 'read']]]];

        [$errors, $warnings] = $this->validateCapturingWarnings('GET', '/pets', $spec, $operation);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("requirement 'OAuth' scopes must be a list of strings", $errors[0]);
        $this->assertSame([], $warnings);
    }

    #[Test]
    public function validate_rejects_non_string_scope_values(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => ['read', 123]]]];

        [$errors, $warnings] = $this->validateCapturingWarnings('GET', '/pets', $spec, $operation);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("requirement 'OAuth' scope at index 1 must be a string", $errors[0]);
        $this->assertSame([], $warnings);
    }

    // ========================================
    // Loud-warning behaviour for unsupported security schemes.
    // ========================================

    #[Test]
    public function oauth2_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => ['read']]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'POST',
            '/v1/users',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors, 'silent-pass behaviour preserved');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('[security] OAuth2 scheme', $warnings[0]);
        $this->assertStringContainsString("'OAuth'", $warnings[0]);
        $this->assertStringContainsString('POST /v1/users', $warnings[0]);
    }

    #[Test]
    public function openid_connect_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OIDC' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://idp.example/.well-known/openid-configuration'],
                ],
            ],
        ];
        $operation = ['security' => [['OIDC' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/me',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('OpenID Connect scheme', $warnings[0]);
        $this->assertStringContainsString("'OIDC'", $warnings[0]);
    }

    #[Test]
    public function mutual_tls_emits_loud_warning_on_first_encounter(): void
    {
        // mutualTLS is OAS 3.1 only.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'mTLS' => ['type' => 'mutualTLS'],
                ],
            ],
        ];
        $operation = ['security' => [['mTLS' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'POST',
            '/v1/internal/sync',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Mutual TLS scheme', $warnings[0]);
        $this->assertStringContainsString("'mTLS'", $warnings[0]);
    }

    #[Test]
    public function http_basic_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Basic' => ['type' => 'http', 'scheme' => 'basic'],
                ],
            ],
        ];
        $operation = ['security' => [['Basic' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/admin',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('http-basic scheme', $warnings[0]);
        $this->assertStringContainsString("'Basic'", $warnings[0]);
    }

    #[Test]
    public function http_digest_emits_loud_warning_on_first_encounter(): void
    {
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Digest' => ['type' => 'http', 'scheme' => 'digest'],
                ],
            ],
        ];
        $operation = ['security' => [['Digest' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/legacy',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('http-digest scheme', $warnings[0]);
        $this->assertStringContainsString("'Digest'", $warnings[0]);
    }

    #[Test]
    public function repeated_calls_with_same_scheme_name_warn_only_once(): void
    {
        // Avoid log spam: warn once per process per scheme name. Three calls
        // referencing the same scheme name must surface exactly one warning.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => []]]];

        $count = 0;
        set_error_handler(static function (int $errno, string $errstr) use (&$count): bool {
            if ($errno === E_USER_WARNING && str_starts_with($errstr, '[security]')) {
                $count++;

                return true;
            }

            return false;
        });

        try {
            $this->validator->validate('GET', '/v1/a', [...$spec], $operation, [], [], []);
            $this->validator->validate('GET', '/v1/b', [...$spec], $operation, [], [], []);
            $this->validator->validate('GET', '/v1/c', [...$spec], $operation, [], [], []);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(1, $count);
    }

    #[Test]
    public function two_distinct_oauth2_scheme_names_each_warn_once(): void
    {
        // Dedup is per scheme name (the components.securitySchemes key), not
        // per type. Two oauth2 definitions named differently both warn.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'oauth2_user' => ['type' => 'oauth2', 'flows' => []],
                    'oauth2_admin' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING && str_starts_with($errstr, '[security]')) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $this->validator->validate(
                'GET',
                '/v1/a',
                $spec,
                ['security' => [['oauth2_user' => []]]],
                [],
                [],
                [],
            );
            $this->validator->validate(
                'GET',
                '/v1/b',
                $spec,
                ['security' => [['oauth2_admin' => []]]],
                [],
                [],
                [],
            );
        } finally {
            restore_error_handler();
        }

        $this->assertCount(2, $captured);
        $this->assertStringContainsString("'oauth2_user'", $captured[0]);
        $this->assertStringContainsString("'oauth2_admin'", $captured[1]);
    }

    #[Test]
    public function bearer_and_api_key_do_not_warn(): void
    {
        // Regression guard: supported schemes must not trigger the silent-pass
        // warning. Two operations exercise both validatable scheme types.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                    'ApiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
            ],
        ];

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING && str_starts_with($errstr, '[security]')) {
                $captured = $errstr;

                return true;
            }

            return false;
        });

        try {
            $this->validator->validate(
                'GET',
                '/v1/x',
                $spec,
                ['security' => [['BearerAuth' => []]]],
                ['Authorization' => 'Bearer t'],
                [],
                [],
            );
            $this->validator->validate(
                'GET',
                '/v1/y',
                $spec,
                ['security' => [['ApiKey' => []]]],
                ['X-Api-Key' => 'k'],
                [],
                [],
            );
        } finally {
            restore_error_handler();
        }

        $this->assertNull($captured, 'supported schemes (bearer, apiKey) must not warn');
    }

    #[Test]
    public function warning_does_not_change_validation_outcome(): void
    {
        // OAuth2 is silently passed (false-negative avoidance) — the warning
        // is purely a side-channel. The return value must remain `[]` even
        // when the warning fires, otherwise the warning emission would
        // accidentally regress the false-negative-avoidance behaviour.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => ['read']]]];

        [$errors] = $this->validateCapturingWarnings('GET', '/pets', $spec, $operation);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function oauth2_inside_and_entry_with_supported_scheme_still_warns(): void
    {
        // An AND-entry mixing an unsupported scheme with a supported one is
        // still skipped by the validator (the unsupported member taints the
        // whole entry). The warning must fire on the unsupported member so the
        // user is told that the test is not actually verifying the oauth2 leg.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'oauth2_user' => ['type' => 'oauth2', 'flows' => []],
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];
        $operation = [
            'security' => [
                ['oauth2_user' => [], 'BearerAuth' => []],
            ],
        ];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'POST',
            '/v1/secure',
            $spec,
            $operation,
            ['Authorization' => 'Bearer t'],
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'oauth2_user'", $warnings[0]);
    }

    #[Test]
    public function http_with_missing_scheme_field_is_hard_error_not_warning(): void
    {
        // `type: http` with no `scheme` key is malformed per OAS 3.x. It must
        // surface as a hard error rather than fall through to Unsupported
        // (which would emit the silent-pass warning). Without the malformed
        // guard the next code path would `strtolower(null)` and fatal — so
        // this also pins that the malformed branch fires before label
        // construction.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Broken' => ['type' => 'http'],
                ],
            ],
        ];
        $operation = ['security' => [['Broken' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/x',
            $spec,
            $operation,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is malformed', $errors[0]);
        $this->assertStringContainsString("'scheme' field", $errors[0]);
        $this->assertSame([], $warnings, 'malformed must take precedence over Unsupported warning');
    }

    #[Test]
    public function http_with_empty_scheme_value_is_hard_error_not_warning(): void
    {
        // Empty / whitespace-only `scheme` is the same defect class as a
        // missing field — it would otherwise fall through to Unsupported with
        // a meaningless `http-` label. Pin the rejection at the malformed
        // boundary so users are pushed to fix the spec.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Broken' => ['type' => 'http', 'scheme' => '   '],
                ],
            ],
        ];
        $operation = ['security' => [['Broken' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/x',
            $spec,
            $operation,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is malformed', $errors[0]);
        $this->assertSame([], $warnings, 'empty `scheme` is malformed, not silent-pass');
    }

    #[Test]
    public function two_unsupported_types_in_same_entry_each_warn(): void
    {
        // An AND-entry combining two distinct unsupported types
        // (oauth2 + openIdConnect) must produce two warnings — one per
        // scheme name. Guards against an early-break refactor that would
        // silently drop the second warning.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'oauth2_user' => ['type' => 'oauth2', 'flows' => []],
                    'OIDC' => ['type' => 'openIdConnect', 'openIdConnectUrl' => 'https://idp.example/.well-known/openid-configuration'],
                ],
            ],
        ];
        $operation = [
            'security' => [
                ['oauth2_user' => [], 'OIDC' => []],
            ],
        ];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/x',
            $spec,
            $operation,
        );

        $this->assertSame([], $errors);
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString("'oauth2_user'", $warnings[0]);
        $this->assertStringContainsString("'OIDC'", $warnings[1]);
    }

    #[Test]
    public function malformed_and_unsupported_in_same_entry_emits_both_signals(): void
    {
        // The validator iterates every scheme in the entry independently:
        // a malformed sibling produces a hard error, AND the unsupported
        // sibling fires its silent-pass warning. Pinning this prevents an
        // accidental refactor that would short-circuit on the first
        // malformed and lose the warning (or vice-versa).
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'Broken' => ['type' => 'htpp'],
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['Broken' => [], 'OAuth' => []]]];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/x',
            $spec,
            $operation,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is malformed', $errors[0]);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'OAuth'", $warnings[0]);
    }

    #[Test]
    public function root_level_security_with_oauth2_warns(): void
    {
        // Operation-level `security` is absent → root-level inherited.
        // Warning must fire identically to the operation-level path.
        $spec = [
            'security' => [['OAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];

        [$errors, $warnings] = $this->validateCapturingWarnings(
            'GET',
            '/v1/inherited',
            $spec,
            [],
        );

        $this->assertSame([], $errors);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'OAuth'", $warnings[0]);
    }

    #[Test]
    public function warning_includes_actionable_workaround_text(): void
    {
        // The warning's value to users is the actionable closing line that
        // tells them how to verify the bearer-token surface manually. If a
        // future refactor trims the message, this assertion fires before the
        // user-facing payload silently disappears.
        $spec = [
            'components' => [
                'securitySchemes' => [
                    'OAuth' => ['type' => 'oauth2', 'flows' => []],
                ],
            ],
        ];
        $operation = ['security' => [['OAuth' => []]]];

        [, $warnings] = $this->validateCapturingWarnings('GET', '/v1/x', $spec, $operation);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Workaround:', $warnings[0]);
        $this->assertStringContainsString('split the bearer-token surface', $warnings[0]);
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $operation
     * @param array<array-key, mixed> $headers
     *
     * @return array{0: string[], 1: string[]} [errors, warnings]
     */
    private function validateCapturingWarnings(
        string $method,
        string $path,
        array $spec,
        array $operation,
        array $headers = [],
    ): array {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING && str_starts_with($errstr, '[security]')) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $errors = $this->validator->validate($method, $path, $spec, $operation, $headers, [], []);
        } finally {
            restore_error_handler();
        }

        return [$errors, $captured];
    }
}
