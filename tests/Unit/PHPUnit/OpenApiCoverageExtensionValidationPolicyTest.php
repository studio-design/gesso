<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\PHPUnit\InvalidValidationPolicyConfigurationException;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;

use function array_map;
use function fclose;
use function fopen;
use function range;
use function rewind;
use function stream_get_contents;

/**
 * Issue #502 (additive half): the `max_errors`, `skip_response_codes`,
 * `skip_request_validation_response_codes`, and `default_spec` extension
 * parameters give plain PHPUnit suites the validation-policy configuration
 * that previously existed only as Laravel config keys.
 */
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
        OpenApiCoverageExtension::overrideStderrForTesting(null);
        if ($this->stderrBuffer !== null) {
            fclose($this->stderrBuffer);
            $this->stderrBuffer = null;
        }
        ValidationPolicyDefaults::reset();
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
    public function max_errors_parameter_rejects_a_non_numeric_value(): void
    {
        try {
            $this->bootstrap(['max_errors' => 'many']);
            $this->fail('Expected InvalidValidationPolicyConfigurationException');
        } catch (InvalidValidationPolicyConfigurationException $e) {
            $this->assertStringContainsString('max_errors', $e->getMessage());
            $this->assertStringContainsString("'many'", $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
        $this->assertStringContainsString('max_errors', $this->readStderr());
    }

    #[Test]
    public function max_errors_parameter_rejects_a_negative_value(): void
    {
        $this->expectException(InvalidValidationPolicyConfigurationException::class);
        $this->expectExceptionMessage('max_errors');

        $this->bootstrap(['max_errors' => '-1']);
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
    public function skip_response_codes_parameter_rejects_a_blank_entry(): void
    {
        try {
            $this->bootstrap(['skip_response_codes' => '422,,404']);
            $this->fail('Expected InvalidValidationPolicyConfigurationException');
        } catch (InvalidValidationPolicyConfigurationException $e) {
            $this->assertStringContainsString('skip_response_codes[1]', $e->getMessage());
            $this->assertStringContainsString('must not be an empty string', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
    }

    #[Test]
    public function skip_response_codes_parameter_rejects_an_invalid_regex_at_bootstrap(): void
    {
        // Issue #502 review: a malformed pattern must FATAL at bootstrap,
        // not surface later as a constructor error inside the first test
        // that builds a validator.
        try {
            $this->bootstrap(['skip_response_codes' => '(unclosed']);
            $this->fail('Expected InvalidValidationPolicyConfigurationException');
        } catch (InvalidValidationPolicyConfigurationException $e) {
            $this->assertStringContainsString('skip_response_codes[0]', $e->getMessage());
            $this->assertStringContainsString('is not a valid regex pattern', $e->getMessage());
            $this->assertStringContainsString('(unclosed', $e->getMessage());
        }

        $this->assertStringContainsString('FATAL', $this->readStderr());
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

    /** @param array<string, string> $extra */
    private function bootstrap(array $extra = [], string $specs = 'refs-valid'): void
    {
        $extension = new OpenApiCoverageExtension();
        $parameters = ParameterCollection::fromArray($extra + [
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => $specs,
        ]);

        $extension->setupExtension(null, $parameters, null);
    }

    private function readStderr(): string
    {
        if ($this->stderrBuffer === null) {
            return '';
        }
        rewind($this->stderrBuffer);

        return (string) stream_get_contents($this->stderrBuffer);
    }
}
