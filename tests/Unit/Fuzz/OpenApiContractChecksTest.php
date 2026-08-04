<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\ExploredOperation;
use Studio\Gesso\Fuzz\OpenApiContractChecks;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_key_exists;
use function range;

class OpenApiContractChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function collects_a_failure_instead_of_throwing_when_the_probe_is_accepted(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 200; // API wrongly accepts the undocumented method
            })
            ->report();

        $this->assertCount(1, $dispatched);
        $this->assertNotContains($dispatched[0]->method->value, ['GET', 'POST'], 'probe must use an undocumented method');
        $this->assertNull($dispatched[0]->body);
        $this->assertSame([], $dispatched[0]->query);
        $this->assertSame('/pets', $dispatched[0]->matchedPath);

        $this->assertCount(1, $summary->failures);
        $failure = $summary->failures[0];
        $this->assertSame(ContractCheck::UnsupportedMethod, $failure->check);
        $this->assertSame('/pets', $failure->path);
        $this->assertSame([405], $failure->expectedStatuses);
        $this->assertSame(200, $failure->actualStatus);
        $this->assertSame(1, $summary->probedPaths);
        $this->assertSame(1, $summary->dispatchedProbes);
    }

    #[Test]
    public function passes_when_the_probe_is_rejected_with_405(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();

        $this->assertFalse($summary->hasFailures());
        $this->assertSame('', $summary->describeFailures());
    }

    #[Test]
    public function expected_statuses_override_replaces_the_default(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets'])
            ->expectedStatuses(ContractCheck::UnsupportedMethod, [405, 404])
            ->dispatchUsing(static fn(ExploredCase $case): int => 404)
            ->report();

        $this->assertFalse($summary->hasFailures());
    }

    #[Test]
    public function substitutes_generated_path_parameters(): void
    {
        $uris = [];

        OpenApiContractChecks::run('contract-checks', seed: 3)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/pets/{petId}'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$uris): int {
                $uris[] = $case->uri();

                return 405;
            })
            ->report();

        $this->assertCount(1, $uris);
        $this->assertStringNotContainsString('{petId}', $uris[0]);
    }

    #[Test]
    public function hooks_run_in_order_and_tear_down_runs_on_dispatch_failure(): void
    {
        $events = [];

        try {
            OpenApiContractChecks::run('contract-checks', seed: 7)
                ->checks([ContractCheck::UnsupportedMethod])
                ->includePaths(['/pets'])
                ->setUpUsing(static function (ExploredOperation $operation) use (&$events): void {
                    $events[] = 'setUp';
                })
                ->authenticateUsing(static function (ExploredOperation $operation) use (&$events): void {
                    $events[] = 'auth';
                })
                ->tearDownUsing(static function (ExploredOperation $operation) use (&$events): void {
                    $events[] = 'tearDown';
                })
                ->dispatchUsing(static function (ExploredCase $case) use (&$events): int {
                    $events[] = 'dispatch';

                    throw new RuntimeException('boom');
                })
                ->report();
            $this->fail('Expected the dispatch failure to be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Contract check dispatch failed.', $e->getMessage());
            $this->assertStringContainsString('unsupported_method', $e->getMessage());
            $this->assertStringContainsString('Curl:', $e->getMessage());
        }

        $this->assertSame(['setUp', 'auth', 'dispatch', 'tearDown'], $events);
    }

    #[Test]
    public function loud_failures_for_missing_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires checks\(\)/');
        OpenApiContractChecks::run('contract-checks')
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();
    }

    #[Test]
    public function checks_rejects_an_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenApiContractChecks::run('contract-checks')->checks([]);
    }

    #[Test]
    public function report_requires_dispatch_using(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/requires dispatchUsing\(\)/');
        OpenApiContractChecks::run('contract-checks')
            ->checks([ContractCheck::UnsupportedMethod])
            ->report();
    }

    #[Test]
    public function expected_statuses_validates_range_and_emptiness(): void
    {
        $plan = OpenApiContractChecks::run('contract-checks');

        $this->expectException(InvalidArgumentException::class);
        $plan->expectedStatuses(ContractCheck::UnsupportedMethod, [99]);
    }

    #[Test]
    public function expected_statuses_rejects_an_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenApiContractChecks::run('contract-checks')->expectedStatuses(ContractCheck::UnsupportedMethod, []);
    }

    #[Test]
    public function run_rejects_an_empty_spec_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenApiContractChecks::run('');
    }

    #[Test]
    public function filters_matching_nothing_fail_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/matched no operations/');
        OpenApiContractChecks::run('contract-checks')
            ->checks([ContractCheck::UnsupportedMethod])
            ->includeTags(['nope'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();
    }

    #[Test]
    public function a_fully_documented_path_is_skipped_with_a_reason(): void
    {
        $dispatched = 0;

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/saturated'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched++;

                return 405;
            })
            ->report();

        $this->assertSame(0, $dispatched);
        $this->assertCount(1, $summary->skips);
        $this->assertSame('/saturated', $summary->skips[0]->path);
        $this->assertNull($summary->skips[0]->method);
        $this->assertStringContainsString('Every explorable HTTP method is documented', $summary->skips[0]->reason);
    }

    #[Test]
    public function a_custom_method_only_path_is_skipped_not_probed(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/custom-only'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 405)
            ->report();

        $this->assertSame(0, $summary->dispatchedProbes);
        $this->assertCount(1, $summary->skips);
        $this->assertStringContainsString('explorer-supported method', $summary->skips[0]->reason);
    }

    #[Test]
    public function an_additional_operations_entry_spelling_a_fixed_method_is_documented_not_probed(): void
    {
        $methods = [];
        foreach (range(1, 10) as $seed) {
            OpenApiContractChecks::run('contract-checks', seed: $seed)
                ->checks([ContractCheck::UnsupportedMethod])
                ->includePaths(['/mixed'])
                ->dispatchUsing(static function (ExploredCase $case) use (&$methods): int {
                    $methods[] = $case->method->value;

                    return 405;
                })
                ->report();
        }

        // /mixed documents GET (fixed field) and PUT (additionalOperations
        // "PUT" — the wire method PUT, resolved case-sensitively at runtime).
        $this->assertNotContains('PUT', $methods);
        $this->assertNotContains('GET', $methods);
        $this->assertNotSame([], $methods);
    }

    #[Test]
    public function probe_avoids_methods_documented_by_a_colliding_path_template(): void
    {
        $methods = [];
        foreach (range(1, 10) as $seed) {
            OpenApiContractChecks::run('contract-checks', seed: $seed)
                ->checks([ContractCheck::UnsupportedMethod])
                ->includePaths(['/members/me'])
                ->dispatchUsing(static function (ExploredCase $case) use (&$methods): int {
                    $methods[] = $case->method->value;

                    return 405;
                })
                ->report();
        }

        // '/members/me' documents only GET, but the concrete probe URI is an
        // instance of '/members/{member_id}' (member_id = "me"), which
        // documents PATCH and DELETE. Probing those methods would be routed
        // to the documented operations and prove nothing (issue #440).
        $this->assertNotSame([], $methods);
        $this->assertNotContains('GET', $methods);
        $this->assertNotContains('PATCH', $methods);
        $this->assertNotContains('DELETE', $methods);
    }

    #[Test]
    public function skips_when_every_undocumented_method_is_documented_by_a_colliding_template(): void
    {
        $dispatched = 0;

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/things/mine'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched++;

                return 405;
            })
            ->report();

        // '/things/{thing_id}' documents every method '/things/mine' leaves
        // undocumented, and '/things/mine' is an instance of that template,
        // so no probe method can prove anything.
        $this->assertSame(0, $dispatched);
        $this->assertSame(0, $summary->dispatchedProbes);
        $this->assertCount(1, $summary->skips);
        $skip = $summary->skips[0];
        $this->assertSame('/things/mine', $skip->path);
        $this->assertNull($skip->method);
        $this->assertStringContainsString('/things/mine', $skip->reason);
        $this->assertStringContainsString('/things/{thing_id}', $skip->reason);
    }

    #[Test]
    public function probes_a_path_whose_only_operation_requires_a_non_json_body(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/oauth/token'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 405;
            })
            ->report();

        // Issue #439: the probe never sends a body, so a documented operation
        // whose required body is form-encoded (not JSON) must not gate it.
        $this->assertSame([], $summary->skips);
        $this->assertCount(1, $dispatched);
        $this->assertNull($dispatched[0]->body);
        $this->assertSame([], $dispatched[0]->query);
        $this->assertSame([], $dispatched[0]->headers);
        $this->assertNotSame('POST', $dispatched[0]->method->value);
        $this->assertSame(1, $summary->dispatchedProbes);
    }

    #[Test]
    public function generates_path_parameters_even_when_the_documented_body_is_not_json(): void
    {
        $uris = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/uploads/{uploadId}'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$uris): int {
                $uris[] = $case->uri();

                return 405;
            })
            ->report();

        $this->assertSame([], $summary->skips);
        $this->assertCount(1, $uris);
        $this->assertStringNotContainsString('{uploadId}', $uris[0]);
    }

    #[Test]
    public function an_ungeneratable_query_parameter_does_not_gate_the_probe(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/search'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 405;
            })
            ->report();

        // The probe sends no query string, so a required `content`-form query
        // parameter must not gate probe construction either.
        $this->assertSame([], $summary->skips);
        $this->assertCount(1, $dispatched);
        $this->assertSame([], $dispatched[0]->query);
    }

    #[Test]
    public function skips_when_a_path_parameter_cannot_be_generated(): void
    {
        $dispatched = 0;

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::UnsupportedMethod])
            ->includePaths(['/callbacks/{payload}'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched++;

                return 405;
            })
            ->report();

        // A path parameter the explorer cannot generate is a real inability
        // to construct the probe URI — the skip must remain.
        $this->assertSame(0, $dispatched);
        $this->assertCount(1, $summary->skips);
        $this->assertSame('/callbacks/{payload}', $summary->skips[0]->path);
        $this->assertStringContainsString("path parameter 'payload'", $summary->skips[0]->reason);
    }

    #[Test]
    public function probe_method_is_deterministic_for_a_seed_and_undocumented(): void
    {
        $probeFor = static function (int $seed): string {
            $method = null;
            OpenApiContractChecks::run('contract-checks', seed: $seed)
                ->checks([ContractCheck::UnsupportedMethod])
                ->includePaths(['/pets'])
                ->dispatchUsing(static function (ExploredCase $case) use (&$method): int {
                    $method = $case->method->value;

                    return 405;
                })
                ->report();

            return $method ?? self::fail('probe did not dispatch');
        };

        $first = $probeFor(7);
        $this->assertSame($first, $probeFor(7), 'same seed must choose the same probe method');
        $this->assertContains($first, ['PUT', 'PATCH', 'DELETE', 'QUERY']);
        // Pinned regression: seed 7 deterministically selects PUT for /pets.
        $this->assertSame('PUT', $first);
    }

    #[Test]
    public function ignored_auth_probes_without_and_with_invalid_credentials(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->includePaths(['/secure'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 200; // API wrongly serves the secured operation
            })
            ->report();

        $this->assertCount(2, $dispatched);
        $this->assertSame(['GET', 'GET'], [$dispatched[0]->method->value, $dispatched[1]->method->value]);
        $this->assertArrayNotHasKey('Authorization', $dispatched[0]->headers);
        $this->assertSame('Bearer gesso-invalid-credential', $dispatched[1]->headers['Authorization']);

        $this->assertCount(2, $summary->failures);
        $this->assertSame(2, $summary->dispatchedProbes);
        $this->assertSame(1, $summary->probedPaths);
        $this->assertSame(
            ['no credentials', 'invalid credentials'],
            [$summary->failures[0]->mutation, $summary->failures[1]->mutation],
        );
        $this->assertSame('secureGet', $summary->failures[0]->operationId);
        $this->assertSame([401, 403], $summary->failures[0]->expectedStatuses);
        $this->assertSame([], $summary->failures[0]->expectedStatusClasses);
        $this->assertStringContainsString('ignored_auth: GET /secure (secureGet) [no credentials]', $summary->failures[0]->describe());
        $this->assertStringContainsString('expected 401 or 403, got 200', $summary->failures[0]->describe());
    }

    #[Test]
    public function ignored_auth_passes_when_both_probes_are_rejected(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->includePaths(['/secure'])
            ->dispatchUsing(static fn(ExploredCase $case): int => 401)
            ->report();

        $this->assertFalse($summary->hasFailures());
        $this->assertSame(2, $summary->dispatchedProbes);
    }

    #[Test]
    public function ignored_auth_never_runs_the_authenticate_hook(): void
    {
        $events = [];

        OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->includePaths(['/secure'])
            ->setUpUsing(static function (ExploredOperation $operation) use (&$events): void {
                $events[] = 'setUp';
            })
            ->authenticateUsing(static function (ExploredOperation $operation) use (&$events): void {
                $events[] = 'auth';
            })
            ->tearDownUsing(static function (ExploredOperation $operation) use (&$events): void {
                $events[] = 'tearDown';
            })
            ->dispatchUsing(static function (ExploredCase $case) use (&$events): int {
                $events[] = 'dispatch';

                return 401;
            })
            ->report();

        // Handing the probe credentials would defeat the check entirely.
        $this->assertNotContains('auth', $events);
        $this->assertSame(['setUp', 'dispatch', 'tearDown', 'setUp', 'dispatch', 'tearDown'], $events);
    }

    #[Test]
    public function ignored_auth_strips_and_garbles_every_declared_credential_location(): void
    {
        $dispatched = [];

        OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->includePaths(['/secure-api-key'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 401;
            })
            ->report();

        $this->assertCount(2, $dispatched);

        // The operation also declares X-Api-Key as an optional header
        // parameter, so the generated valid case carries a value there; the
        // no-credential probe must not ship it.
        $this->assertArrayNotHasKey('X-Api-Key', $dispatched[0]->headers);
        $this->assertArrayNotHasKey('api_key', $dispatched[0]->query);
        $this->assertSame([], $dispatched[0]->cookies);

        // An AND-style requirement is only exercised when every scheme is
        // present but wrong.
        $this->assertSame('gesso-invalid-credential', $dispatched[1]->headers['X-Api-Key']);
        $this->assertSame('gesso-invalid-credential', $dispatched[1]->query['api_key']);
        // A cookie credential must travel as a cookie, not as a Cookie header:
        // test clients build their cookie bag from a separate argument, so a
        // header alone would leave $request->cookie(...) empty and make this
        // probe indistinguishable from the no-credential one.
        $this->assertSame('gesso-invalid-credential', $dispatched[1]->cookies['session']);
        $this->assertArrayNotHasKey('Cookie', $dispatched[1]->headers);
        $this->assertStringContainsString("-H 'Cookie: <redacted>'", $dispatched[1]->curlSnippet());
    }

    #[Test]
    public function ignored_auth_fails_loudly_on_a_malformed_security_declaration(): void
    {
        // Reading `security: "not-a-list"` as "no authentication required"
        // would report a green run with zero probes for a broken spec — the
        // runtime validator treats it as a hard error, so this must too.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a list of requirement objects, got string/');

        OpenApiContractChecks::run('contract-checks-malformed-security', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->dispatchUsing(static fn(ExploredCase $case): int => 401)
            ->report();
    }

    #[Test]
    public function ignored_auth_skips_the_invalid_credential_probe_for_unlocatable_schemes(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->includePaths(['/secure-oauth'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 401;
            })
            ->report();

        // oauth2 credentials have no location this library will guess, but the
        // no-credential probe still proves enforcement.
        $this->assertCount(1, $dispatched);
        $this->assertCount(1, $summary->skips);
        $this->assertSame('GET', $summary->skips[0]->method);
        $this->assertStringContainsString('invalid-credential probe was not dispatched', $summary->skips[0]->reason);
    }

    #[Test]
    public function ignored_auth_skips_operations_that_do_not_require_credentials(): void
    {
        $dispatched = 0;

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->includePaths(['/opted-out', '/optional-auth'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched++;

                return 200;
            })
            ->report();

        // `security: []` opts out; a `{}` entry documents optional credentials.
        // Neither is a contract hole when the API answers 200.
        $this->assertSame(0, $dispatched);
        $this->assertSame(0, $summary->probedPaths);
        $this->assertCount(2, $summary->skips);
        $this->assertStringContainsString('no effective security requirement', $summary->skips[0]->reason);
    }

    #[Test]
    public function ignored_auth_inherits_root_level_security(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks-root-security', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case->matchedPath;

                return 401;
            })
            ->report();

        // Most real specs declare security once at the root; missing that
        // inheritance would make the check silently do nothing.
        $this->assertSame(['/inherited', '/inherited'], $dispatched);
        $this->assertCount(1, $summary->skips);
        $this->assertSame('/overridden', $summary->skips[0]->path);
    }

    #[Test]
    public function missing_required_header_omits_one_required_header_per_probe(): void
    {
        $dispatched = [];

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::MissingRequiredHeader])
            ->includePaths(['/tenants'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched[] = $case;

                return 200; // API wrongly accepts the incomplete request
            })
            ->report();

        // [0] is the control request: unmutated, every header present.
        $this->assertCount(3, $dispatched);
        $this->assertArrayHasKey('X-Request-Id', $dispatched[0]->headers);
        $this->assertArrayHasKey('X-Tenant', $dispatched[0]->headers);

        $this->assertArrayNotHasKey('X-Request-Id', $dispatched[1]->headers);
        $this->assertArrayHasKey('X-Tenant', $dispatched[1]->headers);
        $this->assertArrayHasKey('X-Trace', $dispatched[1]->headers);
        $this->assertArrayNotHasKey('X-Tenant', $dispatched[2]->headers);
        $this->assertArrayHasKey('X-Request-Id', $dispatched[2]->headers);

        $this->assertSame(3, $summary->dispatchedProbes);
        $this->assertCount(2, $summary->failures);
        $this->assertSame("omitted required header 'X-Request-Id'", $summary->failures[0]->mutation);
        $this->assertSame('listTenants', $summary->failures[0]->operationId);
        $this->assertSame([], $summary->failures[0]->expectedStatuses);
        $this->assertSame([4], $summary->failures[0]->expectedStatusClasses);
        $this->assertStringContainsString('expected 4xx, got 200', $summary->failures[0]->describe());
    }

    #[Test]
    public function missing_required_header_accepts_any_client_error(): void
    {
        foreach ([400, 401, 403, 406, 422] as $status) {
            $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
                ->checks([ContractCheck::MissingRequiredHeader])
                ->includePaths(['/tenants'])
                // The control request (every header present) succeeds; only the
                // omission probes answer with the client error under test.
                ->dispatchUsing(static fn(ExploredCase $case): int => array_key_exists('X-Request-Id', $case->headers) &&
                    array_key_exists('X-Tenant', $case->headers) ? 200 : $status)
                ->report();

            $this->assertSame([], $summary->skips);
            $this->assertFalse($summary->hasFailures(), "status {$status} must satisfy the 4xx expectation");
        }
    }

    #[Test]
    public function missing_required_header_skips_when_the_control_request_does_not_reach_the_handler(): void
    {
        $dispatched = 0;

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::MissingRequiredHeader])
            ->includePaths(['/tenants'])
            // No credentials configured, so even the valid request is rejected.
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched++;

                return 401;
            })
            ->report();

        // Without the control gate this run would be green: both omission
        // probes would answer 401 and be scored as enforcement that never
        // happened.
        $this->assertSame(1, $dispatched, 'only the control request is dispatched');
        $this->assertFalse($summary->hasFailures());
        $this->assertCount(1, $summary->skips);
        $this->assertSame('GET', $summary->skips[0]->method);
        $this->assertStringContainsString('answered 401', $summary->skips[0]->reason);
        $this->assertStringContainsString('never reached the handler', $summary->skips[0]->reason);
    }

    #[Test]
    public function missing_required_header_skips_operations_without_required_headers(): void
    {
        $dispatched = 0;

        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::MissingRequiredHeader])
            ->includePaths(['/pets'])
            ->dispatchUsing(static function (ExploredCase $case) use (&$dispatched): int {
                $dispatched++;

                return 400;
            })
            ->report();

        $this->assertSame(0, $dispatched);
        $this->assertCount(2, $summary->skips);
        $this->assertStringContainsString('no required in:header parameter', $summary->skips[0]->reason);
    }

    #[Test]
    public function missing_required_header_runs_the_authenticate_hook(): void
    {
        $authenticated = 0;

        OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::MissingRequiredHeader])
            ->includePaths(['/tenants'])
            ->authenticateUsing(static function (ExploredOperation $operation) use (&$authenticated): void {
                $authenticated++;
            })
            ->dispatchUsing(static fn(ExploredCase $case): int => array_key_exists('X-Request-Id', $case->headers) &&
                array_key_exists('X-Tenant', $case->headers) ? 200 : 400)
            ->report();

        // Only ignored_auth withholds credentials; every other check needs the
        // request to reach the handler it is testing — the control request
        // included.
        $this->assertSame(3, $authenticated);
    }

    #[Test]
    public function an_expected_status_override_replaces_the_default_status_class(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::MissingRequiredHeader])
            ->includePaths(['/tenants'])
            ->expectedStatuses(ContractCheck::MissingRequiredHeader, [400])
            ->dispatchUsing(static fn(ExploredCase $case): int => array_key_exists('X-Request-Id', $case->headers) &&
                array_key_exists('X-Tenant', $case->headers) ? 200 : 422)
            ->report();

        // An exact list means exact: the default 4xx class must not widen it.
        $this->assertCount(2, $summary->failures);
        $this->assertSame([400], $summary->failures[0]->expectedStatuses);
        $this->assertSame([], $summary->failures[0]->expectedStatusClasses);
    }

    #[Test]
    public function expected_status_classes_override_replaces_the_default(): void
    {
        $summary = OpenApiContractChecks::run('contract-checks', seed: 7)
            ->checks([ContractCheck::IgnoredAuth])
            ->includePaths(['/secure'])
            ->expectedStatusClasses(ContractCheck::IgnoredAuth, [4])
            ->dispatchUsing(static fn(ExploredCase $case): int => 418)
            ->report();

        $this->assertFalse($summary->hasFailures());
    }

    #[Test]
    public function expected_status_classes_validates_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/between 1 and 5/');
        OpenApiContractChecks::run('contract-checks')->expectedStatusClasses(ContractCheck::MissingRequiredHeader, [9]);
    }

    #[Test]
    public function expected_status_classes_rejects_an_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenApiContractChecks::run('contract-checks')->expectedStatusClasses(ContractCheck::MissingRequiredHeader, []);
    }
}
