<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Cli;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Cli\CoverageGateCommand;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_map;
use function file_put_contents;
use function glob;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class CoverageGateCommandTest extends TestCase
{
    private string $workDir;
    private string $stdout = '';
    private string $stderr = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/gesso-coverage-gate-' . uniqid('', true);
        mkdir($this->workDir);
        OpenApiSpecLoader::reset();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->workDir);
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function parses_every_supported_flag(): void
    {
        $this->assertSame(
            [
                'invalid_options' => [],
                'base_spec' => 'base.json',
                'spec' => 'openapi.json',
                'coverage' => 'build/coverage.json',
                'spec_name' => 'front',
                'format' => 'markdown',
            ],
            CoverageGateCommand::parseArgv([
                'coverage:gate',
                '--base-spec=base.json',
                '--spec=openapi.json',
                '--coverage=build/coverage.json',
                '--spec-name=front',
                '--format=markdown',
            ]),
        );
    }

    #[Test]
    public function an_unchanged_spec_reports_no_change_and_exits_zero(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->baseSpec());
        $this->writeCoverage([]);

        $this->assertSame(CoverageGateCommand::EXIT_OK, $this->gate());
        $this->assertSame("[Gesso] No operation changed against the base spec.\n", $this->stdout);
    }

    #[Test]
    public function a_ref_rewrite_that_resolves_to_the_same_tree_is_unchanged(): void
    {
        $spec = $this->baseSpec();
        $spec['components'] = ['schemas' => ['Pet' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]];
        $spec['paths']['/pets/{id}']['put']['responses']['200']['content']['application/json']['schema']
            = ['$ref' => '#/components/schemas/Pet'];

        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $spec);
        $this->writeCoverage([]);

        $this->assertSame(CoverageGateCommand::EXIT_OK, $this->gate());
        $this->assertSame("[Gesso] No operation changed against the base spec.\n", $this->stdout);
    }

    #[Test]
    public function an_uncovered_added_response_fails_the_gate(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->headSpec());
        $this->writeCoverage([
            $this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'validated']]),
            $this->endpoint('DELETE', '/pets/{id}', [['204', '*', 'uncovered']]),
        ]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('[Gesso] 2 operations changed against the base spec:', $this->stdout);
        $this->assertStringContainsString('  PUT /pets/{id}', $this->stdout);
        $this->assertStringContainsString('200 application/json    covered', $this->stdout);
        $this->assertStringContainsString('204 (no content)        UNCOVERED', $this->stdout);
        $this->assertStringContainsString('GET /legacy    removed from the spec (not testable)', $this->stdout);
        $this->assertStringContainsString('1 changed response is not covered by any test.', $this->stdout);
    }

    #[Test]
    public function a_fully_covered_change_passes_the_gate(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->headSpec());
        $this->writeCoverage([
            $this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'validated']]),
            $this->endpoint('DELETE', '/pets/{id}', [['204', '*', 'validated']]),
        ]);

        $this->assertSame(CoverageGateCommand::EXIT_OK, $this->gate());
        $this->assertStringContainsString('All changed responses are covered.', $this->stdout);
    }

    #[Test]
    public function a_changed_request_body_puts_untouched_responses_in_scope(): void
    {
        $spec = $this->baseSpec();
        $spec['paths']['/pets/{id}']['put']['requestBody'] = [
            'content' => ['application/json' => ['schema' => ['type' => 'object']]],
        ];

        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $spec);
        $this->writeCoverage([$this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'uncovered']])]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('200 application/json    UNCOVERED', $this->stdout);
    }

    #[Test]
    public function a_path_level_parameter_change_puts_the_operation_in_scope(): void
    {
        $base = $this->baseSpec();
        $base['paths']['/pets/{id}']['parameters'] = [
            ['name' => 'trace', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
        ];
        $head = $base;
        $head['paths']['/pets/{id}']['parameters'][0]['required'] = true;

        $this->writeSpec('base.json', $base);
        $this->writeSpec('openapi.json', $head);
        $this->writeCoverage([$this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'uncovered']])]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('  PUT /pets/{id}', $this->stdout);
        $this->assertStringContainsString('200 application/json    UNCOVERED', $this->stdout);
    }

    #[Test]
    public function an_overridden_path_level_parameter_change_is_not_a_change(): void
    {
        $base = $this->baseSpec();
        $base['paths']['/pets/{id}']['parameters'] = [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        ];
        // The operation redeclares the same (in, name), so the Path Item entry
        // never reaches the request validator.
        $base['paths']['/pets/{id}']['put']['parameters'] = [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
        ];
        $head = $base;
        $head['paths']['/pets/{id}']['parameters'][0]['schema'] = ['type' => 'number', 'format' => 'double'];

        $this->writeSpec('base.json', $base);
        $this->writeSpec('openapi.json', $head);
        $this->writeCoverage([]);

        // GET /legacy has no parameters at all, so nothing changes anywhere.
        $this->assertSame(CoverageGateCommand::EXIT_OK, $this->gate());
        $this->assertSame("[Gesso] No operation changed against the base spec.\n", $this->stdout);
    }

    #[Test]
    public function a_root_server_change_puts_every_inheriting_operation_in_scope(): void
    {
        $base = $this->baseSpec();
        $base['servers'] = [['url' => 'https://api.example.com/v1']];
        $head = $base;
        $head['servers'][0]['url'] = 'https://api.example.com/v2';

        $this->writeSpec('base.json', $base);
        $this->writeSpec('openapi.json', $head);
        $this->writeCoverage([$this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'uncovered']])]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('  PUT /pets/{id}', $this->stdout);
        $this->assertStringContainsString('  GET /legacy', $this->stdout);
    }

    #[Test]
    public function an_operation_level_servers_override_shields_it_from_a_path_item_change(): void
    {
        $base = $this->baseSpec();
        $base['paths']['/pets/{id}']['servers'] = [['url' => 'https://pets.example.com']];
        // servers is override-not-merge, so the Path Item entry never applies
        // to this operation.
        $base['paths']['/pets/{id}']['put']['servers'] = [['url' => 'https://writes.example.com']];
        $head = $base;
        $head['paths']['/pets/{id}']['servers'][0]['url'] = 'https://pets2.example.com';

        $this->writeSpec('base.json', $base);
        $this->writeSpec('openapi.json', $head);
        $this->writeCoverage([]);

        $this->assertSame(CoverageGateCommand::EXIT_OK, $this->gate());
        $this->assertSame("[Gesso] No operation changed against the base spec.\n", $this->stdout);
    }

    #[Test]
    public function a_security_scheme_definition_change_puts_the_operation_in_scope(): void
    {
        $base = $this->baseSpec();
        $base['security'] = [['ApiKey' => []]];
        $base['components'] = ['securitySchemes' => ['ApiKey' => ['type' => 'apiKey', 'name' => 'X-Key', 'in' => 'header']]];
        $head = $base;
        $head['components']['securitySchemes']['ApiKey']['in'] = 'query';

        $this->writeSpec('base.json', $base);
        $this->writeSpec('openapi.json', $head);
        $this->writeCoverage([$this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'uncovered']])]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('  PUT /pets/{id}', $this->stdout);
    }

    #[Test]
    public function an_operation_level_security_override_shields_it_from_a_root_change(): void
    {
        $base = $this->baseSpec();
        $base['security'] = [['ApiKey' => []]];
        $base['components'] = ['securitySchemes' => [
            'ApiKey' => ['type' => 'apiKey', 'name' => 'X-Key', 'in' => 'header'],
            'Basic' => ['type' => 'http', 'scheme' => 'basic'],
        ]];
        $base['paths']['/pets/{id}']['put']['security'] = [['Basic' => []]];
        $head = $base;
        $head['components']['securitySchemes']['ApiKey']['in'] = 'query';

        $this->writeSpec('base.json', $base);
        $this->writeSpec('openapi.json', $head);
        $this->writeCoverage([]);

        // Only GET /legacy inherits the root requirement, so PUT stays out.
        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('  GET /legacy', $this->stdout);
        $this->assertStringNotContainsString('  PUT /pets/{id}', $this->stdout);
    }

    #[Test]
    public function methods_the_coverage_tracker_never_records_are_out_of_scope(): void
    {
        $head = $this->baseSpec();
        // HEAD / OPTIONS / TRACE never reach a coverage document, so gating
        // them would demand coverage no report can show.
        $head['paths']['/pets/{id}']['head'] = ['responses' => ['200' => ['description' => 'ok']]];
        $head['paths']['/pets/{id}']['options'] = ['responses' => ['204' => ['description' => 'no content']]];

        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $head);
        $this->writeCoverage([]);

        $this->assertSame(CoverageGateCommand::EXIT_OK, $this->gate());
        $this->assertSame("[Gesso] No operation changed against the base spec.\n", $this->stdout);
    }

    #[Test]
    public function a_deleted_response_is_reported_but_does_not_fail_the_gate(): void
    {
        $base = $this->baseSpec();
        $base['paths']['/pets/{id}']['put']['responses']['404'] = [
            'description' => 'missing',
            'content' => ['application/json' => ['schema' => ['type' => 'object']]],
        ];

        $this->writeSpec('base.json', $base);
        $this->writeSpec('openapi.json', $this->baseSpec());
        $this->writeCoverage([]);

        $this->assertSame(CoverageGateCommand::EXIT_OK, $this->gate());
        $this->assertStringContainsString('[Gesso] 1 operation changed against the base spec:', $this->stdout);
        $this->assertStringContainsString('404 application/json    removed (not testable)', $this->stdout);
    }

    #[Test]
    public function a_response_the_coverage_document_never_saw_counts_as_uncovered(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->headSpec());
        $this->writeCoverage([$this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'validated']])]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('204 (no content)        UNCOVERED', $this->stdout);
    }

    #[Test]
    public function a_skipped_response_does_not_satisfy_the_gate(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->headSpec());
        $this->writeCoverage([
            $this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'validated']]),
            $this->endpoint('DELETE', '/pets/{id}', [['204', '*', 'skipped']]),
        ]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate());
        $this->assertStringContainsString('204 (no content)        SKIPPED', $this->stdout);
    }

    #[Test]
    public function markdown_format_renders_a_step_summary_table(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->headSpec());
        $this->writeCoverage([
            $this->endpoint('PUT', '/pets/{id}', [['200', 'application/json', 'validated']]),
            $this->endpoint('DELETE', '/pets/{id}', [['204', '*', 'uncovered']]),
        ]);

        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate(format: 'markdown'));
        $this->assertStringContainsString('### Gesso spec patch coverage', $this->stdout);
        $this->assertStringContainsString('**1 changed response is not covered by any test.**', $this->stdout);
        $this->assertStringContainsString('| Operation | Change | Response | Coverage |', $this->stdout);
        $this->assertStringContainsString('| `DELETE /pets/{id}` | added | `204 (no content)` | UNCOVERED |', $this->stdout);
        $this->assertStringContainsString('| `GET /legacy` | removed | — | — |', $this->stdout);
    }

    #[Test]
    public function a_missing_required_flag_is_a_usage_error(): void
    {
        $this->assertSame(
            CoverageGateCommand::EXIT_USAGE,
            $this->command()->run(['base_spec' => 'base.json', 'invalid_options' => []]),
        );
        $this->assertStringContainsString('--spec is required.', $this->stderr);
    }

    #[Test]
    public function an_unknown_spec_name_lists_the_available_ones(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->headSpec());
        $this->writeCoverage([], specName: 'front');

        $this->assertSame(CoverageGateCommand::EXIT_USAGE, $this->gate());
        $this->assertStringContainsString('has no spec named "openapi". Available: front.', $this->stderr);
        // --spec-name selects the coverage key; the gate then runs normally
        // (and here fails, because that spec recorded nothing).
        $this->assertSame(CoverageGateCommand::EXIT_UNCOVERED_CHANGE, $this->gate(specName: 'front'));
        $this->assertSame('', $this->stderr);
    }

    #[Test]
    public function an_unsupported_coverage_schema_version_is_a_usage_error(): void
    {
        $this->writeSpec('base.json', $this->baseSpec());
        $this->writeSpec('openapi.json', $this->headSpec());
        file_put_contents(
            $this->workDir . '/coverage.json',
            json_encode(['schema_version' => 2, 'specs' => []], JSON_THROW_ON_ERROR),
        );

        $this->assertSame(CoverageGateCommand::EXIT_USAGE, $this->gate());
        $this->assertStringContainsString('Unsupported coverage schema_version', $this->stderr);
    }

    #[Test]
    public function an_unreadable_spec_is_a_usage_error(): void
    {
        $this->writeSpec('openapi.json', $this->headSpec());
        $this->writeCoverage([]);

        $this->assertSame(CoverageGateCommand::EXIT_USAGE, $this->gate());
        $this->assertStringContainsString('Spec is not a readable file', $this->stderr);
    }

    private function gate(string $format = 'text', ?string $specName = null): int
    {
        $this->stdout = '';
        $this->stderr = '';

        $options = [
            'base_spec' => $this->workDir . '/base.json',
            'spec' => $this->workDir . '/openapi.json',
            'coverage' => $this->workDir . '/coverage.json',
            'format' => $format,
            'invalid_options' => [],
        ];
        if ($specName !== null) {
            $options['spec_name'] = $specName;
        }

        return $this->command()->run($options);
    }

    private function command(): CoverageGateCommand
    {
        return new CoverageGateCommand(
            function (string $message): void {
                $this->stdout .= $message;
            },
            function (string $message): void {
                $this->stderr .= $message;
            },
        );
    }

    /** @param array<string, mixed> $spec */
    private function writeSpec(string $file, array $spec): void
    {
        file_put_contents($this->workDir . '/' . $file, json_encode($spec, JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $endpoints */
    private function writeCoverage(array $endpoints, string $specName = 'openapi'): void
    {
        file_put_contents($this->workDir . '/coverage.json', json_encode([
            'schema_version' => 3,
            'specs' => [$specName => ['endpoints' => $endpoints]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $responses
     *
     * @return array<string, mixed>
     */
    private function endpoint(string $method, string $path, array $responses): array
    {
        return [
            'endpoint' => $method . ' ' . $path,
            'method' => $method,
            'path' => $path,
            'responses' => array_map(static fn(array $row): array => [
                'status_key' => $row[0],
                'content_type_key' => $row[1],
                'response_state' => $row[2],
            ], $responses),
        ];
    }

    /** @return array<string, mixed> */
    private function baseSpec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'Pets', 'version' => '1.0.0'],
            'paths' => [
                '/pets/{id}' => [
                    'put' => [
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                        'responses' => [
                            '200' => [
                                'description' => 'ok',
                                'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]],
                            ],
                        ],
                    ],
                ],
                '/legacy' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function headSpec(): array
    {
        $spec = $this->baseSpec();
        unset($spec['paths']['/legacy']);
        $spec['paths']['/pets/{id}']['put']['responses']['200']['content']['application/json']['schema']['properties']['name']
            = ['type' => 'string'];
        $spec['paths']['/pets/{id}']['delete'] = [
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
            'responses' => ['204' => ['description' => 'deleted']],
        ];

        return $spec;
    }
}
