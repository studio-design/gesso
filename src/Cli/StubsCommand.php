<?php

declare(strict_types=1);

namespace Studio\Gesso\Cli;

use const PATHINFO_DIRNAME;
use const PATHINFO_FILENAME;

use RuntimeException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\ArgvParser;
use Studio\Gesso\Internal\CliDocuments;
use Studio\Gesso\Internal\CliIo;
use Studio\Gesso\Stubs\StubGenerator;
use Studio\Gesso\Stubs\StubRenderer;
use Throwable;

use function count;
use function file_exists;
use function file_put_contents;
use function implode;
use function in_array;
use function is_dir;
use function mkdir;
use function pathinfo;
use function rtrim;
use function sprintf;

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
    use CliIo;

    public const EXIT_OK = 0;
    public const EXIT_USAGE = 2;
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
        /** @var StubsOptions */
        return ArgvParser::parse($argv, 'stubs', flags: ['dry_run'], values: self::VALUE_OPTIONS);
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
            $spec = CliDocuments::loadSpec($this->absolutise($specPath), $specPath);
            $states = ($options['coverage'] ?? '') === ''
                ? null
                : StubGenerator::statesFromCoverage(CliDocuments::loadCoverageSpec(
                    $this->absolutise((string) $options['coverage']),
                    (string) $options['coverage'],
                    $specName,
                ));
        } catch (Throwable $e) {
            return $this->usageError($e->getMessage());
        }

        // Names are resolved against every operation the spec declares, before
        // anything is filtered out. Resolving them against the uncovered
        // subset instead would let a name that only collides in the full spec
        // come out unsuffixed once the other side went green — landing on the
        // file that other operation already owns, where the never-overwrite
        // guard would silently drop it.
        $allPlans = (new StubGenerator())->plan($spec, $states);
        $renderer = new StubRenderer(
            $adapter,
            $specName,
            $options['namespace'] ?? StubRenderer::DEFAULT_NAMESPACES[$adapter],
            $options['base_class'] ?? StubRenderer::DEFAULT_BASE_CLASSES[$adapter],
        );
        [$plans, $classNames, $unreachable] = $this->partitionStubbable(
            $allPlans,
            StubRenderer::classNames($allPlans),
            $renderer,
        );
        $outputDir = rtrim($options['output'] ?? StubRenderer::DEFAULT_OUTPUT_DIRS[$adapter], '/');
        $dryRun = ($options['dry_run'] ?? false) === true;

        if ($plans === []) {
            $this->writeStdout(($states === null
                ? "[Gesso] The spec declares no operation to stub.\n"
                : "[Gesso] Every declared response is already covered; nothing to stub.\n")
                . $this->renderUnreachable($unreachable));

            return self::EXIT_OK;
        }

        try {
            return $this->write($plans, $classNames, $renderer, $outputDir, $dryRun, $unreachable);
        } catch (Throwable $e) {
            return $this->usageError($e->getMessage());
        }
    }

    /**
     * Keep the plans that still have something to stub, and split off the
     * responses that cannot be turned into a test:
     *
     * - *unreachable* — no wire status selects the key over the operation's
     *   other keys, e.g. a `4XX` declared alongside all 100 exact 4xx codes.
     *   It stays uncovered forever, so a stub would be a test that cannot pass.
     * - *malformed* — a key that is not a status, a range, or `default`.
     *
     * Both are reported rather than dropped silently, and a malformed key
     * never takes its operation's valid keys down with it.
     *
     * @param list<StubOperation> $plans
     * @param list<string> $classNames parallel to $plans
     *
     * @return array{list<StubOperation>, list<string>, list<array{string, string}>}
     */
    private function partitionStubbable(array $plans, array $classNames, StubRenderer $renderer): array
    {
        $stubbable = [];
        $names = [];
        $rejected = [];

        foreach ($plans as $index => $plan) {
            // An operation whose request this adapter cannot express is
            // reported, not approximated: a wrong stub validates as Skipped
            // and reads as passing.
            $unsupported = $renderer->unsupportedReason($plan);
            if ($unsupported !== null && $plan['tuples'] !== []) {
                $rejected[] = ['unsupported', sprintf(
                    '%s %s  %s',
                    $plan['method'],
                    $plan['path'],
                    $unsupported,
                )];

                continue;
            }

            $tuples = [];
            foreach ($plan['tuples'] as $tuple) {
                if ($tuple['reason'] === 'ok') {
                    $tuples[] = $tuple;

                    continue;
                }

                $rejected[] = [$tuple['reason'], sprintf(
                    '%s %s  %s',
                    $plan['method'],
                    $plan['path'],
                    $tuple['content_type'] === OpenApiCoverageTracker::ANY_CONTENT_TYPE
                        ? $tuple['status'] . ' (no content)'
                        : $tuple['status'] . ' ' . $tuple['content_type'],
                )];
            }

            if ($tuples !== []) {
                $plan['tuples'] = $tuples;
                $stubbable[] = $plan;
                $names[] = $classNames[$index];
            }
        }

        return [$stubbable, $names, $rejected];
    }

    /** @param list<array{string, string}> $rejected */
    private function renderUnreachable(array $rejected): string
    {
        $groups = ['unreachable' => [], 'malformed' => [], 'unsupported' => []];
        foreach ($rejected as [$reason, $entry]) {
            $groups[$reason][] = $entry;
        }

        $lines = [];
        if ($groups['unreachable'] !== []) {
            $count = count($groups['unreachable']);
            $lines[] = '';
            $lines[] = sprintf(
                '%d declared response%s not stubbed: no status code and media type select %s '
                . 'over the operation\'s other keys.',
                $count,
                $count === 1 ? ' was' : 's were',
                $count === 1 ? 'it' : 'them',
            );
            foreach ($groups['unreachable'] as $entry) {
                $lines[] = '  ! ' . $entry;
            }
        }
        if ($groups['malformed'] !== []) {
            $count = count($groups['malformed']);
            $lines[] = '';
            $lines[] = sprintf(
                '%d response key%s not an HTTP status, a range, or `default`; run `gesso doctor` for the details.',
                $count,
                $count === 1 ? ' is' : 's are',
            );
            foreach ($groups['malformed'] as $entry) {
                $lines[] = '  ? ' . $entry;
            }
        }
        if ($groups['unsupported'] !== []) {
            $count = count($groups['unsupported']);
            $lines[] = '';
            $lines[] = sprintf(
                '%d operation%s not stubbed for this adapter:',
                $count,
                $count === 1 ? ' was' : 's were',
            );
            foreach ($groups['unsupported'] as $entry) {
                $lines[] = '  ~ ' . $entry;
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * @param list<StubOperation> $plans
     * @param list<string> $classNames parallel to $plans
     * @param list<array{string, string}> $unreachable
     */
    private function write(
        array $plans,
        array $classNames,
        StubRenderer $renderer,
        string $outputDir,
        bool $dryRun,
        array $unreachable,
    ): int {
        $written = [];
        $skipped = [];
        $tuples = 0;

        foreach ($plans as $index => $plan) {
            $className = $classNames[$index];
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

        $this->writeStdout(implode("\n", $lines) . "\n" . $this->renderUnreachable($unreachable));

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
}
