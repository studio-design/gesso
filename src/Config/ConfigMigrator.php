<?php

declare(strict_types=1);

namespace Studio\Gesso\Config;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PATHINFO_FILENAME;

use DOMDocument;
use DOMElement;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Studio\Gesso\Cli\StubsCommand;
use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\Laravel\LaravelConfig;
use Studio\Gesso\PHPUnit\ExtensionParameters;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function ctype_digit;
use function dirname;
use function explode;
use function file_get_contents;
use function glob;
use function implode;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function ltrim;
use function pathinfo;
use function preg_match;
use function preg_quote;
use function realpath;
use function rtrim;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function substr_count;
use function trim;
use function var_export;

/**
 * Reads a project's v2 Gesso configuration and its competitor validator
 * setup, and proposes the equivalent root `gesso.php` (issue: v3 M1/M2).
 *
 * Every old→new spelling comes from the class that rejects it at runtime —
 * {@see ConfigurationBridge::PHPUNIT}, {@see ExtensionParameters::REMOVED_WITHOUT_SUCCESSOR},
 * {@see LaravelConfig::REMOVED}, {@see CoverageMergeCommand::REMOVED_FLAGS},
 * {@see StubsCommand::REMOVED_FLAGS} and {@see LegacyIdentity} — so the
 * migration and the rejection cannot disagree. Nothing is edited in place:
 * the result is a proposal plus a list of findings, each naming a file, a
 * line and the edit to make.
 *
 * @phpstan-type Finding array{category: string, file: string, line: ?int, message: string, action: string}
 *
 * @internal Implementation of `gesso config:migrate`; its flags, exit codes and JSON document are the covered surface.
 */
final class ConfigMigrator
{
    public const PHPUNIT_UPGRADE = 'composer require --dev "phpunit/phpunit:^12.5" --with-all-dependencies';
    public const PEST_UPGRADE = 'composer require --dev "pestphp/pest:^4.0" --with-all-dependencies';
    public const GUIDE_URL = 'https://github.com/studio-design/gesso/blob/main/docs/migration/from-other-validators.md';

    /** Competitor package => anchor of its section in {@see self::GUIDE_URL}. */
    public const COMPETITORS = [
        'hotmeteor/spectator' => 'spectator',
        'kirschbaum-development/laravel-openapi-validator' => 'kirschbaum-developmentlaravel-openapi-validator',
        'osteel/openapi-httpfoundation-testing' => 'osteelopenapi-httpfoundation-testing',
        'league/openapi-psr7-validator' => 'leagueopenapi-psr7-validator',
    ];

    /** The order PHPUnit itself looks for a configuration file. */
    private const PHPUNIT_FILES = ['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist'];

    private const SPECTATOR_EXTENSION = 'Spectator\Coverage\SpectatorExtension';

    /**
     * Competitor symbols in test code, and the edit each needs. The rows
     * transcribe the mapping tables of the migration guide.
     *
     * @var array<string, list<array{string, string}>>
     */
    private const USAGES = [
        'hotmeteor/spectator' => [
            ['/\buse\s+Spectator\\\\Spectator\s*;/', 'delete the import'],
            ['/\bSpectator::using\(/', "delete; name the spec once with spec.default in gesso.php, or #[OpenApiSpec('name')] on the class"],
            ['/->assertValidRequest\(\s*\)/', 'delete; laravel.auto_validate_request validates every request'],
            ['/->assertValidResponse\(\s*\d+\s*\)/', 'replace ->assertValidResponse(<status>) with ->assertStatus(<status>); laravel.auto_assert validates the body'],
            ['/->assertValidResponse\(\s*\)/', 'delete; laravel.auto_assert validates every response'],
            ['/->(?:assertInvalidRequest|assertInvalidResponse|assertErrorsContain|assertValidationMessage|dumpSpecErrors)\(/', 'rewrite by hand: call withoutRequestValidation() / withoutResponseValidation() and assert on the validator result issues()'],
            ['/\b(?:withPathPrefix|setPathPrefix)\(/', 'delete; set spec.strip_prefixes in gesso.php'],
            ['/\bSpectator::reset\(/', 'replace with #[SkipOpenApi] or $this->withoutValidation()'],
            ['/->assertPathExists\(/', 'delete; compare routes and spec with php artisan gesso:routes'],
        ],
        'kirschbaum-development/laravel-openapi-validator' => [
            ['/Kirschbaum\\\\OpenApiValidator\\\\ValidatesOpenApiSpec\b/', 'replace with Studio\Gesso\Laravel\ValidatesOpenApiSchema'],
            ['/\buse\s+ValidatesOpenApiSpec\s*;/', 'replace with use ValidatesOpenApiSchema;'],
            ['/\$responseCodesToSkip\b/', 'move the codes to validation.skip_response_codes in gesso.php, or override openApiSkipResponseCodes()'],
            ['/\bgetAuthenticatedRequest\(/', "delete; set laravel.auto_inject_dummy_credentials to true or 'bearer'"],
            ['/\bgetOpenApiValidatorBuilder\(/', 'delete; the spec is configured in gesso.php'],
        ],
        'osteel/openapi-httpfoundation-testing' => [
            ['/Osteel\\\\OpenApi\\\\Testing\\\\/', 'replace with the Studio\Gesso\Symfony\OpenApiAssertions trait'],
            ['/\bValidatorBuilder::from(?:Yaml|Json)(?:File|String|Url)?\(/', 'delete; the spec is configured with spec.base_path / spec.default in gesso.php'],
            ['/->(?:validate|get|post|put|patch|delete|head|options|trace)\([^,()]*(?:\(\))?[^,()]*,\s*[\'"]\//', 'replace with assertResponseMatchesOpenApiSchema($request, $response) or assertRequestMatchesOpenApiSchema($request); in a KernelBrowser test, assertClientMatchesOpenApiSchema($client)'],
            ['/\bValidationException\b/', 'delete the catch; a mismatch is a PHPUnit failure'],
        ],
        'league/openapi-psr7-validator' => [
            ['/League\\\\OpenAPIValidation\\\\/', 'replace with Studio\Gesso\Psr7\OpenApiPsr7Validator or the Studio\Gesso\Psr7\OpenApiAssertions trait'],
            ['/->getResponseValidator\(\)\s*->validate\(\s*new\s+OperationAddress\(/', 'replace with ->validateResponseForOperation($method, $path, $response)'],
            ['/\(new\s+ValidatorBuilder\)|new\s+ValidatorBuilder\(\)/', 'delete; the spec is configured in gesso.php'],
            ['/\b(?:setCache|overrideCacheKey)\(/', 'delete; Gesso caches loaded specs itself'],
            ['/\bValidationFailed\b|\bInvalid(?:Body|Cookies|Headers|Parameter|Path|QueryArgs|Security)\b/', 'replace the catch with $result->isValid() / $result->issues() (a Skipped result is not a failure)'],
        ],
    ];

    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Layers of the proposal, lowest priority first.
     *
     * @var array<string, array{source: string, values: array<string, mixed>}>
     */
    private array $layers = [];
    private bool $existing = false;

    private function __construct(private readonly string $root) {}

    /**
     * @return array{config: ?array<string, mixed>, existing: bool, findings: list<Finding>, warnings: list<string>}
     */
    public static function scan(string $root): array
    {
        $migrator = new self(rtrim($root, '/\\'));
        $composer = $migrator->readComposer();
        $migrator->checkTestRunnerVersions($composer);
        $migrator->scanCompetitors($composer);
        $migrator->readExistingGessoPhp();
        $migrator->scanLaravelConfig();
        $migrator->scanPhpunitFiles();
        $migrator->scanLegacySpellings();

        return [
            'config' => $migrator->proposal(),
            'existing' => $migrator->existing,
            'findings' => $migrator->findings,
            'warnings' => $migrator->warnings,
        ];
    }

    /**
     * A `gesso.php` for `$config`, in the documented key order, one setting
     * per line so successive runs diff cleanly.
     *
     * @param array<string, mixed> $config
     */
    public static function render(array $config): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// Generated by `gesso config:migrate`. Every key: docs/configuration.md.\n"
            . 'return ' . self::export($config, 0) . ";\n";
    }

    /**
     * The v2 config key paths as dotted `gesso.php` keys: `strict.required['run']`
     * and `strict.required.run` are the same key.
     */
    public static function normaliseKey(string $key): string
    {
        return str_replace(["['", "']"], ['.', ''], $key);
    }

    /** @param array<array-key, mixed> $value */
    private static function export(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }
        if (array_is_list($value) && !self::hasArrays($value)) {
            return '[' . implode(', ', array_map(static fn(mixed $item): string => self::scalar($item), $value)) . ']';
        }
        $indent = str_repeat('    ', $depth + 1);
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $indent . var_export($key, true) . ' => '
                . (is_array($item) ? self::export($item, $depth + 1) : self::scalar($item)) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }

    /** @param array<array-key, mixed> $list */
    private static function hasArrays(array $list): bool
    {
        foreach ($list as $item) {
            if (is_array($item)) {
                return true;
            }
        }

        return false;
    }

    private static function scalar(mixed $value): string
    {
        return $value === null ? 'null' : var_export($value, true);
    }

    /**
     * Dotted keys to the nested `gesso.php` shape, in the documented order.
     *
     * @param array<string, mixed> $flat
     *
     * @return array<string, mixed>
     */
    private static function nest(array $flat): array
    {
        $ordered = [];
        foreach (self::dotted(GessoConfig::defaults()->toArray()) as $key => $_) {
            if (array_key_exists($key, $flat)) {
                $ordered[$key] = $flat[$key];
                unset($flat[$key]);
            }
        }
        // Unknown keys (from an existing gesso.php) stay, so validation names them.
        $ordered += $flat;

        $nested = [];
        foreach ($ordered as $key => $value) {
            $node = &$nested;
            foreach (explode('.', $key) as $segment) {
                if (!isset($node[$segment]) || !is_array($node[$segment])) {
                    $node[$segment] = [];
                }
                $node = &$node[$segment];
            }
            $node = $value;
            unset($node);
        }

        return $nested;
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return array<string, mixed>
     */
    private static function dotted(array $tree, string $prefix = ''): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat += self::dotted($value, $path . '.');
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    /** @param array<string, mixed> $composer */
    private static function requirement(array $composer, string $package): ?string
    {
        foreach (['require', 'require-dev'] as $section) {
            $constraint = is_array($composer[$section] ?? null) ? ($composer[$section][$package] ?? null) : null;
            if (is_string($constraint)) {
                return $constraint;
            }
        }

        return null;
    }

    /**
     * A phpunit.xml string as the PHP value `gesso.php` takes. A value that
     * does not parse stays a string, so the proposal's validation names it.
     */
    private static function typed(string $raw, string $type, string $key, string $file): mixed
    {
        $raw = trim($raw);

        return match ($type) {
            'bool' => (static function () use ($raw, $key, $file): bool|string {
                try {
                    return GessoConfig::parseBool($raw, $key, $file);
                } catch (InvalidGessoConfigurationException) {
                    return $raw;
                }
            })(),
            'int' => ctype_digit($raw) ? (int) $raw : $raw,
            'number' => is_numeric($raw) ? $raw + 0 : $raw,
            'strings' => array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $item): bool => $item !== '')),
            default => $raw,
        };
    }

    /** @return array{string, string, string} regex, flag spelling, replacement */
    private static function flagRule(string $name, string $replacement, string $suffix): array
    {
        $flag = '--' . str_replace('_', '-', $name);

        return ['/(?<![\w-])' . preg_quote($flag, '/') . $suffix . '/', $flag, $replacement];
    }

    /** A literal string assigned to `$key` in a PHP config file. */
    private static function literal(string $path, string $key): ?string
    {
        return preg_match('/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*[\'"]([^\'"]*)[\'"]/', (string) file_get_contents($path), $m) === 1 ? $m[1] : null;
    }

    private static function lineOf(string $path, string $needle): ?int
    {
        $source = (string) file_get_contents($path);
        $offset = strpos($source, $needle);

        return $offset === false ? null : substr_count($source, "\n", 0, $offset) + 1;
    }

    private static function scalarOrJson(mixed $value): string
    {
        if (is_array($value)) {
            return '[' . implode(', ', array_map(static fn(mixed $item): string => is_string($item) ? $item : var_export($item, true), $value)) . ']';
        }

        return is_bool($value) || is_int($value) || is_float($value) || $value === null ? var_export($value, true) : (string) $value;
    }

    /**
     * Mapped values, merged in the precedence v2.9 applied: a legacy
     * phpunit.xml parameter or Laravel key won over `gesso.php`, which won
     * over nothing. Competitor settings fill only what nothing else set.
     *
     * @return ?array<string, mixed> null when there is nothing to write
     */
    private function proposal(): ?array
    {
        $flat = [];
        $source = [];
        foreach ($this->layers as $layer) {
            foreach ($layer['values'] as $key => $value) {
                if (array_key_exists($key, $flat) && $flat[$key] !== $value) {
                    $this->warnings[] = sprintf(
                        '%s: %s overrides the value from %s (%s replaces %s).',
                        $key,
                        $layer['source'],
                        $source[$key],
                        self::scalarOrJson($value),
                        self::scalarOrJson($flat[$key]),
                    );
                }
                $flat[$key] = $value;
                $source[$key] = $layer['source'];
            }
        }
        // An existing gesso.php alone is nothing to migrate.
        if ($flat === [] || array_keys($this->layers) === [GessoConfig::FILENAME]) {
            return null;
        }

        $config = self::nest($flat);

        try {
            GessoConfig::fromArray($config, $this->root, 'the proposed gesso.php');
        } catch (InvalidGessoConfigurationException $e) {
            $this->finding('invalid-value', GessoConfig::FILENAME, null, $e->getMessage(), 'fix the value at its source, then re-run config:migrate');
        }

        return $config;
    }

    /** @param array<string, mixed> $values */
    private function addLayer(string $source, array $values, bool $dropDefaults = true): void
    {
        $defaults = self::dotted(GessoConfig::defaults()->toArray());
        foreach ($dropDefaults ? $values : [] as $key => $value) {
            // A published v2 config carries every default; restating them is noise.
            if ($value === '' || $value === null || (array_key_exists($key, $defaults) && $defaults[$key] === $value)) {
                unset($values[$key]);
            }
        }
        if ($values === []) {
            return;
        }
        $current = $this->layers[$source]['values'] ?? [];
        $this->layers[$source] = ['source' => $source, 'values' => $values + $current];
    }

    private function finding(string $category, string $file, ?int $line, string $message, string $action): void
    {
        $this->findings[] = ['category' => $category, 'file' => $file, 'line' => $line, 'message' => $message, 'action' => $action];
    }

    /** @return array<string, mixed> */
    private function readComposer(): array
    {
        $path = $this->root . '/composer.json';
        if (!is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->warnings[] = 'composer.json could not be decoded: ' . $e->getMessage();

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * PHPUnit 11 and Pest 3 are unsupported (Gesso requires PHPUnit 12+);
     * upgrading them is step 0 of every migration.
     *
     * @param array<string, mixed> $composer
     */
    private function checkTestRunnerVersions(array $composer): void
    {
        $runners = ['phpunit/phpunit' => ['12', self::PHPUNIT_UPGRADE], 'pestphp/pest' => ['4', self::PEST_UPGRADE]];
        $locked = $this->lockedVersions();
        foreach ($runners as $package => [$minimum, $command]) {
            if ($locked !== null) {
                $version = $locked[$package] ?? null;
                if ($version !== null && preg_match('/^v?(\d+)\./', $version, $m) === 1 && (int) $m[1] < (int) $minimum) {
                    $this->finding('test-runner', 'composer.lock', null, sprintf('%s %s is installed; Gesso 3 needs %s.x or later.', $package, $version, $minimum), $command);
                }

                continue;
            }
            $constraint = self::requirement($composer, $package);
            // ponytail: without a lock file, any constraint that never names the supported major is flagged; the lock is authoritative when present.
            if ($constraint !== null && preg_match('/(?<![\d.])(?:' . $minimum . '|1[3-9]|[5-9])\b/', $constraint) !== 1) {
                $this->finding('test-runner', 'composer.json', null, sprintf('%s is constrained to "%s"; Gesso 3 needs %s.x or later.', $package, $constraint, $minimum), $command);
            }
        }
    }

    /** @return ?array<string, string> null when there is no readable composer.lock */
    private function lockedVersions(): ?array
    {
        $path = $this->root . '/composer.lock';
        if (!is_file($path)) {
            return null;
        }

        try {
            $lock = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $versions = [];
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (is_array($lock) && is_array($lock[$section] ?? null) ? $lock[$section] : [] as $package) {
                if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                    $versions[$package['name']] = $package['version'];
                }
            }
        }

        return $versions;
    }

    /** @param array<string, mixed> $composer */
    private function scanCompetitors(array $composer): void
    {
        foreach (self::COMPETITORS as $package => $anchor) {
            if (self::requirement($composer, $package) === null) {
                continue;
            }
            $this->finding(
                'competitor-package',
                'composer.json',
                self::lineOf($this->root . '/composer.json', '"' . $package . '"'),
                sprintf('%s is still required.', $package),
                sprintf('migrate the files listed below, then run composer remove %s (guide: %s#%s)', $package, self::GUIDE_URL, $anchor),
            );
            match ($package) {
                'hotmeteor/spectator' => $this->translateSpectatorConfig(),
                'kirschbaum-development/laravel-openapi-validator' => $this->translateKirschbaumConfig(),
                default => null,
            };
            $this->scanUsages($package);
            if ($package === 'hotmeteor/spectator') {
                $this->warnings[] = 'Spectator validated only after Spectator::using() and only routes in its middleware_groups; '
                    . 'with laravel.auto_assert Gesso validates every exchange of a test using the trait. '
                    . 'Turn auto_assert on after the explicit assertions pass, and record known failures with baseline.violations.';
            }
        }
    }

    private function translateSpectatorConfig(): void
    {
        $file = 'config/spectator.php';
        $path = $this->root . '/' . $file;
        if (!is_file($path)) {
            return;
        }
        $config = $this->evaluate($file);
        $values = [];
        $prefix = is_array($config) ? ($config['path_prefix'] ?? null) : self::literal($path, 'path_prefix');
        if (is_string($prefix) && trim($prefix, '/') !== '') {
            $values['spec.strip_prefixes'] = ['/' . trim($prefix, '/')];
        }
        $local = is_array($config) && is_array($config['sources']['local'] ?? null) ? ($config['sources']['local']['base_path'] ?? null) : null;
        if (is_string($local) && $local !== '') {
            $values['spec.base_path'] = $this->relative($local);
        }
        if (is_array($config) && ($config['error_format'] ?? null) === 'json') {
            $values['validation.format'] = 'json';
        }
        $this->addLayer($file, $values);
        $this->finding(
            'competitor-config',
            $file,
            null,
            'Spectator configuration.',
            ($values === [] ? 'nothing mappable; ' : 'path_prefix, sources.local.base_path and error_format are translated into the proposal; ')
            . 'remote and github sources need remote specs configured by hand; delete the file with the package',
        );
    }

    private function translateKirschbaumConfig(): void
    {
        $file = 'config/openapi_validator.php';
        $specPath = null;
        if (is_file($this->root . '/' . $file)) {
            $config = $this->evaluate($file);
            $specPath = is_array($config) ? ($config['spec_path'] ?? null) : self::literal($this->root . '/' . $file, 'spec_path');
            $this->finding('competitor-config', $file, null, 'laravel-openapi-validator configuration.', 'spec_path is translated into spec.base_path + spec.default; delete the file with the package');
        } elseif (is_file($this->root . '/openapi.yaml')) {
            $specPath = $this->root . '/openapi.yaml';
        }
        $values = ['laravel.auto_assert' => true, 'laravel.auto_validate_request' => true];
        if (is_string($specPath) && $specPath !== '') {
            $values += $this->specLocation($specPath);
        }
        $this->addLayer('laravel-openapi-validator', $values);
    }

    /**
     * The test files using a competitor, one finding per matching line.
     */
    private function scanUsages(string $package): void
    {
        $specPaths = [];
        $specNames = [];
        foreach ($this->phpFiles('tests') as $relative => $lines) {
            foreach ($lines as $index => $line) {
                foreach (self::USAGES[$package] as [$pattern, $action]) {
                    if (preg_match($pattern, $line) === 1) {
                        $this->finding('competitor-usage', $relative, $index + 1, trim($line), $action);

                        break;
                    }
                }
                if (preg_match('/\bfrom(?:Yaml|Json)File\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m) === 1) {
                    $specPaths[$m[1]] = true;
                } elseif (preg_match('/\bSpectator::using\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m) === 1) {
                    $specNames[pathinfo($m[1], PATHINFO_FILENAME)] = true;
                }
            }
        }
        if (count($specPaths) === 1) {
            $this->addLayer($package, $this->specLocation((string) array_keys($specPaths)[0]));
        } elseif (count($specPaths) > 1) {
            $this->warnings[] = sprintf('%s loads several spec files (%s); set spec.base_path and spec.names by hand.', $package, implode(', ', array_keys($specPaths)));
        }
        if (count($specNames) === 1) {
            $this->addLayer($package, ['spec.default' => (string) array_keys($specNames)[0]]);
        } elseif (count($specNames) > 1) {
            $this->addLayer($package, ['spec.names' => array_map('strval', array_keys($specNames))]);
            $this->warnings[] = 'Spectator::using() names several specs; spec.names lists them, and each test class picks one with #[OpenApiSpec(\'name\')].';
        }
    }

    /** @return array<string, string> */
    private function specLocation(string $specPath): array
    {
        $relative = $this->relative($specPath);
        $directory = dirname($relative);

        return ['spec.base_path' => $directory === '' ? '.' : $directory, 'spec.default' => pathinfo($relative, PATHINFO_FILENAME)];
    }

    /** A path as `gesso.php` at the project root spells it. */
    private function relative(string $path): string
    {
        foreach ([realpath($this->root) ?: $this->root, $this->root] as $prefix) {
            if (str_starts_with($path, $prefix . '/')) {
                return substr($path, strlen($prefix) + 1);
            }
        }

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }

    private function readExistingGessoPhp(): void
    {
        $path = $this->root . '/' . GessoConfig::FILENAME;
        if (!is_file($path) || trim((string) file_get_contents($path)) === '') {
            return;
        }
        $this->existing = true;
        $config = $this->evaluate(GessoConfig::FILENAME);
        if (is_array($config)) {
            $this->addLayer(GessoConfig::FILENAME, self::dotted($config), dropDefaults: false);
            if ($this->hasExpressions(GessoConfig::FILENAME)) {
                $this->warnings[] = 'gesso.php computes values (for example __DIR__ or getenv()); the proposal holds their current results as literals.';
            }
        }
    }

    /**
     * Laravel's published v2 `config/gesso.php`: the flat keys
     * {@see LaravelConfig::REMOVED} lists.
     */
    private function scanLaravelConfig(): void
    {
        $file = 'config/gesso.php';
        $path = $this->root . '/' . $file;
        if (!is_file($path)) {
            return;
        }
        $source = (string) file_get_contents($path);
        $present = [];
        foreach (array_keys(LaravelConfig::REMOVED) as $key) {
            if (preg_match('/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>/', $source) === 1) {
                $present[] = $key;
            }
        }
        if ($present === []) {
            return;
        }
        $config = $this->evaluate($file);
        if (!is_array($config)) {
            $this->finding('laravel-config', $file, null, 'config/gesso.php holds v2 flat keys but could not be evaluated outside Laravel.', 'run php artisan gesso:config-migrate, which reads it with the application booted');

            return;
        }

        $values = [];
        foreach (LaravelConfig::REMOVED as $key => $replacement) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $this->finding('laravel-config', $file, self::lineOf($path, "'" . $key . "'"), sprintf("flat key '%s' was removed in Gesso 3.0.", $key), sprintf('now %s in gesso.php', $replacement));
            $value = $config[$key];
            if ($key === 'route_parity') {
                foreach (is_array($value) ? $value : [] as $member => $list) {
                    $values['laravel.route_parity.' . $member] = $list;
                }
            } elseif ($key === 'auto_inject_dummy_bearer') {
                // v2: `true` credentials already covered Bearer, so the narrower flag only matters alone.
                if ($value === true && ($config['auto_inject_dummy_credentials'] ?? false) !== true) {
                    $values['laravel.auto_inject_dummy_credentials'] = 'bearer';
                }
            } else {
                $values[$replacement] = $value;
            }
        }
        $this->finding('laravel-config', $file, null, 'config/gesso.php is the v2 Laravel configuration.', 'delete it once gesso.php is written, then run php artisan config:clear');
        if ($this->hasExpressions($file)) {
            $this->warnings[] = 'config/gesso.php calls env() or other functions; the proposal holds their current values as literals. Re-wrap any value that must stay environment-driven by hand, for example getenv(\'X\') ?: \'default\' (env() is not available when PHPUnit reads gesso.php).';
        }
        $this->addLayer($file, $values);
    }

    private function scanPhpunitFiles(): void
    {
        $values = [];
        foreach (self::PHPUNIT_FILES as $file) {
            $path = $this->root . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $document = new DOMDocument();
            if (@$document->loadXML((string) file_get_contents($path)) === false) {
                $this->warnings[] = $file . ' is not well-formed XML; its extension parameters were not read.';

                continue;
            }
            foreach ($document->getElementsByTagName('bootstrap') as $bootstrap) {
                $class = ltrim($bootstrap->getAttribute('class'), '\\');
                if ($class === self::SPECTATOR_EXTENSION) {
                    $values += $this->translateSpectatorExtension($file, $bootstrap);
                } elseif (str_ends_with($class, '\OpenApiCoverageExtension') || $class === 'OpenApiCoverageExtension') {
                    $values += $this->translateGessoExtension($file, $bootstrap);
                }
            }
        }
        // The first file PHPUnit would read wins, as it does at runtime.
        $this->addLayer('phpunit.xml', $values);
    }

    /** @return array<string, mixed> */
    private function translateGessoExtension(string $file, DOMElement $bootstrap): array
    {
        $values = [];
        foreach ($bootstrap->getElementsByTagName('parameter') as $parameter) {
            $name = $parameter->getAttribute('name');
            $raw = $parameter->getAttribute('value');
            $line = $parameter->getLineNo();
            if (isset(ConfigurationBridge::PHPUNIT[$name])) {
                [$key, $type] = ConfigurationBridge::PHPUNIT[$name];
                $this->finding('phpunit-parameter', $file, $line, sprintf('<parameter name="%s"> was removed in Gesso 3.0.', $name), sprintf('delete it; the value moves to %s in gesso.php', $key));
                $values[$key] = self::typed($raw, $type, $key, $file);
            } elseif (isset(ExtensionParameters::REMOVED_WITHOUT_SUCCESSOR[$name])) {
                $this->finding('phpunit-parameter', $file, $line, sprintf('<parameter name="%s"> was removed in Gesso 3.0.', $name), ExtensionParameters::REMOVED_WITHOUT_SUCCESSOR[$name]);
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function translateSpectatorExtension(string $file, DOMElement $bootstrap): array
    {
        $values = [];
        foreach ($bootstrap->getElementsByTagName('parameter') as $parameter) {
            if ($parameter->getAttribute('name') === 'min_coverage') {
                $values['coverage.min_coverage.endpoint'] = self::typed($parameter->getAttribute('value'), 'number', 'coverage.min_coverage.endpoint', $file);
            }
        }
        $this->finding(
            'competitor-config',
            $file,
            $bootstrap->getLineNo(),
            'Spectator\'s PHPUnit extension is registered.',
            'replace it with <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension"/>; min_coverage moves to coverage.min_coverage.endpoint in gesso.php',
        );

        return $values;
    }

    /**
     * CLI flags, environment variables and Artisan commands Gesso 3.0
     * renamed, wherever a script, workflow or Makefile still spells them.
     */
    private function scanLegacySpellings(): void
    {
        $files = ['composer.json', 'Makefile', ...self::PHPUNIT_FILES];
        foreach (['.github/workflows/*.yml', '.github/workflows/*.yaml'] as $pattern) {
            foreach (glob($this->root . '/' . $pattern) ?: [] as $path) {
                $files[] = substr($path, strlen($this->root) + 1);
            }
        }

        $flags = [];
        foreach (CoverageMergeCommand::REMOVED_FLAGS as $name => $replacement) {
            $flags['coverage:merge'][] = self::flagRule($name, $replacement, '(?![\w-])');
        }
        foreach (CoverageMergeCommand::REMOVED_BARE_MODES as $name => $replacement) {
            // `--strict-required=warn`; the grouped `--strict-required="run=warn"` is current.
            $flags['coverage:merge'][] = self::flagRule($name, $replacement, '=(?![\'"]?\s*(?:run|per_call)=)');
        }
        foreach (StubsCommand::REMOVED_FLAGS as $name => $replacement) {
            $flags['stubs'][] = self::flagRule($name, $replacement, '(?![\w-])');
        }

        foreach ($files as $file) {
            $path = $this->root . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
                $context = match (true) {
                    preg_match('/\bcoverage[:-]merge\b/', $line) === 1 => 'coverage:merge',
                    preg_match('/\b(?:gesso[\s:]+stubs|openapi:stubs)\b/', $line) === 1 => 'stubs',
                    default => null,
                };
                foreach ($context === null ? [] : $flags[$context] as [$pattern, $flag, $replacement]) {
                    if (preg_match($pattern, $line) === 1) {
                        $this->finding('cli-flag', $file, $index + 1, sprintf('%s %s was removed in Gesso 3.0.', $context, $flag), 'use ' . $replacement);
                    }
                }
                foreach (LegacyIdentity::ENV_NAMES as $old => $new) {
                    if (preg_match('/\b' . $old . '\b/', $line) === 1) {
                        $this->finding('env-var', $file, $index + 1, sprintf('%s is the v2 spelling; it is removed in Gesso %s.', $old, LegacyIdentity::REMOVED_IN), 'rename it to ' . $new);
                    }
                }
                foreach (LegacyIdentity::COMMAND_NAMES as $old => $new) {
                    if (preg_match('/(?<![\w-])' . preg_quote($old, '/') . '(?![\w-])/', $line) === 1) {
                        $this->finding('artisan-command', $file, $index + 1, sprintf('%s is the v2 spelling; it is removed in Gesso %s.', $old, LegacyIdentity::REMOVED_IN), 'use ' . $new);
                    }
                }
            }
        }
    }

    /**
     * Evaluate a project PHP config file. Outside Laravel, a file calling
     * `base_path()` or a helper that needs the container throws; that is
     * reported, not fatal.
     */
    private function evaluate(string $file): mixed
    {
        try {
            $value = (static fn(string $path): mixed => require $path)($this->root . '/' . $file);
        } catch (Throwable $e) {
            $this->warnings[] = sprintf('%s could not be evaluated (%s); values that need the application were not read.', $file, $e->getMessage());

            return null;
        }
        if (!is_array($value)) {
            $this->warnings[] = $file . ' does not return an array.';

            return null;
        }

        return $value;
    }

    /** Whether a config file computes values rather than stating literals. */
    private function hasExpressions(string $file): bool
    {
        return preg_match('/\b(?:env|getenv|base_path|config_path|storage_path)\s*\(|__DIR__/', (string) file_get_contents($this->root . '/' . $file)) === 1;
    }

    /**
     * @return iterable<string, list<string>> relative path => lines
     */
    private function phpFiles(string $directory): iterable
    {
        $base = $this->root . '/' . $directory;
        if (!is_dir($base)) {
            return;
        }
        $paths = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);
        foreach ($paths as $path) {
            yield str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($this->root) + 1)) => explode("\n", (string) file_get_contents($path));
        }
    }
}
