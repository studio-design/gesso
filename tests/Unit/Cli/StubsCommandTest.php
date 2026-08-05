<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Cli;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Cli\StubsCommand;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Stubs\StubRenderer;

use function array_map;
use function escapeshellarg;
use function exec;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function scandir;
use function sort;
use function str_contains;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class StubsCommandTest extends TestCase
{
    private string $workDir;
    private string $stdout = '';
    private string $stderr = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . '/gesso-stubs-' . uniqid('', true);
        mkdir($this->workDir);
        OpenApiSpecLoader::reset();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workDir);
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function provideEvery_adapter_generates_syntactically_valid_phpCases(): iterable
    {
        foreach (StubRenderer::ADAPTERS as $adapter) {
            yield $adapter => [$adapter];
        }
    }

    #[Test]
    public function parses_every_supported_flag(): void
    {
        $this->assertSame(
            [
                'invalid_options' => [],
                'spec' => 'openapi.json',
                'coverage' => 'build/coverage.json',
                'spec_name' => 'front',
                'adapter' => 'laravel',
                'output' => 'tests/Contract',
                'namespace' => 'Tests\Contract',
                'base_class' => 'Tests\TestCase',
                'dry_run' => true,
            ],
            StubsCommand::parseArgv([
                'stubs',
                '--spec=openapi.json',
                '--coverage=build/coverage.json',
                '--spec-name=front',
                '--adapter=laravel',
                '--output=tests/Contract',
                '--namespace=Tests\Contract',
                '--base-class=Tests\TestCase',
                '--dry-run',
            ]),
        );
    }

    #[Test]
    public function rejects_unknown_arguments_and_adapters(): void
    {
        $this->assertSame(
            StubsCommand::EXIT_USAGE,
            $this->command()->run(StubsCommand::parseArgv(['--spec=x.json', '--nope'])),
        );
        $this->assertStringContainsString('--nope', $this->stderr);

        $this->stderr = '';
        $this->assertSame(
            StubsCommand::EXIT_USAGE,
            $this->command()->run(StubsCommand::parseArgv(['--spec=x.json', '--adapter=codeception'])),
        );
        $this->assertStringContainsString('Unsupported --adapter=codeception', $this->stderr);
    }

    #[Test]
    public function requires_a_spec(): void
    {
        $this->assertSame(StubsCommand::EXIT_USAGE, $this->command()->run(StubsCommand::parseArgv([])));
        $this->assertStringContainsString('--spec is required', $this->stderr);
    }

    #[Test]
    public function reports_an_unreadable_spec(): void
    {
        $this->assertSame(
            StubsCommand::EXIT_USAGE,
            $this->command()->run(StubsCommand::parseArgv(['--spec=' . $this->workDir . '/missing.json'])),
        );
        $this->assertStringContainsString('Spec is not a readable file', $this->stderr);
    }

    #[Test]
    public function stubs_only_the_responses_the_coverage_document_does_not_report_as_validated(): void
    {
        $spec = $this->writeSpec();
        $coverage = $this->writeCoverage([
            'GET /pets' => [['200', 'application/json', 'validated']],
            'POST /pets' => [['201', 'application/json', 'uncovered']],
        ]);

        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--coverage=' . $coverage,
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(StubsCommand::EXIT_OK, $exit);
        // GET /pets is fully validated, so only POST /pets is left to write.
        $this->assertSame(['PostPetsTest.php'], $this->generatedFiles());
        $this->assertStringContainsString('1 file covering 1 uncovered response', $this->stdout);
    }

    #[Test]
    public function a_skipped_response_still_gets_a_stub(): void
    {
        $spec = $this->writeSpec();
        $coverage = $this->writeCoverage([
            'GET /pets' => [['200', 'application/json', 'skipped']],
            'POST /pets' => [['201', 'application/json', 'validated']],
        ]);

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--coverage=' . $coverage,
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(['GetPetsTest.php'], $this->generatedFiles());
    }

    #[Test]
    public function without_a_coverage_document_the_whole_spec_is_stubbed(): void
    {
        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $this->writeSpec(),
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(['GetPetsTest.php', 'PostPetsTest.php'], $this->generatedFiles());
    }

    #[Test]
    public function reports_nothing_to_stub_when_every_response_is_validated(): void
    {
        $spec = $this->writeSpec();
        $coverage = $this->writeCoverage([
            'GET /pets' => [['200', 'application/json', 'validated']],
            'POST /pets' => [['201', 'application/json', 'validated']],
        ]);

        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--coverage=' . $coverage,
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(StubsCommand::EXIT_OK, $exit);
        $this->assertStringContainsString('nothing to stub', $this->stdout);
        $this->assertDirectoryDoesNotExist($this->workDir . '/out');
    }

    #[Test]
    public function never_overwrites_an_existing_file(): void
    {
        $spec = $this->writeSpec();
        mkdir($this->workDir . '/out');
        file_put_contents($this->workDir . '/out/GetPetsTest.php', '<?php // hand-written');

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(
            '<?php // hand-written',
            file_get_contents($this->workDir . '/out/GetPetsTest.php'),
        );
        $this->assertStringContainsString('already exists and was left untouched', $this->stdout);
        $this->assertStringContainsString('PostPetsTest.php', $this->stdout);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $this->writeSpec(),
            '--output=' . $this->workDir . '/out',
            '--dry-run',
        ]));

        $this->assertSame(StubsCommand::EXIT_OK, $exit);
        $this->assertStringContainsString('Would write 2 files', $this->stdout);
        $this->assertDirectoryDoesNotExist($this->workDir . '/out');
    }

    #[Test]
    public function output_is_byte_identical_across_runs(): void
    {
        $spec = $this->writeSpec();

        $this->command()->run(StubsCommand::parseArgv(['--spec=' . $spec, '--output=' . $this->workDir . '/a']));
        $this->command()->run(StubsCommand::parseArgv(['--spec=' . $spec, '--output=' . $this->workDir . '/b']));

        foreach (['GetPetsTest.php', 'PostPetsTest.php'] as $file) {
            $this->assertSame(
                file_get_contents($this->workDir . '/a/' . $file),
                file_get_contents($this->workDir . '/b/' . $file),
            );
        }
    }

    #[Test]
    #[DataProvider('provideEvery_adapter_generates_syntactically_valid_phpCases')]
    public function every_adapter_generates_syntactically_valid_php(string $adapter): void
    {
        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $this->writeSpec(),
            '--adapter=' . $adapter,
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(StubsCommand::EXIT_OK, $exit);
        $files = $this->generatedFiles();
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $output = [];
            $status = 0;
            exec(
                escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->workDir . '/out/' . $file) . ' 2>&1',
                $output,
                $status,
            );
            $this->assertSame(0, $status, implode("\n", $output));
        }
    }

    #[Test]
    public function paths_that_normalize_to_the_same_class_name_both_get_a_file(): void
    {
        // `/foo-bar` and `/foo/bar` both studly-case to GetFooBarTest; without
        // disambiguation the second would be reported as an existing file and
        // its uncovered responses would be lost.
        $spec = $this->writeInlineSpec('collide', [
            '/foo-bar' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]]]],
            '/foo/bar' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]]]],
        ]);

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $files = $this->generatedFiles();
        $this->assertCount(2, $files);
        $this->assertStringNotContainsString('already exists', $this->stdout);

        // Each colliding name carries a digest of its own endpoint, so it does
        // not shift when an unrelated operation joins or leaves the spec.
        $this->assertSame($files, $this->rerunClassNamesFor($spec));
    }

    #[Test]
    public function each_response_media_type_asks_for_its_own_content_type(): void
    {
        $spec = $this->writeInlineSpec('negotiated', ['/pets' => ['get' => ['responses' => ['200' => ['content' => [
            'application/json' => ['schema' => ['type' => 'object']],
            'application/xml' => ['schema' => ['type' => 'object']],
        ]]]]]]);

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--adapter=laravel',
            '--output=' . $this->workDir . '/out',
        ]));

        // getJson() pins Accept: application/json, so without an explicit
        // Accept both tests would resolve to the same response.
        $code = (string) file_get_contents($this->workDir . '/out/GetPetsTest.php');
        $this->assertStringContainsString("'Accept' => 'application/json',", $code);
        $this->assertStringContainsString("'Accept' => 'application/xml',", $code);
    }

    #[Test]
    public function a_range_key_avoids_the_status_an_exact_key_already_claims(): void
    {
        $spec = $this->writeInlineSpec('ranges', ['/pets' => ['get' => ['responses' => [
            '400' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            '4XX' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            'default' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        ]]]]);

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $code = (string) file_get_contents($this->workDir . '/out/GetPetsTest.php');
        // The resolver prefers exact over range over default, so 4XX must not
        // reuse 400 and default must avoid the whole 4xx class.
        $this->assertStringContainsString('The spec declares `4XX`; this stub exercises 401.', $code);
        $this->assertStringContainsString('The spec declares `default`; this stub exercises 100.', $code);
        $this->assertSame(1, substr_count($code, "            400,\n"));
    }

    #[Test]
    public function a_response_key_no_status_can_reach_is_reported_instead_of_stubbed(): void
    {
        $responses = ['4XX' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]];
        for ($status = 400; $status <= 499; $status++) {
            $responses[(string) $status] = ['content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        }
        $spec = $this->writeInlineSpec('exhausted', ['/pets' => ['get' => ['responses' => $responses]]]);

        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(StubsCommand::EXIT_OK, $exit);
        $this->assertStringContainsString('1 declared response was not stubbed', $this->stdout);
        $this->assertStringContainsString('GET /pets  4XX application/json', $this->stdout);
        $this->assertStringNotContainsString('4XX', (string) file_get_contents($this->workDir . '/out/GetPetsTest.php'));
    }

    #[Test]
    public function a_non_array_or_non_json_request_body_uses_the_raw_call(): void
    {
        $spec = $this->writeInlineSpec('rawbody', ['/pets' => ['post' => [
            'requestBody' => ['content' => ['application/xml' => [
                'schema' => ['type' => 'string'],
                'example' => '<pet name="Fido"/>',
            ]]],
            'responses' => ['201' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
        ]]]);

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--adapter=laravel',
            '--output=' . $this->workDir . '/out',
        ]));

        $code = (string) file_get_contents($this->workDir . '/out/PostPetsTest.php');
        // postJson() would json_encode a scalar and send it as application/json.
        $this->assertStringNotContainsString('postJson', $code);
        $this->assertStringContainsString('$this->call(', $code);
        $this->assertStringContainsString("'Content-Type' => 'application/xml',", $code);
        $this->assertStringContainsString('transformHeadersToServerVars', $code);
        // The XML example is already the wire body; JSON-encoding it would
        // send the quoted string "<pet name=\"Fido\"/>" instead.
        $this->assertStringContainsString('$payload = \'<pet name="Fido"/>\';', $code);
    }

    #[Test]
    public function a_summary_cannot_terminate_the_generated_docblock(): void
    {
        $spec = $this->writeInlineSpec('comment', ['/pets' => ['get' => [
            'summary' => "Ends the comment */ and\ncontinues on a new line",
            'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
        ]]]);

        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(StubsCommand::EXIT_OK, $exit);
        $file = $this->workDir . '/out/GetPetsTest.php';
        $code = (string) file_get_contents($file);
        $this->assertStringContainsString('*\\/', $code);
        $this->assertStringContainsString(' * continues on a new line', $code);
        $this->assertSame(0, $this->lint($file));
    }

    #[Test]
    public function a_shadowing_entry_document_is_rejected(): void
    {
        // The loader resolves a name and searches .json before .yaml, so
        // stubbing the requested YAML would silently use the JSON instead.
        $this->writeInlineSpec('petstore', ['/pets' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]);
        file_put_contents($this->workDir . '/petstore.yaml', "openapi: 3.0.3\n");

        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $this->workDir . '/petstore.yaml',
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertSame(StubsCommand::EXIT_USAGE, $exit);
        $this->assertStringContainsString('selects', $this->stderr);
        $this->assertStringContainsString('petstore.json', $this->stderr);
    }

    #[Test]
    public function pest_stubs_default_into_the_laravel_harness_directory(): void
    {
        // Pest generates Laravel HTTP calls, which only work where the
        // project's uses(TestCase::class, ...)->in('Feature') binding reaches.
        $this->assertSame('tests/Feature/Contract', StubRenderer::DEFAULT_OUTPUT_DIRS['pest']);

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $this->writeSpec(),
            '--adapter=pest',
            '--output=' . $this->workDir . '/out',
        ]));

        $this->assertStringContainsString(
            'uses(TestCase::class, ValidatesOpenApiSchema::class)',
            (string) file_get_contents($this->workDir . '/out/GetPetsTest.php'),
        );
    }

    #[Test]
    public function fills_path_parameters_required_query_and_headers_from_the_spec(): void
    {
        $spec = $this->workDir . '/params.json';
        file_put_contents($spec, json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Params', 'version' => '1.0.0'],
            'paths' => [
                '/pets/{petId}' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'petId', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                            ['name' => 'limit', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                            ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'X-Tenant', 'in' => 'header', 'required' => true, 'example' => 'acme'],
                        ],
                        'responses' => [
                            '200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--adapter=laravel',
            '--output=' . $this->workDir . '/out',
        ]));

        $code = (string) file_get_contents($this->workDir . '/out/GetPetsPetIdTest.php');
        $this->assertStringContainsString("'/pets/1?limit=1'", $code);
        $this->assertStringContainsString("'X-Tenant' => 'acme'", $code);
        // Optional query parameters are not invented into the request line.
        $this->assertStringNotContainsString('cursor', $code);
    }

    #[Test]
    public function a_response_without_content_stubs_a_single_no_content_test(): void
    {
        $spec = $this->workDir . '/nocontent.json';
        file_put_contents($spec, json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'No content', 'version' => '1.0.0'],
            'paths' => ['/pets/{petId}' => ['delete' => ['responses' => ['204' => ['description' => 'gone']]]]],
        ], JSON_THROW_ON_ERROR));

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $code = (string) file_get_contents($this->workDir . '/out/DeletePetsPetIdTest.php');
        $this->assertStringContainsString('test_delete_pets_pet_id_204_no_content', $code);
        // The core validator takes a null body and no content type for a 204.
        $this->assertStringContainsString("            204,\n            null,\n        );", $code);
    }

    #[Test]
    public function a_range_status_key_is_exercised_as_a_concrete_code(): void
    {
        $spec = $this->workDir . '/range.json';
        file_put_contents($spec, json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Range', 'version' => '1.0.0'],
            'paths' => ['/pets' => ['get' => ['responses' => [
                '4XX' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            ]]]],
        ], JSON_THROW_ON_ERROR));

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $code = (string) file_get_contents($this->workDir . '/out/GetPetsTest.php');
        $this->assertStringContainsString('The spec declares `4XX`; this stub exercises 400.', $code);
        $this->assertStringContainsString('            400,', $code);
    }

    #[Test]
    public function a_response_example_becomes_the_stubbed_body(): void
    {
        $spec = $this->workDir . '/example.json';
        file_put_contents($spec, json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Example', 'version' => '1.0.0'],
            'paths' => ['/pets' => ['get' => ['responses' => ['200' => ['content' => ['application/json' => [
                'schema' => ['type' => 'array'],
                'example' => [['id' => 1, 'name' => 'Fido']],
            ]]]]]]],
        ], JSON_THROW_ON_ERROR));

        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/out',
        ]));

        $code = (string) file_get_contents($this->workDir . '/out/GetPetsTest.php');
        $this->assertStringContainsString("'id' => 1,", $code);
        $this->assertStringContainsString("'name' => 'Fido',", $code);
    }

    #[Test]
    public function rejects_a_coverage_document_written_by_another_schema_version(): void
    {
        $coverage = $this->workDir . '/coverage.json';
        file_put_contents($coverage, json_encode(['schema_version' => 1, 'specs' => []], JSON_THROW_ON_ERROR));

        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $this->writeSpec(),
            '--coverage=' . $coverage,
        ]));

        $this->assertSame(StubsCommand::EXIT_USAGE, $exit);
        $this->assertStringContainsString('Unsupported coverage schema_version', $this->stderr);
    }

    #[Test]
    public function names_the_available_specs_when_the_coverage_document_has_no_matching_entry(): void
    {
        $coverage = $this->workDir . '/coverage.json';
        file_put_contents($coverage, json_encode([
            'schema_version' => 3,
            'specs' => ['front' => ['endpoints' => []]],
        ], JSON_THROW_ON_ERROR));

        $exit = $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $this->writeSpec(),
            '--coverage=' . $coverage,
        ]));

        $this->assertSame(StubsCommand::EXIT_USAGE, $exit);
        $this->assertStringContainsString('Available: front', $this->stderr);
    }

    private function command(): StubsCommand
    {
        return new StubsCommand(
            function (string $message): void {
                $this->stdout .= $message;
            },
            function (string $message): void {
                $this->stderr .= $message;
            },
            'gesso stubs',
        );
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function writeInlineSpec(string $name, array $paths): string
    {
        $path = $this->workDir . '/' . $name . '.json';
        file_put_contents($path, json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => $name, 'version' => '1.0.0'],
            'paths' => $paths,
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * Regenerate into a second directory to confirm names are stable.
     *
     * @return list<string>
     */
    private function rerunClassNamesFor(string $spec): array
    {
        $this->command()->run(StubsCommand::parseArgv([
            '--spec=' . $spec,
            '--output=' . $this->workDir . '/rerun',
        ]));

        $files = [];
        foreach (scandir($this->workDir . '/rerun') ?: [] as $entry) {
            if (str_contains($entry, '.php')) {
                $files[] = $entry;
            }
        }
        sort($files);

        return $files;
    }

    private function lint(string $file): int
    {
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        return $status;
    }

    /** A two-operation spec: one GET and one POST, each with a single response. */
    private function writeSpec(): string
    {
        $path = $this->workDir . '/petstore.json';
        file_put_contents($path, json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Pets', 'version' => '1.0.0'],
            'paths' => [
                '/pets' => [
                    'get' => [
                        'operationId' => 'listPets',
                        'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'array']]]]],
                    ],
                    'post' => [
                        'operationId' => 'createPet',
                        'requestBody' => ['content' => ['application/json' => [
                            'schema' => ['type' => 'object'],
                            'example' => ['name' => 'Fido'],
                        ]]],
                        'responses' => ['201' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @param array<string, list<array{string, string, string}>> $endpoints */
    private function writeCoverage(array $endpoints): string
    {
        $rows = [];
        foreach ($endpoints as $endpoint => $responses) {
            [$method, $path] = explode(' ', $endpoint, 2);
            $rows[] = [
                'method' => $method,
                'path' => $path,
                'responses' => array_map(static fn(array $row): array => [
                    'status_key' => $row[0],
                    'content_type_key' => $row[1],
                    'response_state' => $row[2],
                ], $responses),
            ];
        }

        $path = $this->workDir . '/coverage.json';
        file_put_contents($path, json_encode([
            'schema_version' => 3,
            'specs' => ['petstore' => ['endpoints' => $rows]],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }

    /** @return list<string> */
    private function generatedFiles(): array
    {
        $directory = $this->workDir . '/out';
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (str_contains($entry, '.php')) {
                $files[] = $entry;
            }
        }
        sort($files);

        return $files;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
