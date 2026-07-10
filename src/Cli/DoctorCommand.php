<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Cli;

use const E_USER_WARNING;
use const ENT_QUOTES;
use const ENT_XML1;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;
use const STDERR;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\MalformedDiscriminatorException;
use Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException;
use Studio\OpenApiContractTesting\OpenApiVersion;
use Studio\OpenApiContractTesting\Spec\OpenApiOperationResolver;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaConverter;
use Studio\OpenApiContractTesting\Spec\OpenApiSchemaDialect;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Support\DiscriminatorContext;
use Studio\OpenApiContractTesting\Validation\Support\MalformedSpecNode;
use Symfony\Component\HttpClient\Psr18Client;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function class_exists;
use function compact;
use function count;
use function dirname;
use function explode;
use function fwrite;
use function getcwd;
use function htmlspecialchars;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_file;
use function is_readable;
use function is_string;
use function json_encode;
use function pathinfo;
use function realpath;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Pre-test compatibility diagnostics for one or more OpenAPI documents.
 *
 * @phpstan-type DoctorOptions array{specs?: list<string>, strip_prefixes?: list<string>, format?: string, allow_remote_refs?: bool, phpunit_snippet?: bool, help?: bool, invalid_options?: list<string>}
 * @phpstan-type DoctorIssue array{severity: 'error'|'warning'|'skipped', category: string, spec: ?string, message: string, suggestion: ?string}
 * @phpstan-type SpecResult array{path: string, name: string, openapi: string, dialect: string, operations: int, responses: int}
 *
 * @internal The `openapi-contract doctor` CLI is the supported API.
 */
final class DoctorCommand
{
    public const JSON_SCHEMA_VERSION = 1;
    public const EXIT_OK = 0;
    public const EXIT_DIAGNOSTIC_FAILURE = 1;
    public const EXIT_USAGE = 2;

    /** @param null|callable(string): void $stdoutWriter */
    public function __construct(
        private mixed $stdoutWriter = null,
        private mixed $stderrWriter = null,
        private mixed $remoteTransportFactory = null,
    ) {}

    /**
     * @param list<string> $argv
     *
     * @return DoctorOptions
     */
    public static function parseArgv(array $argv): array
    {
        $options = ['specs' => [], 'strip_prefixes' => [], 'invalid_options' => []];

        foreach ($argv as $arg) {
            if ($arg === 'doctor') {
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

            switch ($name) {
                case 'spec':
                    $options['specs'] = [
                        ...$options['specs'],
                        ...array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== '')),
                    ];

                    break;
                case 'strip_prefix':
                    $options['strip_prefixes'] = [
                        ...$options['strip_prefixes'],
                        ...array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== '')),
                    ];

                    break;
                case 'format':
                    $options['format'] = $value;

                    break;
                case 'allow_remote_refs':
                    $options['allow_remote_refs'] = !in_array($value, ['0', 'false', 'no'], true);

                    break;
                case 'phpunit_snippet':
                    $options['phpunit_snippet'] = !in_array($value, ['0', 'false', 'no'], true);

                    break;
                default:
                    $options['invalid_options'][] = '--' . str_replace('_', '-', $name);
            }
        }

        return $options;
    }

    public static function usage(): string
    {
        return <<<'USAGE'
            openapi-contract doctor — check whether this package can load and enforce your contract.

            Usage:
              openapi-contract doctor --spec=<path> [--spec=<path> ...] [options]

            Options:
              --spec=<path[,path]>       JSON/YAML entry document. Repeat for multiple specs.
              --strip-prefix=<prefix>    Request-path prefix used by PHPUnit. Repeat as needed.
              --format=text|json         Output format (default: text).
              --allow-remote-refs        Resolve HTTP(S) refs with an installed Guzzle or
                                         Symfony PSR-18 implementation.
              --phpunit-snippet          Include the equivalent PHPUnit extension XML.
              --help                     Show this message.

            Exit codes:
              0  All specs are compatible (warnings/skipped features may be present).
              1  A spec cannot be loaded or enforced.
              2  Command-line usage is invalid.

            USAGE;
    }

    /** @param DoctorOptions $options */
    public function run(array $options): int
    {
        if (($options['help'] ?? false) === true) {
            $this->writeStdout(self::usage());

            return self::EXIT_OK;
        }

        $format = $options['format'] ?? 'text';
        $invalid = $options['invalid_options'] ?? [];
        if (!in_array($format, ['text', 'json'], true) || $invalid !== [] || ($options['specs'] ?? []) === []) {
            $message = $invalid !== []
                ? 'Unknown argument(s): ' . implode(', ', $invalid)
                : (($options['specs'] ?? []) === [] ? 'At least one --spec is required.' : "Unsupported --format={$format}.");
            $this->writeStderr("[OpenAPI Doctor] {$message}\n\n" . self::usage());

            return self::EXIT_USAGE;
        }

        $allowRemoteRefs = $options['allow_remote_refs'] ?? false;
        $transport = $allowRemoteRefs ? $this->remoteTransport() : null;
        if ($allowRemoteRefs && $transport === null) {
            $report = $this->report([], [[
                'severity' => 'error',
                'category' => 'dependency',
                'spec' => null,
                'message' => '--allow-remote-refs requires an installed PSR-18/PSR-17 implementation.',
                'suggestion' => 'Install guzzlehttp/guzzle + guzzlehttp/psr7, or symfony/http-client.',
            ]], $options);
            $this->render($report, $format);

            return self::EXIT_DIAGNOSTIC_FAILURE;
        }

        $specResults = [];
        $issues = [];
        foreach ($options['specs'] as $path) {
            $this->diagnoseSpec($path, $options['strip_prefixes'] ?? [], $allowRemoteRefs, $transport, $specResults, $issues);
        }

        $report = $this->report($specResults, $issues, $options);
        $this->render($report, $format);

        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                return self::EXIT_DIAGNOSTIC_FAILURE;
            }
        }

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $stripPrefixes
     * @param null|array{0: ClientInterface, 1: RequestFactoryInterface} $transport
     * @param list<SpecResult> $specResults
     * @param list<DoctorIssue> $issues
     */
    private function diagnoseSpec(
        string $inputPath,
        array $stripPrefixes,
        bool $allowRemoteRefs,
        ?array $transport,
        array &$specResults,
        array &$issues,
    ): void {
        $path = $this->absolutise($inputPath);
        $label = $inputPath;
        if (!is_file($path) || !is_readable($path)) {
            $issues[] = $this->issue('error', 'io', $label, "Spec is not a readable file: {$path}", 'Check the path and file permissions.');

            return;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            $issues[] = $this->issue('error', 'parser', $label, "Unsupported spec extension: .{$extension}", 'Use .json, .yaml, or .yml.');

            return;
        }

        $name = pathinfo($path, PATHINFO_FILENAME);
        $basePath = pathinfo($path, PATHINFO_DIRNAME);
        foreach (['json', 'yaml', 'yml'] as $candidateExtension) {
            $candidate = $basePath . '/' . $name . '.' . $candidateExtension;
            if (!is_file($candidate)) {
                continue;
            }
            if (realpath($candidate) !== realpath($path)) {
                $issues[] = $this->issue(
                    'error',
                    'configuration',
                    $label,
                    sprintf('The runtime loader selects `%s` before the requested `%s`.', $candidate, $path),
                    'Remove or rename the shadowing entry document so the doctor and PHPUnit select the same spec.',
                );

                return;
            }

            break;
        }
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if ($severity !== E_USER_WARNING) {
                return false;
            }
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            OpenApiSpecLoader::reset();
            OpenApiSchemaConverter::resetWarningStateForTesting();
            OpenApiSpecLoader::configure(
                $basePath,
                $stripPrefixes,
                $transport[0] ?? null,
                $transport[1] ?? null,
                $allowRemoteRefs,
            );
            $spec = OpenApiSpecLoader::load($name);
            $version = OpenApiVersion::fromSpec($spec);
            $dialect = OpenApiSchemaDialect::fromSpec($spec, $version);
            [$operations, $responses] = $this->inspectStructure($spec, $label, $issues);
            $this->inspectSchemas($spec, $version, $dialect, new DiscriminatorContext($spec, true));
            $this->inspectSkippedFeatures($spec, $label, $issues);

            $specResults[] = [
                'path' => $path,
                'name' => $name,
                'openapi' => is_string($spec['openapi'] ?? null) ? $spec['openapi'] : $version->value,
                'dialect' => $dialect,
                'operations' => $operations,
                'responses' => $responses,
            ];
        } catch (InvalidOpenApiSpecException $e) {
            $issues[] = $this->issue('error', $this->categoryForException($e), $label, $e->getMessage(), $this->suggestionForException($e));
        } catch (MalformedDiscriminatorException $e) {
            $issues[] = $this->issue('error', 'structure', $label, $e->getMessage(), 'Fix the discriminator definition or explicitly disable enforcement in PHPUnit.');
        } catch (InvalidArgumentException|SpecFileNotFoundException $e) {
            $issues[] = $this->issue('error', 'configuration', $label, $e->getMessage(), null);
        } catch (Throwable $e) {
            $issues[] = $this->issue('error', 'internal', $label, $e->getMessage(), 'Report this diagnostic failure with the spec and stack trace.');
        } finally {
            restore_error_handler();
            OpenApiSpecLoader::reset();
            OpenApiSchemaConverter::resetWarningStateForTesting();
        }

        foreach ($warnings as $warning) {
            $issues[] = $this->issue('warning', $this->warningCategory($warning), $label, $warning, null);
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<DoctorIssue> $issues
     *
     * @return array{0: int, 1: int}
     */
    private function inspectStructure(array $spec, string $label, array &$issues): array
    {
        $paths = $spec['paths'] ?? null;
        if (MalformedSpecNode::isMalformed($paths)) {
            $issues[] = $this->issue('error', 'structure', $label, sprintf('`paths` must be an object, got %s.', MalformedSpecNode::describe($paths)), null);

            return [0, 0];
        }

        $operationCount = 0;
        $responseCount = 0;
        foreach ($paths as $path => $pathItem) {
            if (MalformedSpecNode::isMalformed($pathItem)) {
                $issues[] = $this->issue('error', 'structure', $label, sprintf('Path `%s` must be an object, got %s.', (string) $path, MalformedSpecNode::describe($pathItem)), null);

                continue;
            }
            foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                $operation = $declared['operation'];
                if (MalformedSpecNode::isMalformed($operation)) {
                    $issues[] = $this->issue('error', 'structure', $label, sprintf('Operation `%s %s` must be an object, got %s.', $declared['method'], (string) $path, MalformedSpecNode::describe($operation)), null);

                    continue;
                }
                $operationCount++;
                $responses = $operation['responses'] ?? null;
                if (MalformedSpecNode::isMalformed($responses)) {
                    $issues[] = $this->issue(
                        'error',
                        'structure',
                        $label,
                        sprintf('Operation `%s %s` has an invalid `responses` object: got %s.', $declared['method'], (string) $path, MalformedSpecNode::describe($responses)),
                        null,
                    );

                    continue;
                }

                foreach ($responses as $status => $response) {
                    if ($this->inspectResponseDefinition($response, $declared['method'], (string) $path, (string) $status, $label, $issues)) {
                        $responseCount++;
                    }
                }
            }
        }

        return [$operationCount, $responseCount];
    }

    /**
     * Mirror the response-side runtime guards so doctor never reports a
     * response as enforceable when validation would reject its structure.
     *
     * @param list<DoctorIssue> $issues
     */
    private function inspectResponseDefinition(
        mixed $response,
        string $method,
        string $path,
        string $status,
        string $label,
        array &$issues,
    ): bool {
        $location = sprintf('responses[%s]', $status);
        if (MalformedSpecNode::isMalformed($response)) {
            $issues[] = $this->malformedStructureIssue($label, $method, $path, $location, $response);

            return false;
        }

        if (!array_key_exists('content', $response)) {
            return true;
        }

        $content = $response['content'];
        if (MalformedSpecNode::isMalformed($content)) {
            $issues[] = $this->malformedStructureIssue($label, $method, $path, $location . '.content', $content);

            return false;
        }

        $valid = true;
        foreach ($content as $mediaType => $mediaTypeSpec) {
            $mediaLocation = sprintf('%s.content["%s"]', $location, (string) $mediaType);
            if (MalformedSpecNode::isMalformed($mediaTypeSpec)) {
                $issues[] = $this->malformedStructureIssue($label, $method, $path, $mediaLocation, $mediaTypeSpec);
                $valid = false;

                continue;
            }

            foreach (['schema', 'itemSchema'] as $schemaKey) {
                if (!array_key_exists($schemaKey, $mediaTypeSpec) || !MalformedSpecNode::isMalformed($mediaTypeSpec[$schemaKey])) {
                    continue;
                }
                $issues[] = $this->malformedStructureIssue(
                    $label,
                    $method,
                    $path,
                    $mediaLocation . '.' . $schemaKey,
                    $mediaTypeSpec[$schemaKey],
                );
                $valid = false;
            }
        }

        return $valid;
    }

    /** @return DoctorIssue */
    private function malformedStructureIssue(
        string $label,
        string $method,
        string $path,
        string $location,
        mixed $node,
    ): array {
        return $this->issue(
            'error',
            'structure',
            $label,
            sprintf('Malformed `%s` for %s %s: expected object, got %s.', $location, $method, $path, MalformedSpecNode::describe($node)),
            null,
        );
    }

    /** @param array<string, mixed> $spec */
    private function inspectSchemas(
        array $spec,
        OpenApiVersion $version,
        string $dialect,
        DiscriminatorContext $discriminator,
    ): void {
        $this->walkForSchemas($spec, $version, $dialect, $discriminator, null);
    }

    /** @param array<mixed> $node */
    private function walkForSchemas(
        array $node,
        OpenApiVersion $version,
        string $dialect,
        DiscriminatorContext $discriminator,
        ?string $parentKey,
    ): void {
        if ($parentKey === 'schema') {
            /** @var array<string, mixed> $node */
            OpenApiSchemaConverter::convert($node, $version, discriminator: $discriminator, jsonSchemaDialect: $dialect);

            return;
        }

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($key === 'schemas') {
                foreach ($value as $schema) {
                    if (is_array($schema)) {
                        /** @var array<string, mixed> $schema */
                        OpenApiSchemaConverter::convert($schema, $version, discriminator: $discriminator, jsonSchemaDialect: $dialect);
                    }
                }

                continue;
            }
            $this->walkForSchemas($value, $version, $dialect, $discriminator, is_string($key) ? $key : null);
        }
    }

    /** @return null|array{0: ClientInterface, 1: RequestFactoryInterface} */
    private function remoteTransport(): ?array
    {
        if (is_callable($this->remoteTransportFactory)) {
            $transport = ($this->remoteTransportFactory)();
            if (is_array($transport) &&
                ($transport[0] ?? null) instanceof ClientInterface &&
                ($transport[1] ?? null) instanceof RequestFactoryInterface
            ) {
                return [$transport[0], $transport[1]];
            }

            return null;
        }
        if (class_exists(Client::class) && class_exists(HttpFactory::class)) {
            return [new Client(), new HttpFactory()];
        }
        if (class_exists(Psr18Client::class)) {
            $client = new Psr18Client();
            if ($client instanceof ClientInterface && $client instanceof RequestFactoryInterface) {
                return [$client, $client];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<DoctorIssue> $issues
     */
    private function inspectSkippedFeatures(array $spec, string $label, array &$issues): void
    {
        $schemes = $spec['components']['securitySchemes'] ?? null;
        if (!is_array($schemes)) {
            return;
        }

        foreach ($schemes as $name => $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $type = $scheme['type'] ?? null;
            $isUnsupportedHttp = $type === 'http' && ($scheme['scheme'] ?? null) !== 'bearer';
            if (!in_array($type, ['oauth2', 'openIdConnect', 'mutualTLS'], true) && !$isUnsupportedHttp) {
                continue;
            }
            $issues[] = $this->issue(
                'skipped',
                'feature',
                $label,
                sprintf('Security scheme `%s` (%s) is recognized but not enforced.', (string) $name, $type),
                'Keep a separate authentication test until this scheme is supported.',
            );
        }
    }

    /**
     * @param list<SpecResult> $specs
     * @param list<DoctorIssue> $issues
     * @param DoctorOptions $options
     *
     * @return array<string, mixed>
     */
    private function report(array $specs, array $issues, array $options): array
    {
        $counts = ['errors' => 0, 'warnings' => 0, 'skipped' => 0];
        foreach ($issues as $issue) {
            $countKey = $issue['severity'] === 'skipped' ? 'skipped' : $issue['severity'] . 's';
            $counts[$countKey]++;
        }
        $operations = 0;
        $responses = 0;
        foreach ($specs as $spec) {
            $operations += $spec['operations'];
            $responses += $spec['responses'];
        }

        return [
            'schemaVersion' => self::JSON_SCHEMA_VERSION,
            'status' => $counts['errors'] > 0 ? 'error' : ($counts['warnings'] > 0 || $counts['skipped'] > 0 ? 'warning' : 'ok'),
            'summary' => [
                'specs' => count($specs),
                'operations' => $operations,
                'responses' => $responses,
                ...$counts,
            ],
            'specs' => $specs,
            'issues' => $issues,
            'phpunit' => ($options['phpunit_snippet'] ?? false) ? $this->phpunitSnippet($specs, $options['strip_prefixes'] ?? []) : null,
        ];
    }

    /** @param array<string, mixed> $report */
    private function render(array $report, string $format): void
    {
        if ($format === 'json') {
            $this->writeStdout(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            return;
        }

        /** @var array{specs: int, operations: int, responses: int, errors: int, warnings: int, skipped: int} $summary */
        $summary = $report['summary'];
        $lines = [
            sprintf('OpenAPI Doctor: %s', $report['status'] === 'error' ? 'FAILED' : 'COMPATIBLE'),
            sprintf('Specs: %d | Operations: %d | Responses: %d', $summary['specs'], $summary['operations'], $summary['responses']),
        ];
        foreach ($report['specs'] as $spec) {
            $lines[] = sprintf('  OK %s (OpenAPI %s, %d operations, %d responses)', $spec['path'], $spec['openapi'], $spec['operations'], $spec['responses']);
        }
        foreach ($report['issues'] as $issue) {
            $lines[] = sprintf('  %s [%s]%s %s', strtoupper($issue['severity']), $issue['category'], $issue['spec'] !== null ? ' ' . $issue['spec'] . ':' : '', $issue['message']);
            if ($issue['suggestion'] !== null) {
                $lines[] = '    Fix: ' . $issue['suggestion'];
            }
        }
        $lines[] = sprintf('Errors: %d | Warnings: %d | Skipped: %d', $summary['errors'], $summary['warnings'], $summary['skipped']);
        if (is_string($report['phpunit'])) {
            $lines[] = "\nEquivalent PHPUnit configuration:\n" . $report['phpunit'];
        }
        $this->writeStdout(implode("\n", $lines) . "\n");
    }

    /**
     * @param list<SpecResult> $specs
     * @param list<string> $stripPrefixes
     */
    private function phpunitSnippet(array $specs, array $stripPrefixes): ?string
    {
        if ($specs === []) {
            return null;
        }
        $basePath = dirname($specs[0]['path']);
        foreach ($specs as $spec) {
            if (dirname($spec['path']) !== $basePath) {
                return '<!-- Specs use different directories; one PHPUnit extension instance requires a shared spec_base_path. -->';
            }
        }
        $names = implode(',', array_map(static fn(array $spec): string => $spec['name'], $specs));
        $prefixes = implode(',', $stripPrefixes);
        $cwd = getcwd();
        if ($cwd !== false && str_starts_with($basePath, rtrim($cwd, '/') . '/')) {
            $basePath = substr($basePath, strlen(rtrim($cwd, '/')) + 1);
        }
        $basePath = htmlspecialchars($basePath, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $names = htmlspecialchars($names, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $prefixLine = $prefixes === '' ? '' : "\n        <parameter name=\"strip_prefixes\" value=\"" . htmlspecialchars($prefixes, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>';

        return <<<XML
            <extensions>
                <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
                    <parameter name="spec_base_path" value="{$basePath}"/>
                    <parameter name="specs" value="{$names}"/>{$prefixLine}
                </bootstrap>
            </extensions>
            XML;
    }

    private function categoryForException(InvalidOpenApiSpecException $e): string
    {
        $reason = $e->reason->name;
        if (str_contains($reason, 'Ref') || $reason === 'CircularRef') {
            return 'references';
        }
        if (str_contains($reason, 'Dialect')) {
            return 'dialect';
        }
        if (str_contains($reason, 'Version')) {
            return 'version';
        }
        if (str_contains($reason, 'Yaml') || str_contains($reason, 'Json') || $reason === 'NonMappingRoot') {
            return 'parser';
        }

        return 'spec';
    }

    private function suggestionForException(InvalidOpenApiSpecException $e): ?string
    {
        return match ($e->reason->name) {
            'YamlLibraryMissing' => 'Run: composer require --dev symfony/yaml',
            'RemoteRefDisallowed' => 'Re-run with --allow-remote-refs and install a supported PSR-18 implementation, or bundle the spec.',
            'HttpClientNotConfigured' => 'Install Guzzle or Symfony HttpClient and re-run with --allow-remote-refs.',
            default => null,
        };
    }

    private function warningCategory(string $warning): string
    {
        if (str_contains($warning, '$self')) {
            return 'references';
        }
        if (str_contains($warning, 'keyword') || str_contains($warning, 'format')) {
            return 'keyword';
        }

        return 'compatibility';
    }

    /** @return DoctorIssue */
    private function issue(string $severity, string $category, ?string $spec, string $message, ?string $suggestion): array
    {
        /** @var 'error'|'skipped'|'warning' $severity */
        return compact('severity', 'category', 'spec', 'message', 'suggestion');
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
