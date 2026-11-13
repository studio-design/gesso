<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use ReflectionProperty;
use Studio\Gesso\Fuzz\SchemaValueValidator;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\PHPUnit\ExtensionParameters;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\FlatExtensionSettings;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;

use function array_map;
use function bin2hex;
use function fclose;
use function file_put_contents;
use function fopen;
use function mkdir;
use function random_bytes;
use function range;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function var_export;

/**
 * Issue #502 (additive half): the `max_errors`, `skip_response_codes`,
 * `skip_request_validation_response_codes`, and `default_spec` extension
 * parameters give plain PHPUnit suites the validation-policy configuration
 * that previously existed only as Laravel config keys.
 */
#[IgnoreDeprecations]
class OpenApiCoverageExtensionValidationPolicyTest extends TestCase
{
    /** @var null|resource */
    private $stderrBuffer;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        ValidationPolicyDefaults::reset();

        $buffer = fopen('php://memory', 'w+');
        if ($buffer === false) {
            $this->fail('Could not open in-memory buffer for STDERR capture');
        }
        $this->stderrBuffer = $buffer;
        OpenApiCoverageExtension::overrideStderrForTesting($buffer);
    }

    protected function tearDown(): void
    {
        Deprecations::resetForTesting();
        OpenApiCoverageExtension::overrideStderrForTesting(null);
        if ($this->stderrBuffer !== null) {
            fclose($this->stderrBuffer);
            $this->stderrBuffer = null;
        }
        ValidationPolicyDefaults::reset();
        SchemaValueValidator::configureCacheMemory(null);
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function max_errors_parameter_configures_the_process_default(): void
    {
        $this->bootstrap(['max_errors' => '3']);

        $this->assertSame(3, ValidationPolicyDefaults::maxErrors());
    }

    #[Test]
    public function max_errors_parameter_reaches_a_hand_constructed_validator(): void
    {
        $this->bootstrap(['max_errors' => '1'], specs: 'petstore-3.0');

        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );
        $validator = new OpenApiResponseValidator(new StrictRequiredTracker());
        $result = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 200, ['data' => $items]);

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->errors());
    }

    #[Test]
    public function max_errors_parameter_accepts_zero_as_unlimited(): void
    {
        $this->bootstrap(['max_errors' => '0']);

        $this->assertSame(0, ValidationPolicyDefaults::maxErrors());
    }

    #[Test]
    public function fuzz_cache_memory_setting_reaches_the_value_check_cache(): void
    {
        $this->bootstrap(['fuzz_cache_memory_mb' => 64]);

        $this->assertSame(64 * 1024 * 1024, self::fuzzCacheBudget());
    }

    #[Test]
    public function fuzz_cache_memory_is_read_from_a_gesso_php_file(): void
    {
        $dir = sys_get_temp_dir() . '/gesso-fuzz-cache-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $path = $dir . '/gesso.php';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'spec' => ['base_path' => __DIR__ . '/../../fixtures/specs', 'names' => ['refs-valid']],
            'fuzz' => ['cache_memory_mb' => 3],
        ], true) . ";\n");

        try {
            (new OpenApiCoverageExtension())->setupExtension(
                null,
                ExtensionParameters::load(ParameterCollection::fromArray(['config' => $path])),
                null,
            );
        } finally {
            unlink($path);
            rmdir($dir);
        }

        $this->assertSame(3 * 1024 * 1024, self::fuzzCacheBudget());
    }

    #[Test]
    public function a_configuration_without_fuzz_cache_memory_keeps_the_default_budget(): void
    {
        SchemaValueValidator::configureCacheMemory(64);

        $this->bootstrap();

        $this->assertSame(SchemaValueValidator::DEFAULT_MAX_CACHED_BYTES, self::fuzzCacheBudget());
    }

    #[Test]
    public function skip_response_codes_parameter_configures_the_process_default(): void
    {
        $this->bootstrap(['skip_response_codes' => '404, 5\d\d']);

        $this->assertSame(['404', '5\d\d'], ValidationPolicyDefaults::skipResponseCodes());
    }

    #[Test]
    public function skip_response_codes_parameter_with_an_empty_value_disables_the_skip(): void
    {
        // An explicitly present empty value is Laravel's `[]` (issue #502
        // review): no skip patterns, so 5xx bodies are validated too. Only
        // an absent parameter keeps the built-in `5\d\d` default.
        $this->bootstrap(['skip_response_codes' => '']);

        $this->assertSame([], ValidationPolicyDefaults::skipResponseCodes());
    }

    #[Test]
    public function skip_request_validation_response_codes_parameter_configures_the_process_default(): void
    {
        $this->bootstrap(['skip_request_validation_response_codes' => '422,400']);

        $this->assertSame(
            ['422', '400'],
            ValidationPolicyDefaults::skipRequestValidationResponseCodes(),
        );
    }

    #[Test]
    public function default_spec_parameter_configures_the_process_default(): void
    {
        $this->bootstrap(['default_spec' => 'refs-valid']);

        $this->assertSame('refs-valid', ValidationPolicyDefaults::defaultSpec());
    }

    #[Test]
    public function bootstrap_without_the_parameters_resets_previous_process_defaults(): void
    {
        ValidationPolicyDefaults::configure(
            maxErrors: 3,
            skipResponseCodes: ['404'],
            skipRequestValidationResponseCodes: ['422'],
            defaultSpec: 'stale',
        );

        $this->bootstrap();

        $this->assertSame(20, ValidationPolicyDefaults::maxErrors());
        $this->assertSame(
            OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
            ValidationPolicyDefaults::skipResponseCodes(),
        );
        $this->assertSame([], ValidationPolicyDefaults::skipRequestValidationResponseCodes());
        $this->assertSame('', ValidationPolicyDefaults::defaultSpec());
    }

    private static function fuzzCacheBudget(): int
    {
        /** @var int */
        return (new ReflectionProperty(SchemaValueValidator::class, 'maxCachedBytes'))->getValue();
    }

    /** @param array<string, int|string> $extra */
    private function bootstrap(array $extra = [], string $specs = 'refs-valid'): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = FlatExtensionSettings::parameters($extra + [
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => $specs,
        ]);

        $extension->setupExtension(null, $parameters, null);
    }
}
