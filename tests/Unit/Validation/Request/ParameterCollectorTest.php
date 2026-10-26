<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Request\ParameterCollector;

class ParameterCollectorTest extends TestCase
{
    #[Test]
    public function collect_merges_path_and_operation_parameters(): void
    {
        $pathSpec = [
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
            ],
        ];
        $operation = [
            'parameters' => [
                ['name' => 'format', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
        ];

        $result = ParameterCollector::collect('GET', '/pets/{id}', $pathSpec, $operation);

        $this->assertSame([], $result->specErrors);
        $this->assertCount(2, $result->parameters);
        $this->assertSame('id', $result->parameters[0]['name']);
        $this->assertSame('format', $result->parameters[1]['name']);
    }

    #[Test]
    public function collect_has_operation_level_override_path_level_on_same_name_and_in(): void
    {
        $pathSpec = [
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 10]],
            ],
        ];
        $operation = [
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'maximum' => 100]],
            ],
        ];

        $result = ParameterCollector::collect('GET', '/pets', $pathSpec, $operation);

        $this->assertSame([], $result->specErrors);
        $this->assertCount(1, $result->parameters);
        $this->assertSame(100, $result->parameters[0]['schema']['maximum']);
    }

    #[Test]
    public function collect_flags_malformed_scalar_parameter_entry(): void
    {
        $result = ParameterCollector::collect('GET', '/pets', [], ['parameters' => ['oops']]);

        $this->assertSame([], $result->parameters);
        $this->assertCount(1, $result->specErrors);
        $this->assertStringContainsString('expected object, got scalar', $result->specErrors[0]);
    }

    #[Test]
    public function collect_ignores_reserved_in_header_names_case_insensitively(): void
    {
        // OAS 3.0/3.1/3.2: these in:header definitions "SHALL be ignored" — the
        // entry is dropped without a spec error, whatever its casing, level, or
        // (even malformed) schema.
        $pathSpec = [
            'parameters' => [
                ['name' => 'authorization', 'in' => 'header', 'required' => true, 'schema' => 'not-a-schema'],
            ],
        ];
        $operation = [
            'parameters' => [
                ['name' => 'Content-Type', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'ACCEPT', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'integer']],
                ['name' => 'X-Request-ID', 'in' => 'header', 'schema' => ['type' => 'string']],
            ],
        ];

        $result = ParameterCollector::collect('POST', '/pets', $pathSpec, $operation);

        $this->assertSame([], $result->specErrors);
        $this->assertSame([$operation['parameters'][2]], $result->parameters);
    }

    #[Test]
    public function collect_still_rejects_reserved_name_with_malformed_in(): void
    {
        // Only a well-formed in:header entry is "ignored"; a non-string `in`
        // stays a loud malformed-entry error.
        $result = ParameterCollector::collect('GET', '/pets', [], [
            'parameters' => [['name' => 'Authorization', 'in' => ['header']]],
        ]);

        $this->assertSame([], $result->parameters);
        $this->assertCount(1, $result->specErrors);
        $this->assertStringContainsString('Malformed parameter entry', $result->specErrors[0]);
    }

    #[Test]
    public function collect_does_not_treat_reserved_name_as_reserved_when_not_header(): void
    {
        // A `in: query, name: accept` is syntactically odd but not governed by
        // §4.7.12.1, so it must pass through rather than being silently dropped.
        $operation = [
            'parameters' => [
                ['name' => 'accept', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
        ];

        $result = ParameterCollector::collect('GET', '/pets', [], $operation);

        $this->assertSame([], $result->specErrors);
        $this->assertCount(1, $result->parameters);
    }

    #[Test]
    public function collect_rejects_query_and_querystring_mix(): void
    {
        $result = ParameterCollector::collect('GET', '/pets', [], [
            'parameters' => [
                ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']],
                ['name' => 'all', 'in' => 'querystring', 'content' => []],
            ],
        ]);

        $this->assertCount(1, $result->specErrors);
        $this->assertStringContainsString('forbids mixing', $result->specErrors[0]);
    }

    #[Test]
    public function collect_rejects_multiple_querystring_parameters(): void
    {
        $result = ParameterCollector::collect('GET', '/pets', [], [
            'parameters' => [
                ['name' => 'one', 'in' => 'querystring', 'content' => []],
                ['name' => 'two', 'in' => 'querystring', 'content' => []],
            ],
        ]);

        $this->assertCount(1, $result->specErrors);
        $this->assertStringContainsString('at most one', $result->specErrors[0]);
    }
}
