<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Config;

use const INF;
use const NAN;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Config\InvalidGessoConfigurationException;

use function array_reverse;
use function chdir;
use function file_put_contents;
use function getcwd;
use function is_array;
use function mkdir;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

final class GessoConfigTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    /** @var list<string> */
    private array $dirs = [];
    private ?string $originalCwd = null;

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
            $this->originalCwd = null;
        }

        foreach ($this->paths as $path) {
            @unlink($path);
        }

        // Deepest first, so a nested directory does not keep its parent alive.
        foreach (array_reverse($this->dirs) as $dir) {
            @rmdir($dir);
        }

        $this->paths = [];
        $this->dirs = [];
    }

    /**
     * Every leaf `gesso.php` declares, spelled exactly as
     * [ADR 0005](docs/adr/0005-v3-configuration-and-cli-naming.md) froze it.
     *
     * The list is duplicated here on purpose: it is the contract, and a key
     * appearing or disappearing without the ADR moving first is the failure
     * this test exists to catch.
     *
     * @return list<string>
     */
    public static function frozenKeyPaths(): array
    {
        return [
            'spec.base_path',
            'spec.default',
            'spec.names',
            'spec.strip_prefixes',
            'validation.format',
            'validation.max_errors',
            'validation.enforce_discriminator',
            'validation.acknowledged_unvalidatable_schemes',
            'validation.skip_response_codes',
            'validation.skip_request_validation_response_codes',
            'strict.required.run',
            'strict.required.per_call',
            'strict.additional_properties.run',
            'strict.additional_properties.per_call',
            'coverage.min_coverage.endpoint',
            'coverage.min_coverage.response',
            'coverage.min_coverage.sdk_exercise',
            'coverage.min_coverage.strict',
            'coverage.report_output.markdown',
            'coverage.report_output.json',
            'coverage.report_output.junit',
            'coverage.report_output.html',
            'coverage.console_report',
            'coverage.sidecar_dir',
            'baseline.violations',
            'baseline.coverage',
            'baseline_stale.violations',
            'baseline_stale.coverage',
            'enum_drift.enabled',
            'enum_drift.scan_namespaces',
            'enum_drift.fail_on_drift',
            'phpunit.default_testsuite_as_full',
            'laravel.auto_assert',
            'laravel.auto_validate_request',
            'laravel.auto_inject_dummy_credentials',
            'laravel.route_parity.external_operation_ids',
            'laravel.route_parity.external_openapi_paths',
        ];
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function provideEvery_boolean_spelling_has_one_meaningCases(): iterable
    {
        yield 'real true' => [true, true];
        yield 'real false' => [false, false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
        yield 'on' => ['on', true];
        yield 'off' => ['off', false];
        yield 'yes' => ['yes', true];
        yield 'no' => ['no', false];
        yield 'one' => ['1', true];
        yield 'zero' => ['0', false];
        // Documented alongside the false spellings by the filter itself, and
        // already what the Laravel trait read it as.
        yield 'empty string' => ['', false];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNo_accessor_converts_a_key_of_another_typeCases(): iterable
    {
        yield 'bool over an enum' => ['bool', 'validation.format'];
        yield 'int over a percent' => ['int', 'coverage.min_coverage.endpoint'];
        yield 'number over an int' => ['number', 'validation.max_errors'];
        yield 'string over a list' => ['string', 'spec.names'];
        yield 'strings over a path' => ['strings', 'spec.base_path'];
        yield 'boolOrString over a bool' => ['boolOrString', 'validation.enforce_discriminator'];
    }

    #[Test]
    public function the_key_set_is_the_one_adr_0005_froze(): void
    {
        $this->assertSame(self::frozenKeyPaths(), $this->schemaLeafPaths());

        // And each of them is readable, so the list above cannot drift into
        // naming keys the accessors cannot reach.
        $config = GessoConfig::defaults();
        foreach (self::frozenKeyPaths() as $path) {
            $this->assertFalse($config->has($path), $path);
        }
    }

    #[Test]
    public function an_absent_file_yields_documented_defaults(): void
    {
        // The conventional location being empty is not an error — a project
        // that configures nothing runs on the documented defaults.
        $this->originalCwd = getcwd() ?: null;
        chdir($this->tempDir());

        $config = GessoConfig::load();

        $this->assertNull($config->sourcePath());
        $this->assertSame('text', $config->string('validation.format'));
        $this->assertSame(20, $config->int('validation.max_errors'));
        $this->assertTrue($config->bool('validation.enforce_discriminator'));
        $this->assertSame(['5\d\d'], $config->strings('validation.skip_response_codes'));
        $this->assertSame(['422', '400'], $config->strings('validation.skip_request_validation_response_codes'));
        $this->assertSame('off', $config->string('strict.required.run'));
        $this->assertSame('note', $config->string('baseline_stale.coverage'));
        $this->assertSame('default', $config->string('coverage.console_report'));
        $this->assertNull($config->number('coverage.min_coverage.endpoint'));
        $this->assertNull($config->string('spec.base_path'));
        $this->assertSame([], $config->strings('spec.names'));
    }

    #[Test]
    public function an_explicitly_requested_missing_file_is_fatal(): void
    {
        $missing = $this->tempDir() . '/nope.php';

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('not found: ' . $missing);

        GessoConfig::load($missing);
    }

    #[Test]
    public function a_file_that_does_not_return_an_array_is_fatal(): void
    {
        $path = $this->writeRaw("<?php\n\nreturn 'nope';\n");

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('must return an array, got string');

        GessoConfig::load($path);
    }

    #[Test]
    public function an_unknown_top_level_section_is_fatal(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"coverages" is not a known configuration key');

        GessoConfig::fromArray(['coverages' => []], '/tmp');
    }

    #[Test]
    public function an_unknown_key_inside_a_known_section_names_its_full_path(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"validation.max_error" is not a known configuration key');

        GessoConfig::fromArray(['validation' => ['max_error' => 5]], '/tmp');
    }

    #[Test]
    public function a_section_given_a_scalar_is_fatal(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"strict" expected a section (array of keys), got string');

        GessoConfig::fromArray(['strict' => 'off'], '/tmp');
    }

    /**
     * The divergence issue #501 opens with: the extension read `'off'` as
     * enforcement *on*, the Laravel trait read it as off. One truth table now,
     * and it agrees with the word.
     */
    #[Test]
    #[DataProvider('provideEvery_boolean_spelling_has_one_meaningCases')]
    public function every_boolean_spelling_has_one_meaning(mixed $written, bool $expected): void
    {
        $config = GessoConfig::fromArray(
            ['validation' => ['enforce_discriminator' => $written]],
            '/tmp',
        );

        $this->assertSame($expected, $config->bool('validation.enforce_discriminator'));
    }

    #[Test]
    public function an_unreadable_boolean_is_fatal_rather_than_silently_true(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"validation.enforce_discriminator" expected a boolean');

        GessoConfig::fromArray(['validation' => ['enforce_discriminator' => 'nope']], '/tmp');
    }

    /**
     * `spec_base_path` resolved against `getcwd()` in the extension and
     * against the Laravel base path in the Artisan command, so the same
     * string named two directories. It now names one, whatever the runner's
     * working directory is.
     */
    #[Test]
    public function relative_paths_resolve_against_the_config_directory(): void
    {
        $dir = $this->tempDir();
        $path = $this->writeRaw("<?php\n\nreturn " . var_export([
            'spec' => ['base_path' => 'openapi/bundled'],
            'coverage' => ['sidecar_dir' => 'build/sidecars'],
        ], true) . ";\n", $dir);

        $this->originalCwd = getcwd() ?: null;

        $fromRoot = GessoConfig::load($path);

        $subdir = $dir . '/sub';
        mkdir($subdir);
        $this->dirs[] = $subdir;
        chdir($subdir);

        $fromSubdir = GessoConfig::load($path);

        // realpath()'d, so the base is canonical (/var → /private/var on macOS).
        $canonical = realpath($dir);
        $this->assertIsString($canonical);

        $this->assertSame($canonical . '/openapi/bundled', $fromRoot->string('spec.base_path'));
        $this->assertSame($fromRoot->string('spec.base_path'), $fromSubdir->string('spec.base_path'));
        $this->assertSame($canonical . '/build/sidecars', $fromRoot->string('coverage.sidecar_dir'));
    }

    #[Test]
    public function an_absolute_path_is_left_alone(): void
    {
        $config = GessoConfig::fromArray(['spec' => ['base_path' => '/srv/openapi']], '/project');

        $this->assertSame('/srv/openapi', $config->string('spec.base_path'));
    }

    #[Test]
    public function an_empty_path_is_fatal(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"spec.base_path" expected a path, got an empty string');

        GessoConfig::fromArray(['spec' => ['base_path' => '  ']], '/project');
    }

    #[Test]
    public function has_separates_a_declared_key_from_its_default(): void
    {
        $config = GessoConfig::fromArray(['validation' => ['max_errors' => 20]], '/tmp');

        $this->assertTrue($config->has('validation.max_errors'));
        $this->assertFalse($config->has('validation.format'));
        // Same value either way — precedence is decided by declaration, not
        // by the value happening to differ from the default.
        $this->assertSame(20, $config->int('validation.max_errors'));
    }

    #[Test]
    public function an_enum_rejects_a_value_outside_its_set(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"strict.required.run" expected one of off, warn, fail');

        GessoConfig::fromArray(['strict' => ['required' => ['run' => 'loud']]], '/tmp');
    }

    #[Test]
    public function a_string_list_rejects_a_map_and_a_non_string_entry(): void
    {
        $config = GessoConfig::fromArray(['spec' => ['strip_prefixes' => ['/api', '/v1']]], '/tmp');
        $this->assertSame(['/api', '/v1'], $config->strings('spec.strip_prefixes'));

        try {
            GessoConfig::fromArray(['spec' => ['strip_prefixes' => ['a' => '/api']]], '/tmp');
            $this->fail('Expected a map to be rejected.');
        } catch (InvalidGessoConfigurationException $e) {
            $this->assertStringContainsString('"spec.strip_prefixes" expected a list of strings', $e->getMessage());
        }

        try {
            GessoConfig::fromArray(['spec' => ['strip_prefixes' => ['/api', 7]]], '/tmp');
            $this->fail('Expected a non-string entry to be rejected.');
        } catch (InvalidGessoConfigurationException $e) {
            $this->assertStringContainsString('"spec.strip_prefixes[1]" expected a string, got int', $e->getMessage());
        }
    }

    #[Test]
    public function max_errors_takes_a_non_negative_integer_only(): void
    {
        $this->assertSame(0, GessoConfig::fromArray(['validation' => ['max_errors' => 0]], '/tmp')
            ->int('validation.max_errors'));

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"validation.max_errors" expected a non-negative integer, got int');

        GessoConfig::fromArray(['validation' => ['max_errors' => -1]], '/tmp');
    }

    #[Test]
    public function a_threshold_takes_zero_to_one_hundred_only(): void
    {
        $config = GessoConfig::fromArray(
            ['coverage' => ['min_coverage' => ['endpoint' => 90, 'response' => 80.5]]],
            '/tmp',
        );

        $this->assertSame(90, $config->number('coverage.min_coverage.endpoint'));
        $this->assertSame(80.5, $config->number('coverage.min_coverage.response'));

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"coverage.min_coverage.endpoint" expected a number between 0 and 100');

        GessoConfig::fromArray(['coverage' => ['min_coverage' => ['endpoint' => 101]]], '/tmp');
    }

    /**
     * ADR 0005: the deprecated bearer-only key survives as a value of the
     * superset key, so the setting is a boolean that grew a third state.
     */
    #[Test]
    public function auto_inject_dummy_credentials_takes_a_boolean_or_the_bearer_mode(): void
    {
        $key = 'laravel.auto_inject_dummy_credentials';

        $this->assertTrue(GessoConfig::fromArray(
            ['laravel' => ['auto_inject_dummy_credentials' => true]],
            '/tmp',
        )->boolOrString($key));

        $this->assertSame('bearer', GessoConfig::fromArray(
            ['laravel' => ['auto_inject_dummy_credentials' => 'bearer']],
            '/tmp',
        )->boolOrString($key));

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('expected a boolean');

        GessoConfig::fromArray(['laravel' => ['auto_inject_dummy_credentials' => 'token']], '/tmp');
    }

    #[Test]
    public function reading_a_key_the_schema_does_not_declare_is_a_loud_programming_error(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('Unknown Gesso configuration key "validation.nope" requested');

        GessoConfig::defaults()->string('validation.nope');
    }

    /**
     * `has()` decides precedence against the surfaces' own inputs, so a
     * misspelled key answering "not configured" would silently discard what
     * the user did configure.
     */
    #[Test]
    public function has_rejects_a_key_the_schema_does_not_declare(): void
    {
        $config = GessoConfig::fromArray(['validation' => ['max_errors' => 5]], '/tmp');

        $this->assertTrue($config->has('validation.max_errors'));

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('Unknown Gesso configuration key "validation.max_error" requested');

        $config->has('validation.max_error');
    }

    #[Test]
    public function a_section_is_not_a_key(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"validation" is a section, not a setting');

        GessoConfig::defaults()->has('validation');
    }

    /**
     * The dangerous one: `bool()` over the credentials key would read the
     * narrow `'bearer'` mode as `true` and inject dummy credentials for every
     * inject-eligible scheme.
     */
    #[Test]
    public function reading_a_key_through_the_wrong_accessor_is_fatal(): void
    {
        $config = GessoConfig::fromArray(
            ['laravel' => ['auto_inject_dummy_credentials' => 'bearer']],
            '/tmp',
        );

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage(
            '"laravel.auto_inject_dummy_credentials" is a bool_enum setting; read it with boolOrString()',
        );

        $config->bool('laravel.auto_inject_dummy_credentials');
    }

    #[Test]
    #[DataProvider('provideNo_accessor_converts_a_key_of_another_typeCases')]
    public function no_accessor_converts_a_key_of_another_type(string $accessor, string $key): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('read it with');

        GessoConfig::defaults()->{$accessor}($key);
    }

    /**
     * Blank means nothing to any of these consumers, and the string encodings
     * on the other surfaces already reject it.
     */
    #[Test]
    public function a_string_list_rejects_a_blank_entry(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage(
            '"validation.acknowledged_unvalidatable_schemes[0]" expected a non-empty string',
        );

        GessoConfig::fromArray(
            ['validation' => ['acknowledged_unvalidatable_schemes' => ['']]],
            '/tmp',
        );
    }

    /**
     * The validators compile these; an unclosed group must fail at the file,
     * not at the first response it is matched against.
     */
    #[Test]
    public function a_status_code_pattern_list_rejects_an_unparseable_pattern(): void
    {
        $config = GessoConfig::fromArray(
            ['validation' => ['skip_response_codes' => ['5\d\d', '404']]],
            '/tmp',
        );
        $this->assertSame(['5\d\d', '404'], $config->strings('validation.skip_response_codes'));

        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('validation.skip_response_codes[0] is not a valid regex pattern "(unclosed"');

        GessoConfig::fromArray(['validation' => ['skip_response_codes' => ['(unclosed']]], '/tmp');
    }

    /**
     * `NAN` passes `is_float()` and every comparison against it is false, so a
     * bare range check lets it reach the coverage gate as a threshold.
     */
    #[Test]
    public function nan_is_not_a_threshold(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('"coverage.min_coverage.endpoint" expected a number between 0 and 100');

        GessoConfig::fromArray(['coverage' => ['min_coverage' => ['endpoint' => NAN]]], '/tmp');
    }

    #[Test]
    public function infinity_is_not_a_threshold_either(): void
    {
        $this->expectException(InvalidGessoConfigurationException::class);
        $this->expectExceptionMessage('expected a number between 0 and 100');

        GessoConfig::fromArray(['coverage' => ['min_coverage' => ['response' => INF]]], '/tmp');
    }

    #[Test]
    public function a_full_file_round_trips_every_documented_key(): void
    {
        $config = GessoConfig::load($this->writeRaw("<?php\n\nreturn " . var_export([
            'spec' => [
                'base_path' => '/srv/openapi',
                'default' => 'front',
                'names' => ['front', 'admin'],
                'strip_prefixes' => ['/api'],
            ],
            'validation' => [
                'format' => 'json',
                'max_errors' => 5,
                'enforce_discriminator' => false,
                'acknowledged_unvalidatable_schemes' => ['oauth'],
                'skip_response_codes' => [],
                'skip_request_validation_response_codes' => ['422'],
            ],
            'strict' => [
                'required' => ['run' => 'fail', 'per_call' => 'warn'],
                'additional_properties' => ['run' => 'warn', 'per_call' => 'warn'],
            ],
            'coverage' => [
                'min_coverage' => ['endpoint' => 90, 'response' => 80, 'sdk_exercise' => 50, 'strict' => true],
                'report_output' => [
                    'markdown' => '/out/cov.md',
                    'json' => '/out/cov.json',
                    'junit' => '/out/cov.xml',
                    'html' => '/out/cov.html',
                ],
                'console_report' => 'uncovered_only',
                'sidecar_dir' => '/out/sidecars',
            ],
            'baseline' => ['violations' => '/out/v.json', 'coverage' => '/out/c.json'],
            'baseline_stale' => ['violations' => 'fail', 'coverage' => 'off'],
            'enum_drift' => ['enabled' => true, 'scan_namespaces' => ['App\\Enums'], 'fail_on_drift' => false],
            'phpunit' => ['default_testsuite_as_full' => true],
            'laravel' => [
                'auto_assert' => true,
                'auto_validate_request' => true,
                'auto_inject_dummy_credentials' => 'bearer',
                'route_parity' => [
                    'external_operation_ids' => ['legacy.show'],
                    'external_openapi_paths' => ['/legacy/*'],
                ],
            ],
        ], true) . ";\n"));

        foreach (self::frozenKeyPaths() as $path) {
            $this->assertTrue($config->has($path), $path . ' should be declared');
        }

        $this->assertSame('json', $config->string('validation.format'));
        $this->assertFalse($config->bool('validation.enforce_discriminator'));
        $this->assertSame('bearer', $config->boolOrString('laravel.auto_inject_dummy_credentials'));
        $this->assertSame(['legacy.show'], $config->strings('laravel.route_parity.external_operation_ids'));
        $this->assertTrue($config->bool('coverage.min_coverage.strict'));
    }

    /**
     * @return list<string>
     */
    private function schemaLeafPaths(): array
    {
        $schema = (new ReflectionClass(GessoConfig::class))->getConstant('SCHEMA');
        $this->assertIsArray($schema);

        return $this->flatten($schema, '');
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private function flatten(array $schema, string $prefix): array
    {
        $paths = [];

        foreach ($schema as $key => $node) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($node) && !isset($node['type'])) {
                $paths = [...$paths, ...$this->flatten($node, $path)];
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/gesso-config-' . uniqid();
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function writeRaw(string $contents, ?string $dir = null): string
    {
        $path = ($dir ?? $this->tempDir()) . '/' . GessoConfig::FILENAME;
        file_put_contents($path, $contents);
        $this->paths[] = $path;

        return $path;
    }
}
