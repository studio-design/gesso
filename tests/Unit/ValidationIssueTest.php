<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\ValidationIssue;

final class ValidationIssueTest extends TestCase
{
    #[Test]
    public function exposes_all_constructor_properties(): void
    {
        $issue = new ValidationIssue(
            'response.body',
            'Response body does not match the schema',
            instancePath: '/data/0/name',
            keyword: 'type',
            method: 'GET',
            path: '/v1/pets/{petId}',
            statusCode: '200',
            contentType: 'application/json',
        );

        $this->assertSame('response.body', $issue->category);
        $this->assertSame('Response body does not match the schema', $issue->message);
        $this->assertSame('/data/0/name', $issue->instancePath);
        $this->assertSame('type', $issue->keyword);
        $this->assertSame('GET', $issue->method);
        $this->assertSame('/v1/pets/{petId}', $issue->path);
        $this->assertSame('200', $issue->statusCode);
        $this->assertSame('application/json', $issue->contentType);
    }

    #[Test]
    public function context_fields_default_to_null(): void
    {
        $issue = new ValidationIssue('request.security', 'Authorization header is missing');

        $this->assertNull($issue->instancePath);
        $this->assertNull($issue->keyword);
        $this->assertNull($issue->method);
        $this->assertNull($issue->path);
        $this->assertNull($issue->statusCode);
        $this->assertNull($issue->contentType);
    }
}
