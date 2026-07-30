<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesAsserter;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallMode;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function restore_error_handler;
use function set_error_handler;

final class StrictAdditionalPropertiesValidatorIntegrationTest extends TestCase
{
    private StrictAdditionalPropertiesTracker $tracker;
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../../fixtures/specs');
        StrictAdditionalPropertiesTracker::resetCurrent();
        $this->tracker = new StrictAdditionalPropertiesTracker();
        StrictAdditionalPropertiesTracker::setCurrent($this->tracker);
        StrictAdditionalPropertiesPerCallChecker::reset();
        $this->validator = new OpenApiResponseValidator(new StrictRequiredTracker());
    }

    protected function tearDown(): void
    {
        StrictAdditionalPropertiesPerCallChecker::reset();
        StrictAdditionalPropertiesTracker::resetCurrent();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function successful_responses_aggregate_named_findings_by_operation_and_pointer(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $result = $this->validator->validate(
                'strict-additional-properties',
                'GET',
                '/users',
                200,
                ['id' => (string) $i, 'profile' => ['name' => 'A', 'secret' => true], 'trace_id' => 't'],
                'application/json',
            );
            $this->assertTrue($result->isValid());
        }

        $reports = StrictAdditionalPropertiesAsserter::detectAll($this->tracker);
        $this->assertCount(2, $reports);
        $this->assertSame(['/profile/secret', '/trace_id'], [
            $reports[0]->instancePointer,
            $reports[1]->instancePointer,
        ]);
        $this->assertSame('GET', $reports[0]->method);
        $this->assertSame('/users', $reports[0]->path);
        $this->assertSame(2, $reports[0]->hits);
        $this->assertSame(2, $this->tracker->evaluationsOn());
    }

    #[Test]
    public function pattern_properties_and_explicit_open_objects_do_not_report_findings(): void
    {
        $patterns = $this->validator->validate(
            'strict-additional-properties',
            'GET',
            '/patterns',
            200,
            ['id' => '1', 'x-trace' => 'abc'],
            'application/json',
        );
        $open = $this->validator->validate(
            'strict-additional-properties',
            'GET',
            '/open',
            200,
            ['id' => '1', 'anything' => true],
            'application/json',
        );
        $unevaluatedOpen = $this->validator->validate(
            'strict-additional-properties',
            'GET',
            '/unevaluated-open',
            200,
            ['id' => '1', 'anything' => true],
            'application/json',
        );

        $this->assertTrue($patterns->isValid());
        $this->assertTrue($open->isValid());
        $this->assertTrue($unevaluatedOpen->isValid());
        $this->assertSame([], StrictAdditionalPropertiesAsserter::detectAll($this->tracker));
        $this->assertSame(3, $this->tracker->evaluationsOn());
    }

    #[Test]
    public function all_of_declarations_are_merged_and_only_the_unknown_field_is_reported(): void
    {
        $result = $this->validator->validate(
            'strict-additional-properties',
            'GET',
            '/all-of',
            200,
            ['id' => '1', 'name' => 'Ada', 'debug' => true],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $reports = StrictAdditionalPropertiesAsserter::detectAll($this->tracker);
        $this->assertCount(1, $reports);
        $this->assertSame('/debug', $reports[0]->instancePointer);
    }

    #[Test]
    public function conformance_failures_do_not_contribute_to_the_tracker(): void
    {
        $result = $this->validator->validate(
            'strict-additional-properties',
            'GET',
            '/closed',
            200,
            ['id' => '1', 'secret' => true],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame(0, $this->tracker->evaluationsOn());
    }

    #[Test]
    public function openapi_30_does_not_treat_unsupported_unevaluated_properties_as_an_open_policy(): void
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_USER_WARNING);

        try {
            $result = $this->validator->validate(
                'strict-additional-properties-3.0',
                'GET',
                '/unsupported-unevaluated',
                200,
                ['id' => '1', 'trace_id' => 't'],
                'application/json',
            );
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result->isValid());
        $reports = StrictAdditionalPropertiesAsserter::detectAll($this->tracker);
        $this->assertCount(1, $reports);
        $this->assertSame('/trace_id', $reports[0]->instancePointer);
    }

    #[Test]
    public function selected_document_dialect_and_local_schema_override_control_unevaluated_properties(): void
    {
        $documentDraft07 = $this->validator->validate(
            'strict-additional-properties-draft-07',
            'GET',
            '/document-dialect',
            200,
            ['id' => '1', 'trace_id' => 't'],
            'application/json',
        );
        $local202012 = $this->validator->validate(
            'strict-additional-properties-draft-07',
            'GET',
            '/local-2020-12',
            200,
            ['id' => '1', 'trace_id' => 't'],
            'application/json',
        );
        $localDraft07 = $this->validator->validate(
            'strict-additional-properties',
            'GET',
            '/local-draft-07',
            200,
            ['id' => '1', 'trace_id' => 't'],
            'application/json',
        );

        $this->assertTrue($documentDraft07->isValid());
        $this->assertTrue($local202012->isValid());
        $this->assertTrue($localDraft07->isValid());
        $reports = StrictAdditionalPropertiesAsserter::detectAll($this->tracker);
        $this->assertCount(2, $reports);
        $this->assertSame(['/local-draft-07', '/document-dialect'], [
            $reports[0]->path,
            $reports[1]->path,
        ]);
        $this->assertSame(['/trace_id', '/trace_id'], [
            $reports[0]->instancePointer,
            $reports[1]->instancePointer,
        ]);
    }

    #[Test]
    public function embedded_resource_dialect_is_preserved_for_collected_child_schemas(): void
    {
        $result = $this->validator->validate(
            'strict-additional-properties',
            'GET',
            '/embedded-draft-07',
            200,
            ['payload' => ['id' => '1', 'trace_id' => 't']],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $reports = StrictAdditionalPropertiesAsserter::detectAll($this->tracker);
        $this->assertCount(1, $reports);
        $this->assertSame('/embedded-draft-07', $reports[0]->path);
        $this->assertSame('/payload/trace_id', $reports[0]->instancePointer);
    }

    #[Test]
    public function openapi_32_additional_operation_method_spelling_is_preserved(): void
    {
        $result = $this->validator->validate(
            'strict-additional-properties-3.2',
            'customCheck',
            '/jobs',
            200,
            ['id' => '1', 'trace_id' => 't'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $reports = StrictAdditionalPropertiesAsserter::detectAll($this->tracker);
        $this->assertCount(1, $reports);
        $this->assertSame('customCheck', $reports[0]->method);
    }

    #[Test]
    public function per_call_mode_warns_immediately_with_the_operation_and_pointer(): void
    {
        StrictAdditionalPropertiesPerCallChecker::configure(StrictAdditionalPropertiesPerCallMode::Warn);
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            if ($severity === E_USER_WARNING) {
                $warning = $message;

                return true;
            }

            return false;
        });

        try {
            $result = $this->validator->validate(
                'strict-additional-properties',
                'GET',
                '/catalog',
                200,
                [['id' => '1', 'secret' => true]],
                'application/json',
            );
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($result->isValid());
        $this->assertNotNull($warning);
        $this->assertStringContainsString('GET /catalog', $warning);
        $this->assertStringContainsString('[*]/secret', $warning);
    }
}
