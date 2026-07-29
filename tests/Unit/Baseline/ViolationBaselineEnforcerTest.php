<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\ValidationIssue;

class ViolationBaselineEnforcerTest extends TestCase
{
    protected function tearDown(): void
    {
        ViolationBaselineEnforcer::resetCurrent();
        parent::tearDown();
    }

    #[Test]
    public function no_enforcer_is_installed_by_default(): void
    {
        $this->assertNull(ViolationBaselineEnforcer::current());
    }

    #[Test]
    public function set_current_installs_and_reset_removes_the_enforcer(): void
    {
        $enforcer = new ViolationBaselineEnforcer(new ViolationBaseline());
        ViolationBaselineEnforcer::setCurrent($enforcer);

        $this->assertSame($enforcer, ViolationBaselineEnforcer::current());

        ViolationBaselineEnforcer::resetCurrent();

        $this->assertNull(ViolationBaselineEnforcer::current());
    }

    #[Test]
    public function suppresses_a_result_whose_every_issue_is_baselined(): void
    {
        $baseline = new ViolationBaseline();
        $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'));
        $enforcer = new ViolationBaselineEnforcer($baseline);

        $result = OpenApiValidationResult::failure(
            ['[/data/0/id] value must be a string'],
            '/v1/pets',
            '200',
            'application/json',
            [new ValidationIssue(
                'response.body',
                '[/data/0/id] value must be a string',
                instancePath: '/data/0/id',
                keyword: 'type',
                method: 'GET',
                path: '/v1/pets',
                statusCode: '200',
                contentType: 'application/json',
            )],
        );

        $this->assertTrue($enforcer->suppressesResult('front', $result, 'GET', '/v1/pets'));
        $this->assertSame(1, $enforcer->hitCount());
        $this->assertSame([], $enforcer->staleEntries());
    }

    #[Test]
    public function does_not_suppress_when_any_issue_is_new(): void
    {
        $baseline = new ViolationBaseline();
        $known = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $baseline->add($known);
        $enforcer = new ViolationBaselineEnforcer($baseline);

        $result = OpenApiValidationResult::failure(
            ['[/data/0/id] value must be a string', '[/name] value must be a string'],
            '/v1/pets',
            '200',
            'application/json',
            [
                new ValidationIssue('response.body', '[/data/0/id] value must be a string', instancePath: '/data/0/id', keyword: 'type', method: 'GET', path: '/v1/pets', statusCode: '200', contentType: 'application/json'),
                new ValidationIssue('response.body', '[/name] value must be a string', instancePath: '/name', keyword: 'type', method: 'GET', path: '/v1/pets', statusCode: '200', contentType: 'application/json'),
            ],
        );

        $this->assertFalse($enforcer->suppressesResult('front', $result, 'GET', '/v1/pets'));
    }

    #[Test]
    public function marks_baselined_issues_as_hit_even_when_suppression_fails(): void
    {
        $baseline = new ViolationBaseline();
        $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type'));
        $enforcer = new ViolationBaselineEnforcer($baseline);

        $result = OpenApiValidationResult::failure(
            ['[/data/0/id] value must be a string', '[/name] value must be a string'],
            '/v1/pets',
            '200',
            'application/json',
            [
                new ValidationIssue('response.body', '[/data/0/id] value must be a string', instancePath: '/data/0/id', keyword: 'type', method: 'GET', path: '/v1/pets', statusCode: '200', contentType: 'application/json'),
                new ValidationIssue('response.body', '[/name] value must be a string', instancePath: '/name', keyword: 'type', method: 'GET', path: '/v1/pets', statusCode: '200', contentType: 'application/json'),
            ],
        );

        $enforcer->suppressesResult('front', $result, 'GET', '/v1/pets');

        // The known entry did occur, so it must not be reported as stale
        // even though the surrounding result failed on the new issue.
        $this->assertSame(1, $enforcer->hitCount());
        $this->assertSame([], $enforcer->staleEntries());
    }

    #[Test]
    public function excluded_category_issues_do_not_block_suppression(): void
    {
        $baseline = new ViolationBaseline();
        $baseline->add(new ViolationFingerprint('front', 'GET', '/v1/pets', null, null, 'response.request_context', null, null));
        $enforcer = new ViolationBaselineEnforcer($baseline);

        // The response.body issue is a decode-failure artifact (absent
        // placeholder body); with the category excluded, only the
        // request-context issue needs to be baselined.
        $result = OpenApiValidationResult::failure(
            ['Response body is empty but a schema is defined.', 'Path not found in spec.'],
            issues: [
                new ValidationIssue('response.body', 'Response body is empty but a schema is defined.', method: 'GET'),
                new ValidationIssue('response.request_context', 'Path not found in spec.', method: 'GET'),
            ],
        );

        $this->assertTrue($enforcer->suppressesResult('front', $result, 'GET', '/v1/pets', 'response.body'));
        $this->assertFalse($enforcer->suppressesResult('front', $result, 'GET', '/v1/pets'));
    }

    #[Test]
    public function suppresses_a_baselined_decode_failure_via_its_null_context_fingerprint(): void
    {
        $baseline = new ViolationBaseline();
        // Exactly the fingerprint the adapters record at generation time:
        // raw method / path, no matched status or content-type, no pointer,
        // the synthetic `parse` keyword.
        $baseline->add(ViolationFingerprint::forDecodeFailure('front', 'GET', '/v1/pets', 'response.body'));
        $enforcer = new ViolationBaselineEnforcer($baseline);

        $this->assertTrue($enforcer->suppressesDecodeFailure('front', 'GET', '/v1/pets', 'response.body'));
        $this->assertSame(1, $enforcer->hitCount());
        $this->assertFalse($enforcer->suppressesDecodeFailure('front', 'POST', '/v1/pets', 'request.body'));
    }

    #[Test]
    public function a_custom_method_decode_failure_is_matched_case_sensitively(): void
    {
        // OpenAPI 3.2 `additionalOperations`: COPY and copy are distinct
        // operations, so a baselined COPY violation must not suppress a new
        // copy violation. Fixed HTTP methods still match case-insensitively.
        $baseline = new ViolationBaseline();
        $baseline->add(ViolationFingerprint::forDecodeFailure('front', 'COPY', '/v1/pets', 'request.body'));
        $baseline->add(ViolationFingerprint::forDecodeFailure('front', 'GET', '/v1/pets', 'response.body'));
        $enforcer = new ViolationBaselineEnforcer($baseline);

        $this->assertFalse($enforcer->suppressesDecodeFailure('front', 'copy', '/v1/pets', 'request.body'));
        $this->assertTrue($enforcer->suppressesDecodeFailure('front', 'COPY', '/v1/pets', 'request.body'));
        $this->assertTrue($enforcer->suppressesDecodeFailure('front', 'get', '/v1/pets', 'response.body'));
    }

    #[Test]
    public function stale_entries_are_the_baseline_entries_never_hit(): void
    {
        $baseline = new ViolationBaseline();
        $hit = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $stale = new ViolationFingerprint('front', 'POST', '/v1/pets', '201', 'application/json', 'response.body', '/name', 'required');
        $baseline->add($hit);
        $baseline->add($stale);
        $enforcer = new ViolationBaselineEnforcer($baseline);

        $result = OpenApiValidationResult::failure(
            ['[/data/0/id] value must be a string'],
            '/v1/pets',
            '200',
            'application/json',
            [new ValidationIssue('response.body', '[/data/0/id] value must be a string', instancePath: '/data/0/id', keyword: 'type', method: 'GET', path: '/v1/pets', statusCode: '200', contentType: 'application/json')],
        );
        $enforcer->suppressesResult('front', $result, 'GET', '/v1/pets');

        $this->assertSame(1, $enforcer->hitCount());
        $this->assertSame([$stale], $enforcer->staleEntries());
    }
}
