<?php

declare(strict_types=1);

namespace Studio\Gesso\Cli;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;
use const STDERR;

use JsonException;
use RuntimeException;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function explode;
use function file_get_contents;
use function fwrite;
use function getcwd;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_file;
use function is_int;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function pathinfo;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use function usort;

/**
 * Patch-coverage gate for a spec change (issue #475): the OpenAPI counterpart
 * of `diff-cover` / Codecov's patch status.
 *
 * It answers one question — "did this pull request change an operation that no
 * test exercises?" — by structurally diffing the current spec against a base
 * spec and joining the touched `(method, path, status, content-type)` tuples
 * against a `schema_version: 3` coverage document.
 *
 * Deliberately *not* a breaking-change detector. The diff is structural
 * ("did the resolved node change?"), never semantic ("is the change
 * backwards-incompatible?"); that classification belongs to dedicated diff
 * tools and would drag in a rule catalogue to maintain. Because both specs go
 * through {@see OpenApiSpecLoader}, a pure `$ref` reshuffle that resolves to
 * the same tree reads as unchanged.
 *
 * @phpstan-type GateOptions array{base_spec?: string, spec?: string, coverage?: string, spec_name?: string, format?: string, help?: bool, invalid_options?: list<string>}
 * @phpstan-type GateResponse array{status: string, content_type: string, change: 'added'|'changed'|'removed', state: string, covered: bool}
 * @phpstan-type GateOperation array{endpoint: string, change: 'added'|'changed'|'removed', responses: list<GateResponse>}
 * @phpstan-type IndexedResponse array{status: string, content_type: string, hash: string}
 * @phpstan-type IndexedOperation array{endpoint: string, shape: string, responses: array<string, IndexedResponse>}
 *
 * @internal The `gesso coverage:gate` CLI surface is the supported API.
 */
final class CoverageGateCommand
{
    public const EXIT_OK = 0;
    public const EXIT_UNCOVERED_CHANGE = 1;
    public const EXIT_USAGE = 2;

    /** @param null|callable(string): void $stdoutWriter */
    public function __construct(
        private mixed $stdoutWriter = null,
        private mixed $stderrWriter = null,
        private readonly string $invocation = 'gesso coverage:gate',
    ) {}

    /**
     * @param list<string> $argv excluding the script name
     *
     * @return GateOptions
     */
    public static function parseArgv(array $argv): array
    {
        $options = ['invalid_options' => []];

        foreach ($argv as $arg) {
            if ($arg === 'coverage:gate') {
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

            if (!in_array($name, ['base_spec', 'spec', 'coverage', 'spec_name', 'format'], true)) {
                $options['invalid_options'][] = '--' . str_replace('_', '-', $name);

                continue;
            }

            $options[$name] = $value;
        }

        return $options;
    }

    public static function usage(string $invocation = 'gesso coverage:gate'): string
    {
        return <<<USAGE
            {$invocation} — fail when this change touches an operation no test covers.

            Usage:
              {$invocation} --base-spec=<path> --spec=<path> --coverage=<path> [options]

            Options:
              --base-spec=<path>   Spec as it looks on the base branch, e.g. written by
                                   `git show origin/main:openapi.json > base.json`.
              --spec=<path>        Spec as it looks on this branch.
              --coverage=<path>    Coverage JSON (schema_version 3) written by the
                                   `json_output` extension parameter or
                                   `gesso coverage:merge --json-output`.
              --spec-name=<name>   Key under `specs` in the coverage document.
                                   Defaults to the --spec filename without extension.
              --format=text|markdown
                                   Output format (default: text). `markdown` is meant for
                                   \$GITHUB_STEP_SUMMARY.
              --help               Show this message.

            Exit codes:
              0  Every changed operation is covered (including "nothing changed").
              1  A changed operation has a response no test validated.
              2  Command-line usage is invalid, or a spec / coverage file cannot be read.

            USAGE;
    }

    /** @param GateOptions $options */
    public function run(array $options): int
    {
        if (($options['help'] ?? false) === true) {
            $this->writeStdout(self::usage($this->invocation));

            return self::EXIT_OK;
        }

        $format = $options['format'] ?? 'text';
        $invalid = $options['invalid_options'] ?? [];
        if ($invalid !== []) {
            return $this->usageError('Unknown argument(s): ' . implode(', ', $invalid));
        }
        if (!in_array($format, ['text', 'markdown'], true)) {
            return $this->usageError("Unsupported --format={$format}.");
        }
        foreach (['base_spec', 'spec', 'coverage'] as $required) {
            if (($options[$required] ?? '') === '') {
                return $this->usageError('--' . str_replace('_', '-', $required) . ' is required.');
            }
        }

        /** @var string $basePath */
        $basePath = $options['base_spec'];
        /** @var string $headPath */
        $headPath = $options['spec'];
        /** @var string $coveragePath */
        $coveragePath = $options['coverage'];

        try {
            $baseIndex = $this->index($this->loadSpec($basePath));
            $headIndex = $this->index($this->loadSpec($headPath));
        } catch (Throwable $e) {
            return $this->usageError($e->getMessage());
        }

        $specName = $options['spec_name'] ?? pathinfo($this->absolutise($headPath), PATHINFO_FILENAME);

        try {
            $states = $this->loadCoverage($coveragePath, $specName);
        } catch (Throwable $e) {
            return $this->usageError($e->getMessage());
        }

        $operations = $this->diff($baseIndex, $headIndex, $states);

        $uncovered = 0;
        foreach ($operations as $operation) {
            foreach ($operation['responses'] as $response) {
                if (!$response['covered']) {
                    $uncovered++;
                }
            }
        }

        $this->writeStdout($format === 'markdown'
            ? $this->renderMarkdown($operations, $uncovered)
            : $this->renderText($operations, $uncovered));

        return $uncovered === 0 ? self::EXIT_OK : self::EXIT_UNCOVERED_CHANGE;
    }

    /**
     * Resolve one entry document through the runtime loader so both sides of
     * the diff see the same `$ref`-resolved tree the validators would.
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
            // The loader caches by spec name, so a base and a head document
            // sharing a filename (the common `openapi.json` case) would
            // otherwise collide on the second load().
            OpenApiSpecLoader::reset();
            OpenApiSpecLoader::configure(pathinfo($path, PATHINFO_DIRNAME));

            return OpenApiSpecLoader::load(pathinfo($path, PATHINFO_FILENAME));
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot load {$inputPath}: " . $e->getMessage(), previous: $e);
        } finally {
            OpenApiSpecLoader::reset();
        }
    }

    /**
     * Fingerprint every declared operation and response tuple.
     *
     * `shape` covers the operation's *effective* request contract, not just
     * its own node: the operation minus its responses, plus the Path Item
     * fields it inherits (shared `parameters`, `servers`, …), plus the
     * security requirement that actually applies (operation-level, else the
     * root default) together with the `components.securitySchemes`
     * definitions it names. Those live outside the operation object but are
     * part of what the request validator enforces, so a change to any of them
     * makes every response of that operation worth re-testing.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<string, IndexedOperation>
     */
    private function index(array $spec): array
    {
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $rootSecurity = $spec['security'] ?? null;
        $components = is_array($spec['components'] ?? null) ? $spec['components'] : [];
        /** @var array<string, mixed> $securitySchemes */
        $securitySchemes = is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];
        $index = [];

        foreach ($paths as $path => $pathItem) {
            if (!is_array($pathItem)) {
                continue;
            }

            // Path Item fields the operations under it inherit. Everything
            // that is itself an operation is stripped so a sibling's change
            // does not leak into this operation's fingerprint.
            $inherited = $pathItem;
            unset($inherited['additionalOperations']);
            foreach (OpenApiOperationResolver::FIXED_OPERATION_FIELDS as $field) {
                unset($inherited[$field]);
            }

            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $operation = $declared['operation'];
                if (!is_array($operation)) {
                    continue;
                }

                $method = $declared['method'];
                if (!$this->isTrackedMethod($method, $declared['location'])) {
                    continue;
                }

                $endpoint = $method . ' ' . (string) $path;
                $ownShape = $operation;
                unset($ownShape['responses']);
                $shape = [
                    $ownShape,
                    $inherited,
                    $this->effectiveSecurity($operation, $rootSecurity, $securitySchemes),
                ];

                $responses = [];
                /** @var array<string, mixed> $declaredResponses */
                $declaredResponses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
                foreach ($declaredResponses as $status => $response) {
                    $status = (string) $status;
                    if (!is_array($response)) {
                        continue;
                    }

                    // Mirrors OpenApiCoverageTracker's declared-response walk:
                    // a response without `content` contributes a single
                    // `(status, '*')` tuple so 204s stay visible.
                    $content = is_array($response['content'] ?? null) ? $response['content'] : [];
                    if ($content === []) {
                        $key = $status . "\x1f" . OpenApiCoverageTracker::ANY_CONTENT_TYPE;
                        $responses[$key] = [
                            'status' => $status,
                            'content_type' => OpenApiCoverageTracker::ANY_CONTENT_TYPE,
                            'hash' => $this->fingerprint($response),
                        ];

                        continue;
                    }

                    $envelope = $response;
                    unset($envelope['content']);
                    foreach ($content as $contentType => $media) {
                        $contentType = (string) $contentType;
                        $responses[$status . "\x1f" . $contentType] = [
                            'status' => $status,
                            'content_type' => $contentType,
                            'hash' => $this->fingerprint([$envelope, $media]),
                        ];
                    }
                }

                $index[$endpoint] = [
                    'endpoint' => $endpoint,
                    'shape' => $this->fingerprint($shape),
                    'responses' => $responses,
                ];
            }
        }

        return $index;
    }

    /**
     * Only operations {@see OpenApiCoverageTracker} puts in a coverage
     * document can be gated. It records the baseline methods plus OpenAPI 3.2
     * `additionalOperations`; `OPTIONS` / `HEAD` / `TRACE` never appear there,
     * so gating them would mean demanding coverage no report can ever show.
     */
    private function isTrackedMethod(string $method, string $location): bool
    {
        return in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'QUERY'], true) ||
            str_starts_with($location, 'additionalOperations[');
    }

    /**
     * The security requirement that actually applies, resolved to the scheme
     * definitions it names. An operation-level `security` — including an
     * explicit `[]` opting out — overrides the root default, matching how
     * the request validator selects it.
     *
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $securitySchemes
     */
    private function effectiveSecurity(array $operation, mixed $rootSecurity, array $securitySchemes): mixed
    {
        $security = array_key_exists('security', $operation) ? $operation['security'] : $rootSecurity;
        if (!is_array($security)) {
            return $security;
        }

        $schemes = [];
        foreach ($security as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            foreach (array_keys($requirement) as $name) {
                $name = (string) $name;
                $schemes[$name] = $securitySchemes[$name] ?? null;
            }
        }

        return [$security, $schemes];
    }

    /**
     * @param array<string, IndexedOperation> $base
     * @param array<string, IndexedOperation> $head
     * @param array<string, string> $states
     *
     * @return list<GateOperation>
     */
    private function diff(array $base, array $head, array $states): array
    {
        $operations = [];

        foreach ($head as $endpoint => $current) {
            $previous = $base[$endpoint] ?? null;
            $shapeChanged = $previous !== null && $previous['shape'] !== $current['shape'];

            $touched = [];
            foreach ($current['responses'] as $key => $response) {
                $before = $previous['responses'][$key] ?? null;
                if ($previous !== null && !$shapeChanged && $before !== null && $before['hash'] === $response['hash']) {
                    continue;
                }
                $state = $states[$endpoint . "\x1f" . $key] ?? 'uncovered';
                $touched[] = [
                    'status' => $response['status'],
                    'content_type' => $response['content_type'],
                    'change' => $before === null ? 'added' : 'changed',
                    'state' => $state,
                    'covered' => $state === 'validated',
                ];
            }

            // A tuple the change deletes is still a structural change worth
            // showing, but it cannot be tested any more, so it never fails
            // the gate.
            foreach ($previous['responses'] ?? [] as $key => $response) {
                if (isset($current['responses'][$key])) {
                    continue;
                }
                $touched[] = [
                    'status' => $response['status'],
                    'content_type' => $response['content_type'],
                    'change' => 'removed',
                    'state' => 'removed',
                    'covered' => true,
                ];
            }

            if ($previous !== null && $touched === []) {
                continue;
            }

            usort($touched, static fn(array $a, array $b): int => strcmp(
                $a['status'] . ' ' . $a['content_type'],
                $b['status'] . ' ' . $b['content_type'],
            ));

            $operations[] = [
                'endpoint' => $endpoint,
                'change' => $previous === null ? 'added' : 'changed',
                'responses' => $touched,
            ];
        }

        foreach ($base as $endpoint => $previous) {
            if (isset($head[$endpoint])) {
                continue;
            }
            $operations[] = ['endpoint' => $endpoint, 'change' => 'removed', 'responses' => []];
        }

        usort($operations, static fn(array $a, array $b): int => strcmp($a['endpoint'], $b['endpoint']));

        return $operations;
    }

    /**
     * Read the `(method, path, status, content-type)` states out of a
     * coverage document.
     *
     * @return array<string, string>
     */
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

        $states = [];
        $endpoints = is_array($spec['endpoints'] ?? null) ? $spec['endpoints'] : [];
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint) || !is_string($endpoint['method'] ?? null) || !is_string($endpoint['path'] ?? null)) {
                continue;
            }
            $key = $endpoint['method'] . ' ' . $endpoint['path'];
            $responses = is_array($endpoint['responses'] ?? null) ? $endpoint['responses'] : [];
            foreach ($responses as $response) {
                if (!is_array($response) ||
                    !is_string($response['status_key'] ?? null) ||
                    !is_string($response['content_type_key'] ?? null) ||
                    !is_string($response['response_state'] ?? null)) {
                    continue;
                }
                $states[$key . "\x1f" . $response['status_key'] . "\x1f" . $response['content_type_key']] = $response['response_state'];
            }
        }

        return $states;
    }

    /**
     * Order-insensitive fingerprint of a resolved node: object keys sort,
     * array order is kept.
     */
    private function fingerprint(mixed $node): string
    {
        try {
            $encoded = json_encode(
                $this->canonicalize($node),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            // Unencodable node (e.g. an invalid UTF-8 byte sequence surviving
            // the loader): treat it as always-changed rather than silently
            // equal, so the gate errs toward asking for coverage.
            return 'unencodable:' . hash('xxh128', (string) json_encode(null));
        }

        return hash('xxh128', $encoded);
    }

    private function canonicalize(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $canonical = array_map(fn(mixed $child): mixed => $this->canonicalize($child), $node);
        if (!array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }

    /** @param list<GateOperation> $operations */
    private function renderText(array $operations, int $uncovered): string
    {
        if ($operations === []) {
            return "[Gesso] No operation changed against the base spec.\n";
        }

        $changed = 0;
        $width = 0;
        foreach ($operations as $operation) {
            if ($operation['change'] !== 'removed') {
                $changed++;
            }
            foreach ($operation['responses'] as $response) {
                $width = max($width, strlen($this->describeResponse($response)));
            }
        }

        $lines = [sprintf(
            '[Gesso] %d operation%s changed against the base spec:',
            $changed,
            $changed === 1 ? '' : 's',
        ), ''];

        foreach ($operations as $operation) {
            if ($operation['change'] === 'removed') {
                $lines[] = sprintf('  %s    removed from the spec (not testable)', $operation['endpoint']);

                continue;
            }
            $lines[] = '  ' . $operation['endpoint'];
            foreach ($operation['responses'] as $response) {
                $lines[] = sprintf(
                    '    %s    %s',
                    str_pad($this->describeResponse($response), $width),
                    $this->describeState($response),
                );
            }
        }

        $lines[] = '';
        $lines[] = $uncovered === 0
            ? 'All changed responses are covered.'
            : sprintf(
                '%d changed response%s not covered by any test.',
                $uncovered,
                $uncovered === 1 ? ' is' : 's are',
            );

        return implode("\n", $lines) . "\n";
    }

    /** @param list<GateOperation> $operations */
    private function renderMarkdown(array $operations, int $uncovered): string
    {
        $lines = ['### Gesso spec patch coverage', ''];
        if ($operations === []) {
            $lines[] = 'No operation changed against the base spec.';

            return implode("\n", $lines) . "\n";
        }

        $lines[] = $uncovered === 0
            ? 'All changed responses are covered.'
            : sprintf(
                '**%d changed response%s not covered by any test.**',
                $uncovered,
                $uncovered === 1 ? ' is' : 's are',
            );
        $lines[] = '';
        $lines[] = '| Operation | Change | Response | Coverage |';
        $lines[] = '| --- | --- | --- | --- |';

        foreach ($operations as $operation) {
            if ($operation['responses'] === []) {
                $lines[] = sprintf('| `%s` | %s | — | — |', $operation['endpoint'], $operation['change']);

                continue;
            }
            foreach ($operation['responses'] as $response) {
                $lines[] = sprintf(
                    '| `%s` | %s | `%s` | %s |',
                    $operation['endpoint'],
                    $response['change'],
                    $this->describeResponse($response),
                    $this->describeState($response),
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param GateResponse $response */
    private function describeResponse(array $response): string
    {
        return $response['content_type'] === OpenApiCoverageTracker::ANY_CONTENT_TYPE
            ? $response['status'] . ' (no content)'
            : $response['status'] . ' ' . $response['content_type'];
    }

    /** @param GateResponse $response */
    private function describeState(array $response): string
    {
        return match ($response['state']) {
            'validated' => 'covered',
            'skipped' => 'SKIPPED',
            'removed' => 'removed (not testable)',
            default => 'UNCOVERED',
        };
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
