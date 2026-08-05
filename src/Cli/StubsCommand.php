<?php

declare(strict_types=1);

namespace Studio\Gesso\Cli;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;
use const STDERR;

use JsonException;
use RuntimeException;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Stubs\StubGenerator;
use Studio\Gesso\Stubs\StubRenderer;
use Throwable;

use function array_keys;
use function array_map;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_dir;
use function is_file;
use function is_int;
use function is_readable;
use function json_decode;
use function mkdir;
use function pathinfo;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * Scaffolding for the responses no test exercises (issue #406).
 *
 * Spec-only scaffolding produces a stub for every operation, most of which the
 * user already tested. Joining the spec against a coverage document narrows the
 * output to the work that is actually outstanding, which is what turns a
 * coverage report from a scoreboard into a to-do list.
 *
 * Existing files are never overwritten: the command is meant to be re-run as
 * coverage moves, and a re-run must not discard the edits that moved it.
 *
 * @phpstan-import-type StubOperation from StubGenerator
 *
 * @phpstan-type StubsOptions array{spec?: string, coverage?: string, spec_name?: string, adapter?: string, output?: string, namespace?: string, base_class?: string, dry_run?: bool, help?: bool, invalid_options?: list<string>}
 *
 * @internal The `gesso stubs` CLI surface is the supported API.
 */
final class StubsCommand
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 2;
    private const FLAGS = ['dry_run'];
    private const VALUE_OPTIONS = ['spec', 'coverage', 'spec_name', 'adapter', 'output', 'namespace', 'base_class'];

    /** @param null|callable(string): void $stdoutWriter */
    public function __construct(
        private mixed $stdoutWriter = null,
        private mixed $stderrWriter = null,
        private readonly string $invocation = 'gesso stubs',
    ) {}

    /**
     * @param list<string> $argv excluding the script name
     *
     * @return StubsOptions
     */
    public static function parseArgv(array $argv): array
    {
        $options = ['invalid_options' => []];

        foreach ($argv as $arg) {
            if ($arg === 'stubs') {
                continue;
            }
            if ($arg === '--help' || $arg === '-h') {
                $options['help'] = true;

                continue;
            }
            if (!str_starts_with($arg, '--')) {
                $options['invalid_options'][] = $arg;

                continue;
            }

            $option = substr($arg, 2);
            [$name, $value] = str_contains($option, '=') ? explode('=', $option, 2) : [$option, 'true'];
            $name = str_replace('-', '_', $name);

            if (in_array($name, self::FLAGS, true)) {
                $options[$name] = $value !== 'false';

                continue;
            }
            if (!in_array($name, self::VALUE_OPTIONS, true)) {
                $options['invalid_options'][] = '--' . str_replace('_', '-', $name);

                continue;
            }

            $options[$name] = $value;
        }

        return $options;
    }

    public static function usage(string $invocation = 'gesso stubs'): string
    {
        $adapters = implode('|', StubRenderer::ADAPTERS);

        return <<<USAGE
            {$invocation} — write test stubs for the responses no test covers.

            Usage:
              {$invocation} --spec=<path> [options]

            Options:
              --spec=<path>        OpenAPI document to scaffold from (.json/.yaml/.yml).
              --coverage=<path>    Coverage JSON (schema_version 3) written by the
                                   `json_output` extension parameter or
                                   `gesso coverage:merge --json-output`. Only the
                                   responses it does not report as validated are
                                   stubbed. Omit it to stub the whole spec.
              --spec-name=<name>   Key under `specs` in the coverage document, and the
                                   spec name written into the generated tests.
                                   Defaults to the --spec filename without extension.
              --adapter=<name>     {$adapters} (default: phpunit).
              --output=<dir>       Directory to write into. Defaults to the adapter's
                                   conventional location, e.g. tests/Contract.
              --namespace=<ns>     Namespace for the generated classes. Defaults to the
                                   adapter's conventional namespace. Ignored by pest.
              --base-class=<fqcn>  Test class to extend, e.g. Tests\\TestCase.
                                   Ignored by pest.
              --dry-run            Report what would be written without writing it.
              --help               Show this message.

            Existing files are never overwritten; they are reported as skipped.

            Exit codes:
              0  Stubs were written, or there was nothing left to stub.
              2  Command-line usage is invalid, or a spec / coverage file cannot be
                 read, or an output file cannot be written.

            USAGE;
    }

    /** @param StubsOptions $options */
    public function run(array $options): int
    {
        if (($options['help'] ?? false) === true) {
            $this->writeStdout(self::usage($this->invocation));

            return self::EXIT_OK;
        }

        $invalid = $options['invalid_options'] ?? [];
        if ($invalid !== []) {
            return $this->usageError('Unknown argument(s): ' . implode(', ', $invalid));
        }
        if (($options['spec'] ?? '') === '') {
            return $this->usageError('--spec is required.');
        }

        $adapter = $options['adapter'] ?? 'phpunit';
        if (!in_array($adapter, StubRenderer::ADAPTERS, true)) {
            return $this->usageError(sprintf(
                'Unsupported --adapter=%s. Use one of: %s.',
                $adapter,
                implode(', ', StubRenderer::ADAPTERS),
            ));
        }

        /** @var string $specPath */
        $specPath = $options['spec'];
        $specName = $options['spec_name'] ?? pathinfo($this->absolutise($specPath), PATHINFO_FILENAME);

        try {
            $spec = $this->loadSpec($specPath);
            $states = ($options['coverage'] ?? '') === ''
                ? null
                : $this->loadCoverage((string) $options['coverage'], $specName);
        } catch (Throwable $e) {
            return $this->usageError($e->getMessage());
        }

        $plans = (new StubGenerator())->plan($spec, $states);
        $renderer = new StubRenderer(
            $adapter,
            $specName,
            $options['namespace'] ?? StubRenderer::DEFAULT_NAMESPACES[$adapter],
            $options['base_class'] ?? StubRenderer::DEFAULT_BASE_CLASSES[$adapter],
        );
        $outputDir = rtrim($options['output'] ?? StubRenderer::DEFAULT_OUTPUT_DIRS[$adapter], '/');
        $dryRun = ($options['dry_run'] ?? false) === true;

        if ($plans === []) {
            $this->writeStdout($states === null
                ? "[Gesso] The spec declares no operation to stub.\n"
                : "[Gesso] Every declared response is already covered; nothing to stub.\n");

            return self::EXIT_OK;
        }

        try {
            return $this->write($plans, $renderer, $outputDir, $dryRun);
        } catch (Throwable $e) {
            return $this->usageError($e->getMessage());
        }
    }

    /** @param list<StubOperation> $plans */
    private function write(array $plans, StubRenderer $renderer, string $outputDir, bool $dryRun): int
    {
        $written = [];
        $skipped = [];
        $tuples = 0;

        foreach ($plans as $plan) {
            $className = StubRenderer::className($plan['method'], $plan['path']);
            $file = $outputDir . '/' . $className . '.php';

            if (file_exists($this->absolutise($file))) {
                $skipped[] = $file;

                continue;
            }

            $code = $renderer->render($plan, $className);
            $tuples += count($plan['tuples']);

            if (!$dryRun) {
                $this->writeFile($file, $code);
            }
            $written[] = $file;
        }

        $lines = [];
        if ($written !== []) {
            $lines[] = sprintf(
                '[Gesso] %s %d file%s covering %d uncovered response%s%s',
                $dryRun ? 'Would write' : 'Wrote',
                count($written),
                count($written) === 1 ? '' : 's',
                $tuples,
                $tuples === 1 ? '' : 's',
                $dryRun ? ':' : ' to ' . $outputDir . ':',
            );
            foreach ($written as $file) {
                $lines[] = '  + ' . $file;
            }
        }
        if ($skipped !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = sprintf(
                '%d file%s already exist%s and %s left untouched:',
                count($skipped),
                count($skipped) === 1 ? '' : 's',
                count($skipped) === 1 ? 's' : '',
                count($skipped) === 1 ? 'was' : 'were',
            );
            foreach ($skipped as $file) {
                $lines[] = '  = ' . $file;
            }
        }

        $this->writeStdout(implode("\n", $lines) . "\n");

        return self::EXIT_OK;
    }

    private function writeFile(string $file, string $code): void
    {
        $absolute = $this->absolutise($file);
        $directory = pathinfo($absolute, PATHINFO_DIRNAME);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create output directory: {$directory}");
        }
        if (file_put_contents($absolute, $code) === false) {
            throw new RuntimeException("Cannot write stub: {$file}");
        }
    }

    /**
     * Resolve the document through the runtime loader so the stubs describe the
     * same `$ref`-resolved tree the validators enforce.
     *
     * @return array<string, mixed>
     */
    private function loadSpec(string $inputPath): array
    {
        $path = $this->absolutise($inputPath);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Spec is not a readable file: {$inputPath}");
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw new RuntimeException("Unsupported spec extension: .{$extension} ({$inputPath})");
        }

        try {
            OpenApiSpecLoader::reset();
            OpenApiSpecLoader::configure(pathinfo($path, PATHINFO_DIRNAME));

            return OpenApiSpecLoader::load(pathinfo($path, PATHINFO_FILENAME));
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot load {$inputPath}: " . $e->getMessage(), previous: $e);
        } finally {
            OpenApiSpecLoader::reset();
        }
    }

    /** @return array<string, string> */
    private function loadCoverage(string $inputPath, string $specName): array
    {
        $path = $this->absolutise($inputPath);
        $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("Coverage file is not a readable file: {$inputPath}");
        }

        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Coverage file is not valid JSON: {$inputPath}", previous: $e);
        }
        if (!is_array($document)) {
            throw new RuntimeException("Coverage file must decode to a JSON object: {$inputPath}");
        }

        $version = $document['schema_version'] ?? null;
        if (!is_int($version) || $version !== JsonCoverageRenderer::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'Unsupported coverage schema_version in %s: expected %d.',
                $inputPath,
                JsonCoverageRenderer::SCHEMA_VERSION,
            ));
        }

        $specs = is_array($document['specs'] ?? null) ? $document['specs'] : [];
        $spec = $specs[$specName] ?? null;
        if (!is_array($spec)) {
            $available = array_map(static fn(mixed $name): string => (string) $name, array_keys($specs));

            throw new RuntimeException(sprintf(
                'Coverage document has no spec named "%s". Available: %s. Use --spec-name to select one.',
                $specName,
                $available === [] ? '(none)' : implode(', ', $available),
            ));
        }

        return StubGenerator::statesFromCoverage($spec);
    }

    private function absolutise(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $cwd = getcwd();
        $absolute = rtrim($cwd !== false ? $cwd : '.', '/') . '/' . $path;

        return realpath($absolute) ?: $absolute;
    }

    private function usageError(string $message): int
    {
        $this->writeStderr("[Gesso] {$message}\n\n" . self::usage($this->invocation));

        return self::EXIT_USAGE;
    }

    private function writeStdout(string $message): void
    {
        if (is_callable($this->stdoutWriter)) {
            ($this->stdoutWriter)($message);

            return;
        }
        echo $message;
    }

    private function writeStderr(string $message): void
    {
        if (is_callable($this->stderrWriter)) {
            ($this->stderrWriter)($message);

            return;
        }
        fwrite(STDERR, $message);
    }
}
