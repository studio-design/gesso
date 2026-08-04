<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Conformance;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Cli\DoctorCommand;

use function basename;
use function copy;
use function file_get_contents;
use function glob;
use function json_decode;
use function ksort;
use function mkdir;
use function rmdir;
use function sprintf;
use function str_ends_with;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Conformance signal for the spec loader and `gesso doctor` against the
 * official OpenAPI example documents (issue #283).
 *
 * Every example document the OpenAPI Initiative publishes for OAS 3.0, 3.1,
 * and 3.2 is loaded and diagnosed in both its JSON and its YAML form, and the
 * outcome — OpenAPI version, operation and response counts, and the
 * severity/category of every issue raised — is pinned by a committed baseline.
 * A document that stops loading, starts raising an issue, or silently loses
 * operations fails the build.
 *
 * Issue prose is deliberately not compared. Message wording is not a
 * compatibility surface (see `docs/versioning.md`) and is covered by the
 * doctor's own unit tests; what is pinned here is that the document is
 * understood at all.
 *
 * The corpus is pinned by commit SHA in `composer.json` (the lock file is not
 * committed for a library, so `composer.json` is the pin). See
 * `docs/conformance.md`.
 */
final class OasExampleDocumentTest extends TestCase
{
    /**
     * `examples/v2.0` also ships in the corpus. It is Swagger 2.0, which this
     * package does not accept by design, so it is not measured.
     */
    private const VERSIONS = ['v3.0', 'v3.1', 'v3.2'];

    /**
     * Documents whose JSON and YAML forms legitimately disagree, keyed by
     * document with the reason. Everything else must produce an identical
     * diagnosis in both serializations.
     */
    private const SERIALIZATION_DIFFERENCES = ['v3.1/tictactoe'];
    private string $yamlRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // The corpus ships `petstore.json` and `petstore.yaml` side by side,
        // and the loader resolves a bare spec name to the JSON sibling first —
        // the doctor reports that shadowing as a configuration error rather
        // than diagnosing a file the runtime would not have loaded. Copying the
        // YAML forms into a tree of their own removes the sibling, so the YAML
        // pipeline is exercised for real instead of pinning that diagnostic.
        $this->yamlRoot = sys_get_temp_dir() . '/gesso-oas-examples-' . uniqid('', true);

        foreach (self::VERSIONS as $version) {
            mkdir($this->yamlRoot . '/' . $version, recursive: true);
            foreach ($this->documentsIn($version, 'yaml') as $path) {
                copy($path, $this->yamlRoot . '/' . $version . '/' . basename($path));
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (self::VERSIONS as $version) {
            foreach (glob($this->yamlRoot . '/' . $version . '/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->yamlRoot . '/' . $version);
        }
        @rmdir($this->yamlRoot);

        parent::tearDown();
    }

    #[Test]
    public function every_official_example_document_is_diagnosed_as_the_baseline_records(): void
    {
        $baseline = $this->baseline();

        $this->assertSame(
            $baseline['corpus']['commit'],
            $this->installedCorpusCommit(),
            'The installed corpus does not match the commit the baseline was recorded against. '
            . 'Re-pin composer.json or regenerate the baseline.',
        );

        $this->assertSame(
            $baseline['documents'],
            $this->measure(),
            'The diagnosis of the official OpenAPI example documents has moved. A new issue on a document '
            . 'that used to be clean is a regression; a lost operation or response means the loader stopped '
            . 'understanding part of a document the OpenAPI Initiative publishes as valid. Update '
            . 'tests/fixtures/compatibility/v1-oas-example-documents.json and docs/conformance.md.',
        );
    }

    #[Test]
    public function json_and_yaml_forms_agree_except_where_the_corpus_itself_differs(): void
    {
        $documents = $this->measure();
        $differences = [];

        foreach ($documents as $key => $entry) {
            if (!str_ends_with($key, '.json')) {
                continue;
            }

            $document = substr($key, 0, -5);
            $yaml = $documents[$document . '.yaml'] ?? null;
            $this->assertNotNull($yaml, sprintf('Document "%s" has no YAML form to compare against.', $document));

            if ($entry !== $yaml) {
                $differences[] = $document;
            }
        }

        $this->assertSame(
            self::SERIALIZATION_DIFFERENCES,
            $differences,
            'The JSON and YAML forms of an example document are no longer diagnosed identically. Unless the '
            . 'two upstream files genuinely differ, this is a YAML pipeline defect.',
        );

        foreach (self::SERIALIZATION_DIFFERENCES as $document) {
            $this->assertArrayHasKey(
                $document,
                $this->baseline()['serialization_differences'],
                sprintf('Document "%s" is exempted from JSON/YAML parity without a published reason.', $document),
            );
        }
    }

    /**
     * @return array<string, array{openapi: string, exit: int, operations: int, responses: int, issues: list<string>}>
     */
    private function measure(): array
    {
        $documents = [];

        foreach (self::VERSIONS as $version) {
            foreach (['json', 'yaml'] as $extension) {
                $paths = $extension === 'json'
                    ? $this->documentsIn($version, 'json')
                    : $this->yamlCopies($version);

                foreach ($paths as $path) {
                    $documents[$version . '/' . basename($path)] = $this->diagnose($path);
                }
            }
        }

        ksort($documents);

        return $documents;
    }

    /**
     * @return array{openapi: string, exit: int, operations: int, responses: int, issues: list<string>}
     */
    private function diagnose(string $path): array
    {
        $output = '';
        $command = new DoctorCommand(stdoutWriter: static function (string $message) use (&$output): void {
            $output .= $message;
        });
        $exit = $command->run(['specs' => [$path], 'format' => 'json']);

        /**
         * @var array{
         *     specs: list<array{openapi: string}>,
         *     summary: array{operations: int, responses: int},
         *     issues: list<array{severity: string, category: string}>
         * } $report
         */
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $issues = [];
        foreach ($report['issues'] as $issue) {
            $issues[] = $issue['severity'] . '/' . $issue['category'];
        }

        return [
            'openapi' => $report['specs'][0]['openapi'] ?? '',
            'exit' => $exit,
            'operations' => $report['summary']['operations'],
            'responses' => $report['summary']['responses'],
            'issues' => $issues,
        ];
    }

    /**
     * @return list<string>
     */
    private function documentsIn(string $version, string $extension): array
    {
        $directory = $this->corpusPath() . '/examples/' . $version;
        $this->assertDirectoryExists($directory, sprintf('Corpus directory "%s" is missing.', $version));

        $matches = glob($directory . '/*.' . $extension);
        $this->assertIsArray($matches);
        $this->assertNotEmpty($matches, sprintf('No .%s documents in corpus directory "%s".', $extension, $version));

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function yamlCopies(string $version): array
    {
        $matches = glob($this->yamlRoot . '/' . $version . '/*.yaml');
        $this->assertIsArray($matches);

        return $matches;
    }

    private function installedCorpusCommit(): string
    {
        $installed = file_get_contents($this->corpusPath() . '/../../composer/installed.json');
        $this->assertIsString($installed, 'Cannot read vendor/composer/installed.json.');

        /** @var array{packages: list<array{name: string, dist?: array{reference?: string}}>} $decoded */
        $decoded = json_decode($installed, true, flags: JSON_THROW_ON_ERROR);

        foreach ($decoded['packages'] as $package) {
            if ($package['name'] === 'oai/learn.openapis.org') {
                return $package['dist']['reference'] ?? '';
            }
        }

        $this->fail('The OpenAPI example-document corpus is not installed.');
    }

    private function corpusPath(): string
    {
        $path = __DIR__ . '/../../../vendor/oai/learn.openapis.org';
        $this->assertDirectoryExists($path, 'Run `composer install` to fetch the pinned OpenAPI example documents.');

        return $path;
    }

    /**
     * @return array{
     *     corpus: array{commit: string},
     *     documents: array<string, array{openapi: string, exit: int, operations: int, responses: int, issues: list<string>}>,
     *     serialization_differences: array<string, string>
     * }
     */
    private function baseline(): array
    {
        $path = __DIR__ . '/../../fixtures/compatibility/v1-oas-example-documents.json';
        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'Missing example-document baseline.');

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
