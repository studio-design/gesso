<?php

declare(strict_types=1);

namespace Examples\Psr7\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\Fuzz\OpenApiSpecExplorer;
use Studio\OpenApiContractTesting\Psr7\OpenApiAssertions;

use function json_encode;

#[OpenApiSpec('petstore')]
final class PetContractTest extends TestCase
{
    use OpenApiAssertions;

    #[Test]
    public function validates_a_psr7_exchange(): void
    {
        $request = new Request(
            'POST',
            'https://example.test/pets',
            ['Content-Type' => 'application/json'],
            '{"name":"Fido"}',
        );
        $response = new Response(
            201,
            ['Content-Type' => 'application/json'],
            '{"id":1,"name":"Fido"}',
        );

        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);
    }

    #[Test]
    public function explores_the_whole_spec_without_a_framework_adapter(): void
    {
        $summary = OpenApiSpecExplorer::explore('petstore', casesPerOperation: 2, seed: 7)
            ->dispatchUsing(static function (ExploredCase $case): array {
                $name = $case->body['name'];

                return [
                    new Request(
                        $case->method->value,
                        'https://example.test' . $case->matchedPath,
                        ['Content-Type' => 'application/json'],
                        (string) json_encode($case->body),
                    ),
                    new Response(
                        201,
                        ['Content-Type' => 'application/json'],
                        (string) json_encode(['id' => 1, 'name' => $name]),
                    ),
                ];
            })
            ->assertResponseUsing(function (mixed $exchange): void {
                $this->assertIsArray($exchange);
                $this->assertPsr7ExchangeMatchesOpenApiSchema($exchange[0], $exchange[1]);
            })
            ->assertResponses();

        $this->assertSame(1, $summary->executedOperations);
        $this->assertSame(2, $summary->executedCases);
    }
}
