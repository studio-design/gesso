<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\ContractCheckFailure;
use Studio\Gesso\Fuzz\ContractCheckSkip;
use Studio\Gesso\Fuzz\ContractCheckSummary;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\HttpMethod;

class ContractCheckSummaryTest extends TestCase
{
    #[Test]
    public function check_names_match_schemathesis(): void
    {
        $this->assertSame('unsupported_method', ContractCheck::UnsupportedMethod->value);
        $this->assertSame([405], ContractCheck::UnsupportedMethod->defaultExpectedStatuses());
    }

    #[Test]
    public function failure_describe_names_check_operation_and_mutation(): void
    {
        $failure = new ContractCheckFailure(
            ContractCheck::UnsupportedMethod,
            'PATCH',
            '/v1/pets/{petId}',
            null,
            [405],
            200,
            new ExploredCase(null, [], [], ['petId' => 7], HttpMethod::PATCH, '/v1/pets/{petId}'),
        );

        $described = $failure->describe();
        $this->assertStringContainsString('unsupported_method: PATCH /v1/pets/{petId}', $described);
        $this->assertStringContainsString('expected 405, got 200', $described);
        $this->assertStringContainsString('curl -X PATCH', $described);
    }

    #[Test]
    public function summary_reports_and_describes_failures(): void
    {
        $failure = new ContractCheckFailure(
            ContractCheck::UnsupportedMethod,
            'DELETE',
            '/pets',
            null,
            [405, 404],
            204,
            new ExploredCase(null, [], [], [], HttpMethod::DELETE, '/pets'),
        );
        $skip = new ContractCheckSkip(ContractCheck::UnsupportedMethod, '/full', null, 'documented');

        $summary = new ContractCheckSummary(2, 2, [$failure], [$skip]);

        $this->assertTrue($summary->hasFailures());
        $this->assertTrue($summary->hasSkips());
        $this->assertStringContainsString('expected 405 or 404, got 204', $summary->describeFailures());

        $empty = new ContractCheckSummary(1, 1, [], []);
        $this->assertFalse($empty->hasFailures());
        $this->assertFalse($empty->hasSkips());
        $this->assertSame('', $empty->describeFailures());
    }
}
