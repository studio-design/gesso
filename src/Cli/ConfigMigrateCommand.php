<?php

declare(strict_types=1);

namespace Studio\Gesso\Cli;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_INT_MAX;

use Studio\Gesso\Config\ConfigMigrator;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Internal\ArgvParser;
use Studio\Gesso\Internal\CliIo;
use Studio\Gesso\Internal\ToolVersion;

use function array_column;
use function count;
use function file_put_contents;
use function getcwd;
use function implode;
use function in_array;
use function json_encode;
use function rtrim;
use function sprintf;
use function strlen;
use function usort;

/**
 * `gesso config:migrate`: print (or write) the root `gesso.php` equivalent
 * to a project's v2 phpunit.xml parameters, published Laravel config and
 * competitor validator setup, and list every other edit the migration needs.
 *
 * Text output keeps stdout for the proposed file alone, so it can be
 * redirected; the report goes to stderr. `--format=json` puts one document on
 * stdout instead.
 *
 * @phpstan-type MigrateOptions array{write?: bool, force?: bool, check?: bool, format?: string, help?: bool, invalid_options?: list<string>}
 *
 * @internal The `gesso config:migrate` CLI surface is the supported API.
 */
final class ConfigMigrateCommand
{
    use CliIo;

    public const JSON_SCHEMA_VERSION = 1;

    /** @param null|callable(string): void $stdoutWriter */
    public function __construct(
        private mixed $stdoutWriter = null,
        private mixed $stderrWriter = null,
        private readonly string $invocation = 'gesso config:migrate',
        private readonly ?string $projectDir = null,
    ) {}

    /**
     * @param list<string> $argv excluding the script name
     *
     * @return MigrateOptions
     */
    public static function parseArgv(array $argv): array
    {
        /** @var MigrateOptions */
        return ArgvParser::parse($argv, flags: ['write', 'force', 'check'], values: ['format']);
    }

    public static function usage(string $invocation = 'gesso config:migrate'): string
    {
        return <<<USAGE
            {$invocation} — move a v2 or competitor setup to gesso.php.

            Usage:
              {$invocation} [options]

            Reads, from the current directory: the Gesso extension parameters in
            phpunit.xml / phpunit.dist.xml / phpunit.xml.dist, a published v2
            config/gesso.php, an existing gesso.php, composer.json / composer.lock,
            .github/workflows/*.yml and Makefile. Nothing but gesso.php is written.

            Options:
              --write              Write the proposal to ./gesso.php instead of printing it.
              --force              Let --write replace a non-empty gesso.php.
              --check              Print only the report; exit 1 while anything is left
                                   to migrate.
              --format=text|json   Report format (default: text). text prints the
                                   proposed gesso.php on stdout and the report on stderr.
              --help               Show this message.

            Exit codes:
              0  Done; with --check, nothing is left to migrate.
              1  With --check: a legacy setting, flag, test runner or competitor
                 package remains.
              2  Command-line usage is invalid, gesso.php exists and --force was not
                 given, the proposal does not validate, or gesso.php cannot be written.

            USAGE;
    }

    /** @param MigrateOptions $options */
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
        $format = $options['format'] ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->usageError("Unsupported --format={$format}.");
        }

        $root = rtrim($this->projectDir ?? (getcwd() ?: '.'), '/\\');
        $result = ConfigMigrator::scan($root);
        $proposal = $result['config'] === null ? null : ConfigMigrator::render($result['config']);
        $check = ($options['check'] ?? false) === true;

        $written = false;
        if (($options['write'] ?? false) === true && $proposal !== null) {
            $target = $root . '/' . GessoConfig::FILENAME;
            if ($result['existing'] && ($options['force'] ?? false) !== true) {
                $this->writeStderr("[Gesso] gesso.php already exists and is not empty; review the proposal (run without --write), then re-run with --write --force to replace it.\n");

                return self::EXIT_USAGE;
            }
            if (in_array('invalid-value', array_column($result['findings'], 'category'), true)) {
                $this->writeStderr($this->report($result['findings'], $result['warnings']) . "[Gesso] The proposal does not validate; gesso.php was not written.\n");

                return self::EXIT_USAGE;
            }
            if (@file_put_contents($target, $proposal) !== strlen($proposal)) {
                $this->writeStderr("[Gesso] Cannot write {$target}.\n");

                return self::EXIT_USAGE;
            }
            $written = true;
        }

        if ($format === 'json') {
            $this->writeStdout(json_encode([
                'schemaVersion' => self::JSON_SCHEMA_VERSION,
                'tool' => ['name' => ToolVersion::PACKAGE, 'version' => ToolVersion::resolve()],
                'clean' => $result['findings'] === [],
                'config' => $proposal,
                'written' => $written,
                'findings' => $result['findings'],
                'warnings' => $result['warnings'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        } else {
            if ($proposal !== null && !$check && !$written) {
                $this->writeStdout($proposal);
            }
            $this->writeStderr($this->report($result['findings'], $result['warnings']) . ($written ? "[Gesso] Wrote gesso.php.\n" : ''));
        }

        return $check && $result['findings'] !== [] ? self::EXIT_FAILURE : self::EXIT_OK;
    }

    /**
     * Findings grouped by file, the test-runner upgrade first because every
     * other step assumes a supported PHPUnit.
     *
     * @param list<array{category: string, file: string, line: ?int, message: string, action: string}> $findings
     * @param list<string> $warnings
     */
    private function report(array $findings, array $warnings): string
    {
        if ($findings === []) {
            $out = "[Gesso] config:migrate: nothing left to migrate.\n";
        } else {
            $out = sprintf("[Gesso] config:migrate: %d item(s) to migrate.\n", count($findings));
            $runner = [];
            $byFile = [];
            foreach ($findings as $finding) {
                if ($finding['category'] === 'test-runner') {
                    $runner[] = $finding;
                } else {
                    $byFile[$finding['file']][] = $finding;
                }
            }
            if ($runner !== []) {
                $out .= "\nStep 0: upgrade the test runner (PHPUnit 11 and Pest 3 are unsupported):\n";
                foreach ($runner as $finding) {
                    $out .= "  {$finding['file']}: {$finding['message']}\n    {$finding['action']}\n";
                }
            }
            foreach ($byFile as $file => $items) {
                usort($items, static fn(array $a, array $b): int => ($a['line'] ?? PHP_INT_MAX) <=> ($b['line'] ?? PHP_INT_MAX));
                $out .= "\n{$file}:\n";
                foreach ($items as $finding) {
                    $at = $finding['line'] === null ? '' : $finding['line'] . ': ';
                    $out .= "  {$at}{$finding['message']}\n    -> {$finding['action']}\n";
                }
            }
        }
        if ($warnings !== []) {
            $out .= "\nWarnings:\n";
            foreach ($warnings as $warning) {
                $out .= "  - {$warning}\n";
            }
        }

        return $out;
    }
}
