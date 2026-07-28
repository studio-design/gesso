<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiValidationOutcome;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\ValidationIssue;

class OpenApiValidationResultTest extends TestCase
{
    #[Test]
    public function success_creates_valid_result(): void
    {
        $result = OpenApiValidationResult::success('/v1/pets');

        $this->assertSame(OpenApiValidationOutcome::Success, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertSame([], $result->errors());
        $this->assertSame('', $result->errorMessage());
        $this->assertSame('/v1/pets', $result->matchedPath());
    }

    #[Test]
    public function success_without_path(): void
    {
        $result = OpenApiValidationResult::success();

        $this->assertSame(OpenApiValidationOutcome::Success, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertNull($result->matchedPath());
    }

    #[Test]
    public function failure_creates_invalid_result(): void
    {
        $errors = ['Error 1', 'Error 2'];
        $result = OpenApiValidationResult::failure($errors);

        $this->assertSame(OpenApiValidationOutcome::Failure, $result->outcome());
        $this->assertFalse($result->isValid());
        $this->assertFalse($result->isSkipped());
        $this->assertSame($errors, $result->errors());
        $this->assertNull($result->matchedPath());
    }

    #[Test]
    public function failure_with_tagged_issues_returns_them(): void
    {
        $issues = [
            new ValidationIssue('request.parameter.query', 'Error 1', method: 'GET', path: '/v1/pets'),
            new ValidationIssue('request.security', 'Error 2', method: 'GET', path: '/v1/pets'),
        ];
        $result = OpenApiValidationResult::failure(['Error 1', 'Error 2'], '/v1/pets', issues: $issues);

        $this->assertSame($issues, $result->issues());
    }

    #[Test]
    public function failure_without_issues_derives_unknown_issues_with_result_context(): void
    {
        $result = OpenApiValidationResult::failure(
            ['Error 1', 'Error 2'],
            '/v1/pets',
            '200',
            'application/json',
        );

        $issues = $result->issues();
        $this->assertCount(2, $issues);
        $this->assertSame('unknown', $issues[0]->category);
        $this->assertSame('Error 1', $issues[0]->message);
        $this->assertSame('/v1/pets', $issues[0]->path);
        $this->assertSame('200', $issues[0]->statusCode);
        $this->assertSame('application/json', $issues[0]->contentType);
        $this->assertNull($issues[0]->method);
        $this->assertSame('Error 2', $issues[1]->message);
    }

    #[Test]
    public function failure_with_mismatched_issue_count_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must mirror');

        OpenApiValidationResult::failure(
            ['Error 1', 'Error 2'],
            issues: [new ValidationIssue('request.spec', 'Error 1')],
        );
    }

    #[Test]
    public function failure_with_mismatched_issue_message_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must mirror');

        OpenApiValidationResult::failure(
            ['Error 1'],
            issues: [new ValidationIssue('request.spec', 'Different message')],
        );
    }

    #[Test]
    public function success_and_skipped_have_no_issues(): void
    {
        $this->assertSame([], OpenApiValidationResult::success('/v1/pets')->issues());
        $this->assertSame([], OpenApiValidationResult::skipped('/v1/pets', 'reason')->issues());
    }

    #[Test]
    public function failure_with_empty_errors_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('failure() requires at least one error message');

        // @phpstan-ignore-next-line argument.type — intentionally empty to verify the runtime guard still fires for consumers without static analysis
        OpenApiValidationResult::failure([]);
    }

    #[Test]
    public function error_message_joins_errors_with_newline(): void
    {
        $result = OpenApiValidationResult::failure(['Error 1', 'Error 2']);

        $this->assertSame("Error 1\nError 2", $result->errorMessage());
    }

    #[Test]
    public function skipped_creates_skipped_result(): void
    {
        $result = OpenApiValidationResult::skipped('/v1/pets', 'status 500 matched skip pattern 5\d\d');

        // isValid() remains true so the assertion surface does not fail the test,
        // but isSkipped() distinguishes the case from a genuine success.
        $this->assertSame(OpenApiValidationOutcome::Skipped, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame([], $result->errors());
        $this->assertSame('', $result->errorMessage());
        $this->assertSame('/v1/pets', $result->matchedPath());
        $this->assertSame('status 500 matched skip pattern 5\d\d', $result->skipReason());
    }

    #[Test]
    public function skipped_without_reason(): void
    {
        $result = OpenApiValidationResult::skipped('/v1/pets');

        $this->assertSame(OpenApiValidationOutcome::Skipped, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertSame('/v1/pets', $result->matchedPath());
        $this->assertNull($result->skipReason());
    }

    #[Test]
    public function skipped_without_matched_path(): void
    {
        $result = OpenApiValidationResult::skipped();

        $this->assertSame(OpenApiValidationOutcome::Skipped, $result->outcome());
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isSkipped());
        $this->assertNull($result->matchedPath());
        $this->assertNull($result->skipReason());
    }

    #[Test]
    public function matched_status_and_content_default_to_null(): void
    {
        // Coverage tracking depends on the absence of these being explicit
        // null rather than missing — pin the defaults across all three factories.
        $success = OpenApiValidationResult::success();
        $failure = OpenApiValidationResult::failure(['err']);
        $skipped = OpenApiValidationResult::skipped();

        foreach ([$success, $failure, $skipped] as $result) {
            $this->assertNull($result->matchedStatusCode());
            $this->assertNull($result->matchedContentType());
        }
    }

    #[Test]
    public function success_propagates_matched_status_and_content(): void
    {
        $result = OpenApiValidationResult::success('/v1/pets', '200', 'application/json');

        $this->assertSame('200', $result->matchedStatusCode());
        $this->assertSame('application/json', $result->matchedContentType());
    }

    #[Test]
    public function failure_propagates_matched_status_and_content(): void
    {
        // Failures still carry matched-status/content when the validator got far
        // enough to pick them — coverage records the (status, contentType) pair
        // even on schema mismatches so partial coverage shows up correctly.
        $result = OpenApiValidationResult::failure(['err'], '/v1/pets', '422', 'application/problem+json');

        $this->assertSame('422', $result->matchedStatusCode());
        $this->assertSame('application/problem+json', $result->matchedContentType());
    }

    #[Test]
    public function skipped_carries_literal_status_and_no_content_type(): void
    {
        // Skip happens before content-type lookup — matchedContentType is always null
        // and matchedStatusCode is the literal HTTP status, not a spec range key.
        $result = OpenApiValidationResult::skipped('/v1/pets', 'status 503 matched 5\d\d', '503');

        $this->assertSame('503', $result->matchedStatusCode());
        $this->assertNull($result->matchedContentType());
    }

    #[Test]
    public function outcome_match_covers_all_three_cases_exhaustively(): void
    {
        $results = [
            OpenApiValidationResult::success(),
            OpenApiValidationResult::failure(['err']),
            OpenApiValidationResult::skipped(reason: 'reason'),
        ];

        $labels = [];
        foreach ($results as $result) {
            $labels[] = match ($result->outcome()) {
                OpenApiValidationOutcome::Success => 'success',
                OpenApiValidationOutcome::Failure => 'failure',
                OpenApiValidationOutcome::Skipped => 'skipped',
            };
        }

        $this->assertSame(['success', 'failure', 'skipped'], $labels);
    }
}
