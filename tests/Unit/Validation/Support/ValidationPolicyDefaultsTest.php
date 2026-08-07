<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;

use function array_map;
use function count;
use function range;

/**
 * Issue #502 (additive half): process-wide defaults for the validation-policy
 * settings that previously existed only as Laravel config keys. The
 * validators consult {@see ValidationPolicyDefaults} only when the caller
 * omits the corresponding constructor argument — an explicit argument always
 * wins, so the framework adapters (which pass explicit arguments from their
 * own configuration surfaces) are unaffected.
 */
class ValidationPolicyDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../../fixtures/specs');
        ValidationPolicyDefaults::reset();
    }

    protected function tearDown(): void
    {
        ValidationPolicyDefaults::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function unconfigured_readers_return_the_constructor_defaults(): void
    {
        $this->assertSame(20, ValidationPolicyDefaults::maxErrors());
        $this->assertSame(
            OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
            ValidationPolicyDefaults::skipResponseCodes(),
        );
        $this->assertSame([], ValidationPolicyDefaults::skipRequestValidationResponseCodes());
        $this->assertSame('', ValidationPolicyDefaults::defaultSpec());
    }

    #[Test]
    public function reset_restores_the_unconfigured_defaults(): void
    {
        ValidationPolicyDefaults::configure(
            maxErrors: 3,
            skipResponseCodes: ['404'],
            skipRequestValidationResponseCodes: ['422'],
            defaultSpec: 'petstore-3.0',
        );

        ValidationPolicyDefaults::reset();

        $this->assertSame(20, ValidationPolicyDefaults::maxErrors());
        $this->assertSame(
            OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
            ValidationPolicyDefaults::skipResponseCodes(),
        );
        $this->assertSame([], ValidationPolicyDefaults::skipRequestValidationResponseCodes());
        $this->assertSame('', ValidationPolicyDefaults::defaultSpec());
    }

    #[Test]
    public function process_default_max_errors_reaches_a_validator_constructed_without_the_argument(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        ValidationPolicyDefaults::configure(maxErrors: 1);
        $validator = new OpenApiResponseValidator(new StrictRequiredTracker());

        $result = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 200, ['data' => $items]);

        $this->assertFalse($result->isValid());
        $this->assertCount(1, $result->errors());
    }

    #[Test]
    public function explicit_max_errors_argument_wins_over_the_process_default(): void
    {
        $items = array_map(
            static fn(int $i) => ['id' => 'str-' . $i, 'name' => $i],
            range(1, 50),
        );

        ValidationPolicyDefaults::configure(maxErrors: 1);
        $validator = new OpenApiResponseValidator(new StrictRequiredTracker(), maxErrors: 2);

        $result = $validator->validate('petstore-3.0', 'GET', '/v1/pets', 200, ['data' => $items]);

        $this->assertFalse($result->isValid());
        $this->assertGreaterThan(1, count($result->errors()));
    }

    #[Test]
    public function process_default_skip_response_codes_reach_a_validator_constructed_without_the_argument(): void
    {
        ValidationPolicyDefaults::configure(skipResponseCodes: ['200']);
        $validator = new OpenApiResponseValidator(new StrictRequiredTracker());

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 'not-an-int', 'name' => 123]]],
        );

        $this->assertTrue($result->isValid(), 'a skip-pattern hit must not fail');
        $this->assertTrue($result->isSkipped(), 'the process-default pattern must skip the matching status');
    }

    #[Test]
    public function explicit_skip_response_codes_argument_wins_over_the_process_default(): void
    {
        ValidationPolicyDefaults::configure(skipResponseCodes: ['200']);
        $validator = new OpenApiResponseValidator(new StrictRequiredTracker(), skipResponseCodes: []);

        $result = $validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 'not-an-int', 'name' => 123]]],
        );

        $this->assertFalse($result->isValid(), 'an explicit empty pattern set must validate every status');
    }

    #[Test]
    public function process_default_skip_request_validation_codes_reach_a_validator_constructed_without_the_argument(): void
    {
        ValidationPolicyDefaults::configure(skipRequestValidationResponseCodes: ['422']);
        $validator = new OpenApiRequestValidator();

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [], // missing required `name` → request validation MUST fail
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertTrue($result->isValid(), 'skipped result must report isValid()=true');
        $this->assertTrue($result->isSkipped(), 'the process default must enable the documented-4xx downgrade');
    }

    #[Test]
    public function request_validator_without_a_process_default_stays_strict(): void
    {
        $validator = new OpenApiRequestValidator();

        $result = $validator->validate(
            'request-validation-skip',
            'POST',
            '/exact-422',
            [],
            [],
            [],
            'application/json',
            responseStatusCode: 422,
        );

        $this->assertFalse($result->isValid(), 'direct callers must stay strict when nothing is configured');
    }
}
