<?php

declare(strict_types=1);

namespace Studio\Gesso\Cli;

use const DIRECTORY_SEPARATOR;
use const E_USER_WARNING;
use const FILTER_VALIDATE_INT;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PATHINFO_DIRNAME;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Config\InvalidGessoConfigurationException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\MalformedDiscriminatorException;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\Internal\ArgvParser;
use Studio\Gesso\Internal\CliDocuments;
use Studio\Gesso\Internal\CliIo;
use Studio\Gesso\Internal\FailureCatalogue;
use Studio\Gesso\Internal\HttpRefLoader;
use Studio\Gesso\Internal\SpecPath;
use Studio\Gesso\Internal\ToolVersion;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Request\ParameterCollector;
use Studio\Gesso\Validation\Request\SchemeKind;
use Studio\Gesso\Validation\Request\SecurityValidator;
use Studio\Gesso\Validation\Support\BodyStructureInspector;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\DiscriminatorContext;
use Studio\Gesso\Validation\Support\FormBodyDecoder;
use Studio\Gesso\Validation\Support\MalformedSpecNode;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function class_exists;
use function compact;
use function count;
use function dirname;
use function filesize;
use function filter_var;
use function get_debug_type;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_dir;
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
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function var_export;

/**
 * Pre-test compatibility diagnostics for one or more OpenAPI documents.
 *
 * @phpstan-type DoctorOptions array{specs?: list<string>, strip_prefixes?: list<string>, remote_ref_hosts?: list<string>, acknowledged_unvalidatable_schemes?: list<string>, remote_ref_max_bytes?: int|string, local_ref_root?: string, config_ref_root?: string, config_specs?: list<string>, config_escaping_names?: list<string>, config?: string, format?: string, output_file?: string, allow_remote_refs?: bool, phpunit_snippet?: bool, emit_config?: bool, help?: bool, invalid_options?: list<string>}
 * @phpstan-type DoctorIssue array{severity: 'error'|'warning'|'skipped', category: string, spec: ?string, message: string, suggestion: ?string, code: ?string, docs_url: ?string}
 * @phpstan-type SpecResult array{path: string, name: string, openapi: string, dialect: string, operations: int, responses: int}
 *
 * @internal The `gesso doctor` CLI surface is the supported API.
 */
final class DoctorCommand
{
    use CliIo;

    public const JSON_SCHEMA_VERSION = 1;
    public const EXIT_DIAGNOSTIC_FAILURE = self::EXIT_FAILURE;
    private const USAGE_PREFIX = '[OpenAPI Doctor]';

    /** @param null|callable(string): void $stdoutWriter */
    public function __construct(
        private mixed $stdoutWriter = null,
        private mixed $stderrWriter = null,
        private mixed $remoteTransportFactory = null,
        private readonly string $invocation = 'gesso doctor',
    ) {}

    /**
     * @param list<string> $argv
     *
     * @return DoctorOptions
     */
    public static function parseArgv(array $argv): array
    {
        /** @var DoctorOptions */
        return ArgvParser::parse(
            $argv,
            flags: ['allow_remote_refs', 'phpunit_snippet', 'emit_config'],
            values: ['format', 'output_file', 'remote_ref_max_bytes', 'local_ref_root', 'config'],
            lists: [
                'spec' => 'specs',
                'strip_prefix' => 'strip_prefixes',
                'remote_ref_host' => 'remote_ref_hosts',
                'acknowledge_unvalidatable_scheme' => 'acknowledged_unvalidatable_schemes',
            ],
        );
    }

    public static function usage(string $invocation = 'gesso doctor'): string
    {
        return <<<USAGE
            {$invocation} — check whether this package can load and enforce your contract.

            Usage:
              {$invocation} --spec=<path> [--spec=<path> ...] [options]

            Options:
              --spec=<path[,path]>       JSON/YAML entry document. Repeat for multiple specs.
              --strip-prefix=<prefix>    Request-path prefix used by PHPUnit. Repeat as needed.
              --local-ref-root=<dir>     Filesystem boundary for local refs (default: each
                                         entry document's directory).
              --format=text|json         Output format (default: text).
              --output-file=<path>       Write the report there instead of stdout.
              --allow-remote-refs        Resolve HTTP(S) refs with an installed Guzzle or
                                         Symfony PSR-18 implementation.
              --remote-ref-host=<host>   Exact host allowed for HTTP(S) refs. Required with
                                         --allow-remote-refs; repeat as needed.
              --remote-ref-max-bytes=<n> Maximum bytes read per remote document
                                         (default: 10485760).
              --acknowledge-unvalidatable-scheme=<name[,name]>
                                         Security scheme name (components.securitySchemes
                                         key) acknowledged as unvalidatable. Repeat as
                                         needed. Mirrors the acknowledged_unvalidatable_schemes
                                         PHPUnit / Laravel setting.
              --phpunit-snippet          Include the equivalent PHPUnit extension XML.
              --emit-config              Print the equivalent gesso.php to stdout (the
                                         report goes to stderr or --output-file).
                                         Redirect: > gesso.php
              --config=<path>            gesso.php to default the options above from
                                         (default: ./gesso.php when present). Explicit
                                         flags win; see docs/configuration.md.
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
            $this->writeStdout(self::usage($this->invocation));

            return self::EXIT_OK;
        }

        $format = $options['format'] ?? 'text';
        $invalid = $options['invalid_options'] ?? [];
        if ($invalid !== []) {
            return $this->usageError('Unknown argument(s): ' . implode(', ', $invalid), self::USAGE_PREFIX);
        }
        if (($options['config'] ?? null) === '') {
            return $this->usageError('--config must name a gesso.php file.', self::USAGE_PREFIX);
        }

        // `--emit-config > gesso.php` lets the shell truncate the default file
        // before doctor runs; an empty default file is not a config to read then.
        $default = GessoConfig::FILENAME;
        if (!($options['emit_config'] ?? false) || isset($options['config']) || !is_file($default) || filesize($default) !== 0) {
            try {
                $options = self::withSharedDefaults($options, ConfigurationBridge::phpunit(GessoConfig::load($options['config'] ?? null)));
            } catch (InvalidGessoConfigurationException $e) {
                return $this->usageError($e->getMessage(), self::USAGE_PREFIX);
            }
        }
        if (($options['specs'] ?? []) === [] && ($options['config_escaping_names'] ?? []) === []) {
            return $this->usageError('At least one --spec is required.', self::USAGE_PREFIX);
        }
        if (!in_array($format, ['text', 'json'], true)) {
            return $this->usageError("Unsupported --format={$format}.", self::USAGE_PREFIX);
        }

        $allowRemoteRefs = $options['allow_remote_refs'] ?? false;
        $remoteRefHosts = $options['remote_ref_hosts'] ?? [];
        if (($allowRemoteRefs && $remoteRefHosts === []) || (!$allowRemoteRefs && $remoteRefHosts !== [])) {
            $message = $allowRemoteRefs
                ? '--allow-remote-refs requires at least one --remote-ref-host.'
                : '--remote-ref-host requires --allow-remote-refs.';

            return $this->usageError($message, self::USAGE_PREFIX);
        }

        $hasRemoteRefMaxBytes = array_key_exists('remote_ref_max_bytes', $options);
        if ($hasRemoteRefMaxBytes && !$allowRemoteRefs) {
            return $this->usageError('--remote-ref-max-bytes requires --allow-remote-refs.', self::USAGE_PREFIX);
        }
        $maxRemoteRefBytes = $hasRemoteRefMaxBytes
            ? filter_var($options['remote_ref_max_bytes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : HttpRefLoader::DEFAULT_MAX_RESPONSE_BYTES;
        if ($maxRemoteRefBytes === false) {
            return $this->usageError('--remote-ref-max-bytes must be a positive integer.', self::USAGE_PREFIX);
        }

        if (array_key_exists('local_ref_root', $options)) {
            $localRefRoot = realpath(trim($options['local_ref_root']));
            if ($localRefRoot === false || !is_dir($localRefRoot)) {
                return $this->usageError('--local-ref-root must be an existing directory.', self::USAGE_PREFIX);
            }
            $options['local_ref_root'] = $localRefRoot;
        }

        $transport = $allowRemoteRefs ? $this->remoteTransport() : null;
        $everySpecUsesConfigRoot = false;
        $specResults = [];
        $issues = [];
        if ($allowRemoteRefs && $transport === null) {
            $issues[] = $this->issue(
                'error',
                'dependency',
                null,
                '--allow-remote-refs requires an installed PSR-18/PSR-17 implementation.',
                'Install guzzlehttp/guzzle + guzzlehttp/psr7, or symfony/http-client.',
            );
        } else {
            foreach ($options['config_escaping_names'] ?? [] as $name) {
                // The runtime loader rejects these names before touching the disk.
                $issues[] = $this->issue(
                    'error',
                    'configuration',
                    $name,
                    "spec.names entry '{$name}' leaves spec.base_path; the runtime loader rejects it.",
                    'Name specs relative to spec.base_path, without `..` segments or absolute paths.',
                );
            }
            $configRoot = $options['config_ref_root'] ?? null;
            $everySpecUsesConfigRoot = $configRoot !== null;
            foreach ($options['specs'] ?? [] as $path) {
                $root = $options['local_ref_root'] ?? null;
                if ($root === null && $configRoot !== null) {
                    // Config-derived specs always stay confined to spec.base_path,
                    // as at runtime; an explicit --spec inherits it per spec,
                    // only when it lies inside.
                    $real = realpath($this->absolutise($path));
                    $root = in_array($path, $options['config_specs'] ?? [], true) || ($real !== false && self::pathIsInsideRoot($real, $configRoot))
                        ? $configRoot
                        : null;
                    $everySpecUsesConfigRoot = $everySpecUsesConfigRoot && $root !== null;
                }
                $this->diagnoseSpec(
                    $path,
                    $options['strip_prefixes'] ?? [],
                    $allowRemoteRefs,
                    $remoteRefHosts,
                    $maxRemoteRefBytes,
                    $root,
                    $transport,
                    $options['acknowledged_unvalidatable_schemes'] ?? [],
                    $specResults,
                    $issues,
                );
            }
        }

        if (!isset($options['local_ref_root']) && $everySpecUsesConfigRoot && isset($options['config_ref_root'])) {
            // The emitted config and snippet describe the root every spec used.
            $options['local_ref_root'] = $options['config_ref_root'];
        }
        $report = $this->report($specResults, $issues, $options);
        $emitConfig = $options['emit_config'] ?? false;
        $basePath = $this->sharedSpecBasePath($specResults, $options['local_ref_root'] ?? null);
        if ($emitConfig && $specResults !== [] && $basePath === null) {
            return $this->usageError(
                '--emit-config requires every --spec to share one directory; pass --local-ref-root=<dir>.',
                self::USAGE_PREFIX,
            );
        }
        $rendered = $this->render($report, $format);

        try {
            if ($emitConfig && ($options['output_file'] ?? '') === '') {
                // stdout carries only the gesso.php source, so `> gesso.php` works.
                $this->writeStderr($rendered);
            } else {
                $this->writeReport($rendered, $options['output_file'] ?? null);
            }
        } catch (RuntimeException $e) {
            return $this->usageError($e->getMessage(), self::USAGE_PREFIX);
        }

        // A rejected spec emits nothing: an empty redirected gesso.php fails
        // loudly on the next load instead of configuring a suite from it.
        if ($emitConfig && $basePath !== null && $report['status'] !== 'error') {
            $this->writeStdout($this->gessoConfigSource(
                $specResults,
                $options['strip_prefixes'] ?? [],
                $basePath,
                $options['acknowledged_unvalidatable_schemes'] ?? [],
            ));
        }

        foreach ($issues as $issue) {
            if ($issue['severity'] === 'error') {
                return self::EXIT_DIAGNOSTIC_FAILURE;
            }
        }

        return self::EXIT_OK;
    }

    /**
     * Issue #660: default the options the PHPUnit extension also reads from
     * `gesso.php`, so the doctor checks the bundle the suite enforces. The spec
     * list follows `spec.base_path` + `spec.names` (`front` when names are
     * absent, as in the extension), with the base path as the local-ref root
     * the runtime loader confines refs to. Explicit flags win.
     *
     * @param DoctorOptions $options
     * @param array<string, bool|float|int|list<string>|string> $shared
     *
     * @return DoctorOptions
     */
    private static function withSharedDefaults(array $options, array $shared): array
    {
        $basePath = $shared['spec_base_path'] ?? null;
        if (is_string($basePath)) {
            $basePath = realpath($basePath) ?: $basePath;
            if (($options['specs'] ?? []) === []) {
                $names = $shared['specs'] ?? ['front'];
                $names = is_array($names) ? $names : ['front'];
                $options['config_escaping_names'] = array_values(array_filter($names, SpecPath::escapesBase(...)));
                $options['specs'] = array_map(
                    static fn(string $name): string => self::cwdRelative(self::entryDocument($basePath, $name)),
                    array_values(array_filter($names, static fn(string $name): bool => !SpecPath::escapesBase($name))),
                );
                $options['config_specs'] = $options['specs'];
            }
            if (is_dir($basePath)) {
                $options['config_ref_root'] = $basePath;
            }
        }
        foreach (['strip_prefixes', 'acknowledged_unvalidatable_schemes'] as $key) {
            if (($options[$key] ?? []) === [] && is_array($shared[$key] ?? null)) {
                $options[$key] = $shared[$key];
            }
        }

        return $options;
    }

    /** The entry document the runtime loader selects for `$name` (`.json` when none exists, reported as unreadable). */
    private static function entryDocument(string $basePath, string $name): string
    {
        foreach (['json', 'yaml', 'yml'] as $extension) {
            if (is_file("{$basePath}/{$name}.{$extension}")) {
                return "{$basePath}/{$name}.{$extension}";
            }
        }

        return "{$basePath}/{$name}.json";
    }

    /** `$path` relative to the cwd when it is the cwd or lies below it; `$path` itself otherwise. */
    private static function cwdRelative(string $path): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $path;
        }
        $cwd = rtrim($cwd, '/');
        if ($path === $cwd) {
            return '.';
        }

        return str_starts_with($path, $cwd . '/') ? substr($path, strlen($cwd) + 1) : $path;
    }

    private static function pathIsInsideRoot(string $path, string $root): bool
    {
        $root = rtrim($root, '/\\');

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function symfonyHttpClientWithoutRedirects(): HttpClientInterface
    {
        // Symfony follows up to 20 redirects by default, below HttpRefLoader's
        // --remote-ref-host check. Returning the 3xx lets HttpRefLoader reject
        // it, matching Guzzle's PSR-18 behaviour.
        return HttpClient::create(['max_redirects' => 0]);
    }

    /**
     * @param list<string> $stripPrefixes
     * @param list<string> $remoteRefHosts
     * @param positive-int $maxRemoteRefBytes
     * @param null|string $localRefRoot canonical configured local-ref boundary
     * @param null|array{0: ClientInterface, 1: RequestFactoryInterface} $transport
     * @param list<string> $acknowledgedSchemes scheme names acknowledged as unvalidatable
     * @param list<SpecResult> $specResults
     * @param list<DoctorIssue> $issues
     */
    private function diagnoseSpec(
        string $inputPath,
        array $stripPrefixes,
        bool $allowRemoteRefs,
        array $remoteRefHosts,
        int $maxRemoteRefBytes,
        ?string $localRefRoot,
        ?array $transport,
        array $acknowledgedSchemes,
        array &$specResults,
        array &$issues,
    ): void {
        $path = $this->absolutise($inputPath);
        $label = $inputPath;
        if (!is_file($path) || !is_readable($path)) {
            $issues[] = $this->issue('error', 'io', $label, "Spec is not a readable file: {$path}", 'Check the path and file permissions.');

            return;
        }
        $path = realpath($path) ?: $path;

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            $issues[] = $this->issue('error', 'parser', $label, "Unsupported spec extension: .{$extension}", 'Use .json, .yaml, or .yml.');

            return;
        }

        $basePath = $localRefRoot ?? pathinfo($path, PATHINFO_DIRNAME);
        if ($localRefRoot !== null && !self::pathIsInsideRoot($path, $localRefRoot)) {
            $issues[] = $this->issue(
                'error',
                'configuration',
                $label,
                'The entry document is outside --local-ref-root.',
                'Choose a common trusted directory that contains the entry document and its local refs.',
            );

            return;
        }
        $relativePath = $localRefRoot === null
            ? pathinfo($path, PATHINFO_FILENAME) . '.' . $extension
            : substr($path, strlen(rtrim($localRefRoot, '/\\')) + 1);
        $name = substr($relativePath, 0, -(strlen($extension) + 1));
        $shadowing = CliDocuments::shadowingEntry($basePath, $name, $path);
        if ($shadowing !== null) {
            $issues[] = $this->issue(
                'error',
                'configuration',
                $label,
                sprintf('The runtime loader selects `%s` before the requested `%s`.', $shadowing, $path),
                'Remove or rename the shadowing entry document so the doctor and PHPUnit select the same spec.',
            );

            return;
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
                allowedRemoteRefHosts: $remoteRefHosts,
                maxRemoteRefBytes: $maxRemoteRefBytes,
            );
            $spec = OpenApiSpecLoader::load($name);
            $version = OpenApiVersion::fromSpec($spec);
            $dialect = OpenApiSchemaDialect::fromSpec($spec, $version);
            [$operations, $responses] = $this->inspectStructure($spec, $version, $label, $issues);
            $this->walkForSchemas($spec, $version, $dialect, new DiscriminatorContext($spec, true), null);
            $this->inspectSkippedFeatures($spec, $label, $acknowledgedSchemes, $issues);
            $this->inspectWebhooks($spec, $label, $issues);

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
    private function inspectStructure(array $spec, OpenApiVersion $version, string $label, array &$issues): array
    {
        // `paths` and an operation's `responses` are REQUIRED in OAS 3.0 but
        // optional from 3.1 on: a document may describe only `webhooks`, and an
        // operation may describe only its request. The runtime validators
        // already read both through `array_key_exists()` and treat an absent
        // key as "nothing to match", so only a node that is present with the
        // wrong type is a structure error.
        $optional = $version !== OpenApiVersion::V3_0;

        if ($optional && !array_key_exists('paths', $spec)) {
            return [0, 0];
        }

        $paths = $spec['paths'] ?? null;
        if (MalformedSpecNode::isMalformed($paths)) {
            $issues[] = $this->issue('error', 'structure', $label, sprintf('`paths` must be an object, got %s.', MalformedSpecNode::describe($paths)), null);

            return [0, 0];
        }

        $operationCount = 0;
        $responseCount = 0;
        foreach ($paths as $path => $pathItem) {
            // Runtime compiles every `paths` key into one matcher, so a template
            // the matcher rejects breaks validation for the whole spec.
            try {
                new OpenApiPathMatcher([(string) $path]);
            } catch (InvalidArgumentException $e) {
                $issues[] = $this->issue('error', 'structure', $label, $e->getMessage(), 'Put a literal between adjacent placeholders (`{a}-{b}`) and give each placeholder a unique name.');
            }
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
                $requestStructureValid = true;
                foreach (BodyStructureInspector::request($operation) as $defect) {
                    $issues[] = $this->malformedStructureIssue($label, $declared['method'], (string) $path, $defect['location'], $defect['node']);
                    $requestStructureValid = false;
                }
                $this->inspectUnenforcedRequest($declared['method'], (string) $path, $pathItem, $operation, $requestStructureValid, $label, $issues);
                if ($optional && !array_key_exists('responses', $operation)) {
                    continue;
                }

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
                    // Specification extensions are legal on a Responses Object
                    // and are skipped at runtime; inspecting them would report
                    // a structure error against a valid document.
                    if (str_starts_with((string) $status, 'x-')) {
                        continue;
                    }
                    if ($this->inspectResponseDefinition($response, $declared['method'], (string) $path, (string) $status, $label, $issues, $version === OpenApiVersion::V3_2)) {
                        $responseCount++;
                        /** @var array<string, mixed> $response */
                        $this->inspectUnenforcedContent($response['content'] ?? null, true, sprintf('%s %s response %s', $declared['method'], (string) $path, (string) $status), $label, $issues);
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
        bool $inspectEncoding,
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
        foreach (BodyStructureInspector::content($content, $location . '.content', $inspectEncoding) as $defect) {
            $issues[] = $this->malformedStructureIssue($label, $method, $path, $defect['location'], $defect['node']);
            $valid = false;
        }

        return $valid;
    }

    /**
     * Report the request-side parts runtime validation accepts without
     * checking: cookie parameters, `content`-based parameters, and request
     * media types it skips. Parameters are merged by the runtime collector so
     * path-level overrides resolve exactly as they do during validation.
     *
     * @param array<string, mixed> $pathItem
     * @param array<string, mixed> $operation
     * @param list<DoctorIssue> $issues
     */
    private function inspectUnenforcedRequest(
        string $method,
        string $path,
        array $pathItem,
        array $operation,
        bool $requestStructureValid,
        string $label,
        array &$issues,
    ): void {
        foreach (ParameterCollector::collect($method, $path, $pathItem, $operation)->parameters as $parameter) {
            $location = sprintf('%s.%s', (string) $parameter['in'], (string) $parameter['name']);
            if ($parameter['in'] === 'cookie') {
                $issues[] = $this->issue(
                    'skipped',
                    'feature',
                    $label,
                    sprintf('Cookie parameter `%s` for %s %s is not validated.', $location, $method, $path),
                    'Keep a separate test for the cookie until cookie parameters are supported.',
                );
            } elseif ($parameter['in'] !== 'querystring' && !isset($parameter['schema']) && isset($parameter['content'])) {
                // Parameter validators read only `schema`; outside the 3.2
                // querystring, a `content` map is never consulted.
                $issues[] = $this->issue(
                    'skipped',
                    'feature',
                    $label,
                    sprintf('Parameter `%s` for %s %s is declared with `content`, which is not validated.', $location, $method, $path),
                    'Declare the parameter with `schema`, or keep a separate test for it.',
                );
            }
        }

        $callbacks = $operation['callbacks'] ?? null;
        foreach (is_array($callbacks) ? $callbacks : [] as $name => $_callback) {
            $issues[] = $this->issue(
                'skipped',
                'feature',
                $label,
                sprintf('Callback `%s` on %s %s is not validated.', (string) $name, $method, $path),
                null,
            );
        }

        if ($requestStructureValid && is_array($operation['requestBody'] ?? null)) {
            $this->inspectUnenforcedContent($operation['requestBody']['content'] ?? null, false, sprintf('%s %s request body', $method, $path), $label, $issues);
        }
    }

    /**
     * Mirror the media-type branches of `RequestBodyValidator::validate()` and
     * `ResponseSchemaResolver::resolveResponseSchema()`: report each declared
     * media type whose matching body the runtime returns as Skipped. Only
     * called on content the structure checks accepted.
     *
     * @param list<DoctorIssue> $issues
     */
    private function inspectUnenforcedContent(mixed $content, bool $response, string $where, string $label, array &$issues): void
    {
        if (!is_array($content)) {
            return;
        }
        /** @var array<string, mixed> $content */
        $jsonKeys = ContentTypeMatcher::findJsonContentTypes($content);
        foreach ($content as $mediaType => $mediaTypeSpec) {
            if (!is_array($mediaTypeSpec)) {
                continue;
            }
            $mediaType = (string) $mediaType;
            $normalized = ContentTypeMatcher::normalizeMediaType($mediaType);
            $hasSchema = isset($mediaTypeSpec['schema']);
            $reason = null;
            if ($hasSchema && strtolower($mediaType) !== $normalized && in_array($mediaType, $jsonKeys, true)) {
                // The runtime matches JSON keys literally, so a key carrying
                // media-type parameters (`; charset=utf-8`) is never selected.
                $reason = 'carries media-type parameters, which runtime validation does not match against a JSON body';
            } elseif (in_array($mediaType, $jsonKeys, true)) {
                // A JSON key validates `schema`; `itemSchema` alone is skipped.
                if (!$hasSchema && isset($mediaTypeSpec['itemSchema'])) {
                    $reason = 'uses `itemSchema` streaming, which is not validated';
                } elseif ($hasSchema && str_contains($normalized, '*')) {
                    // `application/*` validates JSON bodies only; a non-JSON
                    // body it matches is Skipped like any non-JSON schema.
                    $reason = 'validates JSON bodies only; a non-JSON body it matches declares a schema the JSON Schema engine does not evaluate';
                }
            } elseif (isset($mediaTypeSpec['itemSchema'])) {
                $reason = 'uses `itemSchema` streaming, which is not validated';
            } elseif ($hasSchema) {
                if ($response && $normalized === FormBodyDecoder::MULTIPART) {
                    $reason = 'is a multipart response, which is not reassembled into parts and is not validated';
                } elseif ($response ? $normalized !== FormBodyDecoder::URLENCODED : !FormBodyDecoder::isFormMediaType($normalized)) {
                    $reason = 'declares a non-JSON schema, which the JSON Schema engine does not evaluate';
                }
            }
            if ($reason !== null) {
                $issues[] = $this->issue(
                    'skipped',
                    'feature',
                    $label,
                    sprintf('Media type `%s` for %s %s; matching bodies are reported as Skipped.', $mediaType, $where, $reason),
                    'Keep a separate test for this body.',
                );
            }
        }
    }

    /**
     * Root `webhooks` are not addressable by request path, so runtime
     * validation never reaches them.
     *
     * @param array<string, mixed> $spec
     * @param list<DoctorIssue> $issues
     */
    private function inspectWebhooks(array $spec, string $label, array &$issues): void
    {
        if (!is_array($spec['webhooks'] ?? null)) {
            return;
        }
        foreach ($spec['webhooks'] as $name => $_pathItem) {
            $issues[] = $this->issue('skipped', 'feature', $label, sprintf('Webhook `%s` is not validated.', (string) $name), null);
        }
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
            // Psr18Client is both the PSR-18 client and a PSR-17 request
            // factory (since symfony/http-client 4.3).
            $client = new Psr18Client(self::symfonyHttpClientWithoutRedirects());

            return [$client, $client];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string> $acknowledgedSchemes scheme names acknowledged as unvalidatable (issue #445)
     * @param list<DoctorIssue> $issues
     */
    private function inspectSkippedFeatures(array $spec, string $label, array $acknowledgedSchemes, array &$issues): void
    {
        $schemes = [];
        $components = null;
        if (array_key_exists('components', $spec)) {
            $components = $spec['components'];
            if (!is_array($components)) {
                // A present-but-non-object `components` leaves every
                // referenced scheme unresolvable — runtime hard-errors with
                // "undefined scheme" as soon as a security requirement
                // exists. Distinguish it from an absent key and stop the
                // scheme inspection on the unusable node.
                $issues[] = $this->issue(
                    'error',
                    'structure',
                    $label,
                    sprintf('`components` must be an object, got %s.', get_debug_type($components)),
                    null,
                );

                return;
            }
        }
        if (is_array($components) && array_key_exists('securitySchemes', $components)) {
            $declared = $components['securitySchemes'];
            if (!is_array($declared)) {
                // Runtime validation hard-errors on this node whenever a
                // security requirement exists. The container is unusable, and
                // the acknowledged rot checks below would misreport every
                // name as "not defined" — stop here, mirroring the runtime
                // is_array guard around its own rot check.
                $issues[] = $this->issue(
                    'error',
                    'structure',
                    $label,
                    sprintf('components.securitySchemes must be an object mapping scheme names to definitions, got %s.', get_debug_type($declared)),
                    null,
                );

                return;
            }
            $schemes = $declared;
        }

        foreach ($schemes as $name => $scheme) {
            if (!is_array($scheme)) {
                // Runtime validation resolves a non-object definition as an
                // undefined scheme (hard error) when referenced; report the
                // defect at its definition site.
                $issues[] = $this->issue(
                    'error',
                    'structure',
                    $label,
                    sprintf('Security scheme `%s` must be an object, got %s.', (string) $name, get_debug_type($scheme)),
                    'Fix the definition under components.securitySchemes — a request referencing this scheme fails validation with a hard error.',
                );

                continue;
            }
            // Partition via the runtime classifier so the doctor and the
            // validator cannot disagree — e.g. `scheme: Bearer` is enforced
            // bearer auth (RFC 7235 case-insensitive), and a malformed
            // definition (missing/non-string `type`, `http` without `scheme`,
            // …) is the same hard error runtime validation raises for a
            // request secured by it — not a silently accepted definition.
            $classification = SecurityValidator::classifyScheme($scheme);
            if ($classification->kind === SchemeKind::Malformed) {
                $issues[] = $this->issue(
                    'error',
                    'structure',
                    $label,
                    sprintf('Security scheme `%s` is malformed: %s', (string) $name, $classification->reason ?? ''),
                    'Fix the definition under components.securitySchemes — requests secured by this scheme fail validation with a hard error.',
                );

                continue;
            }
            if ($classification->kind !== SchemeKind::Unsupported) {
                continue;
            }
            $rawType = $scheme['type'] ?? null;
            // Unsupported classifications always carry a string `type`; the
            // fallback only guards the static type.
            $type = is_string($rawType) ? $rawType : get_debug_type($rawType);
            if (in_array((string) $name, $acknowledgedSchemes, true)) {
                $issues[] = $this->issue(
                    'skipped',
                    'feature',
                    $label,
                    sprintf('Security scheme `%s` (%s) is recognized but not enforced — acknowledged as unvalidatable.', (string) $name, $type),
                    null,
                );

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

        // Rot checks mirroring SecurityValidator::warnAcknowledgementRot():
        // an acknowledged name that is absent from the spec, or that names a
        // scheme the validator can enforce, is a configuration warning so the
        // acknowledged list cannot rot silently.
        foreach ($acknowledgedSchemes as $acknowledgedName) {
            $definition = $schemes[$acknowledgedName] ?? null;
            if (!is_array($definition)) {
                $issues[] = $this->issue(
                    'warning',
                    'configuration',
                    $label,
                    sprintf('Acknowledged security scheme `%s` is not defined in components.securitySchemes.', $acknowledgedName),
                    'Fix the name or remove the entry from the acknowledged list.',
                );

                continue;
            }
            $kind = SecurityValidator::classifyScheme($definition)->kind;
            if ($kind === SchemeKind::Bearer || $kind === SchemeKind::ApiKey) {
                $issues[] = $this->issue(
                    'warning',
                    'configuration',
                    $label,
                    sprintf('Acknowledged security scheme `%s` is a scheme the validator can enforce; the acknowledgement has no effect.', $acknowledgedName),
                    'Remove the entry from the acknowledged list so the scheme stays validated.',
                );
            }
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
            // Same shape and same `unknown` semantics as the `tool` block in
            // coverage JSON and validation JSON, so a consumer reading two
            // Gesso documents does not have to special-case this one.
            'tool' => [
                'name' => ToolVersion::PACKAGE,
                'version' => ToolVersion::resolve(),
            ],
            'status' => $counts['errors'] > 0 ? 'error' : ($counts['warnings'] > 0 || $counts['skipped'] > 0 ? 'warning' : 'ok'),
            'summary' => [
                'specs' => count($specs),
                'operations' => $operations,
                'responses' => $responses,
                ...$counts,
            ],
            'specs' => $specs,
            'issues' => $issues,
            'phpunit' => ($options['phpunit_snippet'] ?? false)
                ? $this->phpunitSnippet(
                    $specs,
                    $options['strip_prefixes'] ?? [],
                    $options['local_ref_root'] ?? null,
                    $options['acknowledged_unvalidatable_schemes'] ?? [],
                )
                : null,
        ];
    }

    /** @param array<string, mixed> $report */
    private function render(array $report, string $format): string
    {
        if ($format === 'json') {
            return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
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
            $lines[] = sprintf(
                '  %s %s[%s]%s %s',
                strtoupper($issue['severity']),
                $issue['code'] !== null ? FailureCatalogue::tag($issue['code']) . ' ' : '',
                $issue['category'],
                $issue['spec'] !== null ? ' ' . $issue['spec'] . ':' : '',
                $issue['message'],
            );
            if ($issue['suggestion'] !== null) {
                $lines[] = '    Fix: ' . $issue['suggestion'];
            }
        }
        $lines[] = sprintf('Errors: %d | Warnings: %d | Skipped: %d', $summary['errors'], $summary['warnings'], $summary['skipped']);
        if (is_string($report['phpunit'])) {
            $lines[] = "\nEquivalent PHPUnit configuration:\n" . $report['phpunit'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<SpecResult> $specs
     * @param list<string> $stripPrefixes
     * @param list<string> $acknowledgedSchemes
     */
    private function phpunitSnippet(array $specs, array $stripPrefixes, ?string $localRefRoot, array $acknowledgedSchemes): ?string
    {
        if ($specs === []) {
            return null;
        }
        $basePath = $this->sharedSpecBasePath($specs, $localRefRoot);
        if ($basePath === null) {
            return '<!-- Specs use different directories; one PHPUnit extension instance requires a shared spec.base_path. -->';
        }

        // The extension reads ./gesso.php by default, so the XML carries no
        // parameter: the flat v2 parameters are deprecated (#501).
        return <<<'XML'
            <extensions>
                <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension"/>
            </extensions>
            XML
            . "\n\n<!-- Save the PHP below as gesso.php next to phpunit.xml. -->\n"
            . $this->gessoConfigSource($specs, $stripPrefixes, $basePath, $acknowledgedSchemes);
    }

    /**
     * The one spec directory every entry document shares, relative to the cwd
     * when it is the cwd or lies below it (a portable path); `null` when there are no specs, or when they live
     * in different directories and no `--local-ref-root` names a common one.
     *
     * @param list<SpecResult> $specs
     */
    private function sharedSpecBasePath(array $specs, ?string $localRefRoot): ?string
    {
        if ($specs === []) {
            return null;
        }
        $basePath = $localRefRoot ?? dirname($specs[0]['path']);
        if ($localRefRoot === null) {
            foreach ($specs as $spec) {
                if (dirname($spec['path']) !== $basePath) {
                    return null;
                }
            }
        }

        return self::cwdRelative($basePath);
    }

    /**
     * The `gesso.php` the doctor invocation describes. The base path
     * is cwd-relative, which is what `gesso.php` resolves against when the file
     * is written to the cwd.
     *
     * @param list<SpecResult> $specs
     * @param list<string> $stripPrefixes
     * @param list<string> $acknowledgedSchemes
     */
    private function gessoConfigSource(array $specs, array $stripPrefixes, string $basePath, array $acknowledgedSchemes): string
    {
        $list = static fn(array $values): string => '[' . implode(', ', array_map(
            static fn(string $value): string => var_export($value, true),
            $values,
        )) . ']';

        $spec = [
            "        'base_path' => " . var_export($basePath, true) . ',',
            "        'names' => " . $list(array_map(static fn(array $spec): string => $spec['name'], $specs)) . ',',
        ];
        if ($stripPrefixes !== []) {
            $spec[] = "        'strip_prefixes' => " . $list($stripPrefixes) . ',';
        }
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '// Generated by `gesso doctor --emit-config`. Keys: docs/configuration.md',
            'return [',
            "    'spec' => [",
            ...$spec,
            '    ],',
        ];
        if ($acknowledgedSchemes !== []) {
            $lines[] = "    'validation' => [";
            $lines[] = "        'acknowledged_unvalidatable_schemes' => " . $list($acknowledgedSchemes) . ',';
            $lines[] = '    ],';
        }
        $lines[] = '];';

        return implode("\n", $lines) . "\n";
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
            'RemoteRefHostDisallowed' => 'Add the exact trusted host with --remote-ref-host, or bundle the spec.',
            'LocalRefOutsideAllowedRoot' => 'Use --local-ref-root with the narrowest trusted common directory, or bundle the spec.',
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
        $code = FailureCatalogue::forDoctor($category);
        $docs_url = $code === null ? null : FailureCatalogue::docsUrl($code);

        /** @var 'error'|'skipped'|'warning' $severity */
        return compact('severity', 'category', 'spec', 'message', 'suggestion', 'code', 'docs_url');
    }
}
