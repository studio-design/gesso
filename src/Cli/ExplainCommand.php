<?php

declare(strict_types=1);

namespace Studio\Gesso\Cli;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use RuntimeException;
use Studio\Gesso\Internal\ArgvParser;
use Studio\Gesso\Internal\CliIo;
use Studio\Gesso\Internal\FailureCatalogue;
use Studio\Gesso\Internal\ToolVersion;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function in_array;
use function json_encode;
use function sprintf;
use function str_pad;
use function str_starts_with;
use function strtoupper;

/**
 * `gesso explain <code>`: what a `GSO` failure code means, its common causes,
 * how to fix it, and what not to do to silence it. Without a code it lists
 * every code.
 *
 * @phpstan-import-type FailureCode from FailureCatalogue
 *
 * @phpstan-type ExplainOptions array{format?: string, output_file?: string, help?: bool, invalid_options?: list<string>}
 *
 * @internal The `gesso explain` CLI surface is the supported API.
 */
final class ExplainCommand
{
    use CliIo;

    public const JSON_SCHEMA_VERSION = 1;

    /** @param null|callable(string): void $stdoutWriter */
    public function __construct(
        private mixed $stdoutWriter = null,
        private mixed $stderrWriter = null,
        private readonly string $invocation = 'gesso explain',
    ) {}

    /**
     * @param list<string> $argv excluding the script name
     *
     * @return ExplainOptions
     */
    public static function parseArgv(array $argv): array
    {
        /** @var ExplainOptions */
        return ArgvParser::parse($argv, values: ['format', 'output_file']);
    }

    public static function usage(string $invocation = 'gesso explain'): string
    {
        return <<<USAGE
            {$invocation} — explain a Gesso failure code.

            Usage:
              {$invocation} [<code>] [options]

            Arguments:
              <code>               A failure code such as GSO212, as printed in
                                   failure output. Omit it to list every code.

            Options:
              --format=text|json   Output format (default: text).
              --output-file=<path> Write the explanation there instead of stdout.
              --help               Show this message.

            Exit codes:
              0  The code was explained, or every code was listed.
              1  Reserved; not returned.
              2  The code is unknown, or command-line usage is invalid.

            USAGE;
    }

    /** @param ExplainOptions $options */
    public function run(array $options): int
    {
        if (($options['help'] ?? false) === true) {
            $this->writeStdout(self::usage($this->invocation));

            return self::EXIT_OK;
        }

        // ArgvParser reports every positional argument as invalid; the first
        // one is the code this command takes.
        $invalid = $options['invalid_options'] ?? [];
        $positional = array_values(array_filter($invalid, static fn(string $arg): bool => !str_starts_with($arg, '-')));
        $unknown = array_values(array_filter($invalid, static fn(string $arg): bool => str_starts_with($arg, '-')));
        if (count($positional) > 1) {
            $unknown = [...$unknown, ...array_slice($positional, 1)];
        }
        if ($unknown !== []) {
            return $this->usageError('Unknown argument(s): ' . implode(', ', $unknown));
        }

        $format = $options['format'] ?? 'text';
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->usageError("Unsupported --format={$format}.");
        }

        $code = $positional[0] ?? null;
        if ($code === null) {
            $rendered = $format === 'json' ? $this->listJson() : $this->listText();
        } else {
            $code = strtoupper($code);
            $entry = FailureCatalogue::find($code);
            if ($entry === null) {
                $suggestions = FailureCatalogue::suggest($code);
                $this->writeStderr(sprintf(
                    "[Gesso] Unknown failure code: %s.%s\nRun `%s` to list every code.\n",
                    $code,
                    $suggestions === [] ? '' : ' Did you mean ' . implode(', ', $suggestions) . '?',
                    $this->invocation,
                ));

                return self::EXIT_USAGE;
            }
            $rendered = $format === 'json' ? $this->entryJson($code, $entry) : $this->entryText($code, $entry);
        }

        try {
            $this->writeReport($rendered, $options['output_file'] ?? null);
        } catch (RuntimeException $e) {
            return $this->usageError($e->getMessage());
        }

        return self::EXIT_OK;
    }

    /** @param FailureCode $entry */
    private function entryText(string $code, array $entry): string
    {
        $lines = [
            "{$code}: {$entry['title']}",
            '',
            $entry['summary'],
            '',
            FailureCatalogue::source($code),
            '',
            'Common causes:',
            ...array_map(static fn(string $cause): string => '  - ' . $cause, $entry['causes']),
            '',
            'How to fix:',
            ...array_map(static fn(string $fix): string => '  - ' . $fix, $entry['fix']),
            '',
            $entry['do_not'],
            '',
            'Docs: ' . FailureCatalogue::docsUrl($code),
        ];

        return implode("\n", $lines) . "\n";
    }

    /** @param FailureCode $entry */
    private function entryJson(string $code, array $entry): string
    {
        return $this->json($this->describe($code, $entry));
    }

    private function listText(): string
    {
        $lines = [];
        foreach (FailureCatalogue::codes() as $code) {
            $entry = FailureCatalogue::find($code);
            if ($entry !== null) {
                $lines[] = str_pad($code, 8) . $entry['title'];
            }
        }
        $lines[] = '';
        $lines[] = "Run `{$this->invocation} <code>` for one code, or see " . FailureCatalogue::DOCS_URL;

        return implode("\n", $lines) . "\n";
    }

    private function listJson(): string
    {
        $codes = [];
        foreach (FailureCatalogue::codes() as $code) {
            $entry = FailureCatalogue::find($code);
            if ($entry !== null) {
                $codes[] = $this->describe($code, $entry);
            }
        }

        return $this->json(['codes' => $codes]);
    }

    /**
     * @param FailureCode $entry
     *
     * @return array<string, mixed>
     */
    private function describe(string $code, array $entry): array
    {
        return [
            'code' => $code,
            'title' => $entry['title'],
            'summary' => $entry['summary'],
            'source' => FailureCatalogue::source($code),
            'causes' => $entry['causes'],
            'fix' => $entry['fix'],
            'do_not' => $entry['do_not'],
            'docs_url' => FailureCatalogue::docsUrl($code),
        ];
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): string
    {
        return json_encode([
            'schemaVersion' => self::JSON_SCHEMA_VERSION,
            'tool' => ['name' => ToolVersion::PACKAGE, 'version' => ToolVersion::resolve()],
            ...$body,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
