<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Cli;

use const JSON_THROW_ON_ERROR;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Cli\DoctorCommand;
use Studio\OpenApiContractTesting\Tests\Helpers\FakeHttpClient;

use function array_column;
use function file_put_contents;
use function glob;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class DoctorCommandTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/openapi-doctor-' . uniqid('', true);
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->workDir);
        parent::tearDown();
    }

    #[Test]
    public function parses_repeatable_specs_and_prefixes(): void
    {
        $this->assertSame(
            [
                'specs' => ['front.json', 'admin.yaml'],
                'strip_prefixes' => ['/api', '/internal'],
                'invalid_options' => [],
                'format' => 'json',
                'allow_remote_refs' => true,
                'phpunit_snippet' => true,
            ],
            DoctorCommand::parseArgv([
                'doctor',
                '--spec=front.json,admin.yaml',
                '--strip-prefix=/api',
                '--strip-prefix=/internal',
                '--format=json',
                '--allow-remote-refs',
                '--phpunit-snippet',
            ]),
        );
    }

    #[Test]
    public function reports_versioned_json_counts_for_multiple_specs(): void
    {
        $first = $this->writeSpec('front.json', $this->validSpec('/pets'));
        $second = $this->writeSpec('admin.json', $this->validSpec('/users'));
        $output = '';
        $command = new DoctorCommand(stdoutWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $exit = $command->run([
            'specs' => [$first, $second],
            'strip_prefixes' => ['/api'],
            'format' => 'json',
            'phpunit_snippet' => true,
        ]);

        $this->assertSame(DoctorCommand::EXIT_OK, $exit);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(DoctorCommand::JSON_SCHEMA_VERSION, $report['schemaVersion']);
        $this->assertSame('ok', $report['status']);
        $this->assertSame(2, $report['summary']['specs']);
        $this->assertSame(2, $report['summary']['operations']);
        $this->assertSame(2, $report['summary']['responses']);
        $this->assertStringContainsString('spec_base_path', $report['phpunit']);
        $this->assertStringContainsString('front,admin', $report['phpunit']);
    }

    #[Test]
    public function malformed_and_unresolved_specs_exit_non_zero_with_stable_categories(): void
    {
        $malformed = $this->writeSpec('malformed.json', '{nope');
        $unresolved = $this->writeSpec('unresolved.json', (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Missing' => ['$ref' => './missing.json']]],
        ], JSON_THROW_ON_ERROR));

        foreach ([[$malformed, 'parser'], [$unresolved, 'references']] as [$path, $category]) {
            $output = '';
            $command = new DoctorCommand(stdoutWriter: static function (string $message) use (&$output): void {
                $output .= $message;
            });
            $exit = $command->run(['specs' => [$path], 'format' => 'json']);
            $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(DoctorCommand::EXIT_DIAGNOSTIC_FAILURE, $exit);
            $this->assertSame('error', $report['status']);
            $this->assertSame($category, $report['issues'][0]['category']);
        }
    }

    #[Test]
    public function resolves_http_refs_with_injected_psr_transport(): void
    {
        $url = 'https://example.com/pet.json';
        $root = $this->writeSpec('remote.json', (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => ['/pets' => ['get' => ['responses' => ['200' => [
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => ['$ref' => $url]]],
            ]]]]],
        ], JSON_THROW_ON_ERROR));
        $client = new FakeHttpClient([$url => FakeHttpClient::jsonResponse('{"type":"object"}')]);
        $output = '';
        $command = new DoctorCommand(
            stdoutWriter: static function (string $message) use (&$output): void {
                $output .= $message;
            },
            remoteTransportFactory: static fn(): array => [$client, new HttpFactory()],
        );

        $exit = $command->run(['specs' => [$root], 'format' => 'json', 'allow_remote_refs' => true]);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(DoctorCommand::EXIT_OK, $exit);
        $this->assertSame('ok', $report['status']);
        $this->assertSame([$url], $client->sentUrls());
    }

    #[Test]
    public function fails_when_runtime_extension_priority_would_shadow_requested_spec(): void
    {
        $this->writeSpec('root.json', $this->validSpec('/json'));
        $yaml = $this->writeSpec('root.yaml', "openapi: 3.1.0\ninfo: {title: Test, version: '1'}\npaths: {}\n");
        $output = '';
        $command = new DoctorCommand(stdoutWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $exit = $command->run(['specs' => [$yaml], 'format' => 'json']);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(DoctorCommand::EXIT_DIAGNOSTIC_FAILURE, $exit);
        $this->assertSame('configuration', $report['issues'][0]['category']);
        $this->assertStringContainsString('selects', $report['issues'][0]['message']);
    }

    #[Test]
    public function separates_warning_and_skipped_feature_diagnostics(): void
    {
        $spec = $this->writeSpec('features.json', (string) json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => ['/pets' => ['get' => ['responses' => ['200' => [
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'unevaluatedProperties' => false,
                ]]],
            ]]]]],
            'components' => ['securitySchemes' => ['oauth' => ['type' => 'oauth2']]],
        ], JSON_THROW_ON_ERROR));
        $output = '';
        $command = new DoctorCommand(stdoutWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });

        $exit = $command->run(['specs' => [$spec], 'format' => 'json']);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(DoctorCommand::EXIT_OK, $exit);
        $this->assertSame('warning', $report['status']);
        $this->assertSame(1, $report['summary']['warnings']);
        $this->assertSame(1, $report['summary']['skipped']);
        $this->assertSame(['skipped', 'warning'], array_column($report['issues'], 'severity'));
    }

    #[Test]
    public function rejects_malformed_response_objects_instead_of_counting_them(): void
    {
        $spec = $this->writeSpec('response-null.json', $this->specWithResponse(null));
        $report = $this->runJsonDoctor($spec, $exit);

        $this->assertSame(DoctorCommand::EXIT_DIAGNOSTIC_FAILURE, $exit);
        $this->assertSame('error', $report['status']);
        $this->assertSame(0, $report['summary']['responses']);
        $this->assertSame('structure', $report['issues'][0]['category']);
        $this->assertStringContainsString('responses[200]', $report['issues'][0]['message']);
        $this->assertStringContainsString('got null', $report['issues'][0]['message']);
    }

    #[Test]
    public function rejects_nested_response_nodes_using_runtime_malformed_node_rules(): void
    {
        $cases = [
            ['content' => null],
            ['content' => ['application/json' => null]],
            ['content' => ['application/json' => ['schema' => null]]],
            ['content' => ['application/json' => ['schema' => [['type' => 'string']]]]],
            ['content' => ['application/json' => ['itemSchema' => 'string']]],
        ];

        foreach ($cases as $index => $response) {
            $spec = $this->writeSpec("response-malformed-{$index}.json", $this->specWithResponse($response));
            $report = $this->runJsonDoctor($spec, $exit);

            $this->assertSame(DoctorCommand::EXIT_DIAGNOSTIC_FAILURE, $exit, "case {$index}");
            $this->assertSame(0, $report['summary']['responses'], "case {$index}");
            $this->assertSame('structure', $report['issues'][0]['category'], "case {$index}");
        }
    }

    #[Test]
    public function rejects_malformed_discriminator_in_response_schema_with_default_enforcement(): void
    {
        $spec = $this->writeSpec('response-discriminator.json', (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => ['/pets' => ['get' => ['responses' => ['200' => [
                'description' => 'ok',
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'discriminator' => ['propertyName' => 'type', 'mapping' => 'not-an-object'],
                ]]],
            ]]]]],
        ], JSON_THROW_ON_ERROR));
        $report = $this->runJsonDoctor($spec, $exit);

        $this->assertSame(DoctorCommand::EXIT_DIAGNOSTIC_FAILURE, $exit);
        $this->assertSame('error', $report['status']);
        $this->assertSame('structure', $report['issues'][0]['category']);
        $this->assertStringContainsString('discriminator.mapping', $report['issues'][0]['message']);
    }

    #[Test]
    public function rejects_malformed_discriminator_in_component_schema_with_default_enforcement(): void
    {
        $spec = $this->writeSpec('component-discriminator.json', (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => ['Pet' => [
                'type' => 'object',
                'discriminator' => ['propertyName' => 'type', 'mapping' => 'not-an-object'],
            ]]],
        ], JSON_THROW_ON_ERROR));
        $report = $this->runJsonDoctor($spec, $exit);

        $this->assertSame(DoctorCommand::EXIT_DIAGNOSTIC_FAILURE, $exit);
        $this->assertSame('error', $report['status']);
        $this->assertSame('structure', $report['issues'][0]['category']);
        $this->assertStringContainsString('discriminator.mapping', $report['issues'][0]['message']);
    }

    private function writeSpec(string $name, string $contents): string
    {
        $path = $this->workDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function validSpec(string $path): string
    {
        return (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => [$path => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
        ], JSON_THROW_ON_ERROR);
    }

    private function specWithResponse(mixed $response): string
    {
        return (string) json_encode([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => ['/pets' => ['get' => ['responses' => ['200' => $response]]]],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param-out int $exit
     *
     * @return array<string, mixed>
     */
    private function runJsonDoctor(string $spec, ?int &$exit): array
    {
        $output = '';
        $command = new DoctorCommand(stdoutWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });
        $exit = $command->run(['specs' => [$spec], 'format' => 'json']);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
