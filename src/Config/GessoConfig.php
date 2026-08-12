<?php

declare(strict_types=1);

namespace Studio\Gesso\Config;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;

use InvalidArgumentException;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Validation\Support\StatusCodePatternSet;

use function array_is_list;
use function array_key_exists;
use function count;
use function dirname;
use function explode;
use function filter_var;
use function get_debug_type;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * The single configuration entry point (issue #501).
 *
 * `gesso.php` is a plain PHP file returning a nested array, read once and
 * handed to every surface that used to parse its own copy of the same
 * settings — 29 PHPUnit extension parameters, 13 Laravel config keys and the
 * merge CLI's flags. The key set is frozen by
 * [ADR 0005](../../docs/adr/0005-v3-configuration-and-cli-naming.md); take
 * names from there, and add a key here only after adding it there.
 *
 * Three properties are the point of the class, and each is a bug that exists
 * on the duplicated surfaces today:
 *
 * 1. **Unknown keys are FATAL.** A typo cannot silently keep a default.
 * 2. **Booleans are parsed in one place** ({@see self::parseBool()}), so
 *    `enforce_discriminator => 'off'` cannot mean "on" through the extension
 *    and "off" through the Laravel trait.
 * 3. **Relative paths resolve against the config file's own directory**, not
 *    `getcwd()` and not the Laravel base path, so the resolved directory does
 *    not depend on where the runner was invoked from.
 *
 * The class only reads and validates. Deciding precedence against CLI flags
 * and environment variables belongs to the surfaces that own them; they use
 * {@see self::has()} to tell "declared in the file" from "left at its
 * default".
 *
 * @internal Not part of the package's public API. The `gesso.php` key names
 *           are the covered surface; this class is how they are read.
 */
final class GessoConfig
{
    /** Conventional file name, at the project root. */
    public const FILENAME = 'gesso.php';

    /**
     * The frozen key set. A nested array mirrors the shape of `gesso.php`
     * itself, so the validation walk is a plain recursion and the file's own
     * layout is readable here. A node carrying a `type` is a leaf; anything
     * else is a section.
     *
     * Leaf types:
     * - `bool`      — a real boolean, or one of the strings
     *                 {@see self::parseBool()} accepts.
     * - `int`       — a non-negative integer (`0` meaning "unlimited" where
     *                 the setting says so).
     * - `percent`   — `null`, or a number in `0..100`.
     * - `enum`      — one of `values`.
     * - `string`    — `null`, or any string.
     * - `path`      — `null`, or a path resolved against the config directory.
     * - `strings`   — a list of non-empty strings. An `items` entry adds the
     *                 constraint the setting's own consumer applies, so the
     *                 file is rejected where the value is written rather than
     *                 halfway through a run.
     * - `bool_enum` — a boolean, or one of `values` (a boolean setting that
     *                 grew a third state).
     *
     * @var array<string, mixed>
     */
    private const SCHEMA = [
        'spec' => [
            'base_path' => ['type' => 'path', 'default' => null],
            'default' => ['type' => 'string', 'default' => null],
            'names' => ['type' => 'strings', 'default' => []],
            'strip_prefixes' => ['type' => 'strings', 'default' => []],
        ],
        'validation' => [
            'format' => ['type' => 'enum', 'values' => ['text', 'json'], 'default' => 'text'],
            'max_errors' => ['type' => 'int', 'default' => 20],
            'enforce_discriminator' => ['type' => 'bool', 'default' => true],
            'acknowledged_unvalidatable_schemes' => ['type' => 'strings', 'default' => []],
            'skip_response_codes' => [
                'type' => 'strings',
                'items' => 'status_code_pattern',
                'default' => OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
            ],
            'skip_request_validation_response_codes' => [
                'type' => 'strings',
                'items' => 'status_code_pattern',
                'default' => OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES,
            ],
        ],
        'strict' => [
            'required' => [
                'run' => ['type' => 'enum', 'values' => ['off', 'warn', 'fail'], 'default' => 'off'],
                'per_call' => ['type' => 'enum', 'values' => ['off', 'warn'], 'default' => 'off'],
            ],
            'additional_properties' => [
                'run' => ['type' => 'enum', 'values' => ['off', 'warn', 'fail'], 'default' => 'off'],
                'per_call' => ['type' => 'enum', 'values' => ['off', 'warn'], 'default' => 'off'],
            ],
        ],
        'coverage' => [
            'min_coverage' => [
                'endpoint' => ['type' => 'percent', 'default' => null],
                'response' => ['type' => 'percent', 'default' => null],
                'sdk_exercise' => ['type' => 'percent', 'default' => null],
                'strict' => ['type' => 'bool', 'default' => false],
            ],
            'report_output' => [
                'markdown' => ['type' => 'path', 'default' => null],
                'json' => ['type' => 'path', 'default' => null],
                'junit' => ['type' => 'path', 'default' => null],
                'html' => ['type' => 'path', 'default' => null],
            ],
            'console_report' => [
                'type' => 'enum',
                'values' => ['default', 'all', 'uncovered_only', 'active_only'],
                'default' => 'default',
            ],
            'sidecar_dir' => ['type' => 'path', 'default' => null],
        ],
        'baseline' => [
            'violations' => ['type' => 'path', 'default' => null],
            'coverage' => ['type' => 'path', 'default' => null],
        ],
        'baseline_stale' => [
            'violations' => ['type' => 'enum', 'values' => ['off', 'note', 'fail'], 'default' => 'note'],
            'coverage' => ['type' => 'enum', 'values' => ['off', 'note', 'fail'], 'default' => 'note'],
        ],
        'enum_drift' => [
            'enabled' => ['type' => 'bool', 'default' => false],
            'scan_namespaces' => ['type' => 'strings', 'default' => []],
            'fail_on_drift' => ['type' => 'bool', 'default' => true],
        ],
        'phpunit' => [
            'default_testsuite_as_full' => ['type' => 'bool', 'default' => false],
        ],
        'laravel' => [
            'auto_assert' => ['type' => 'bool', 'default' => false],
            'auto_validate_request' => ['type' => 'bool', 'default' => false],
            // `true` covers every inject-eligible scheme; `'bearer'` is the
            // narrower behaviour the deprecated `auto_inject_dummy_bearer`
            // key had (ADR 0005).
            'auto_inject_dummy_credentials' => ['type' => 'bool_enum', 'values' => ['bearer'], 'default' => false],
            'route_parity' => [
                'external_operation_ids' => ['type' => 'strings', 'default' => []],
                'external_openapi_paths' => ['type' => 'strings', 'default' => []],
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $values every leaf, defaulted and resolved
     * @param array<string, true> $declared dotted paths the file itself set
     */
    private function __construct(
        private readonly array $values,
        private readonly array $declared,
        private readonly ?string $sourcePath,
    ) {}

    /**
     * Read `gesso.php`.
     *
     * An explicitly requested file that is missing is FATAL — a typo in
     * `--config` must not degrade to "no configuration". The conventional
     * location being empty is not: a project that configures nothing is
     * running on documented defaults.
     */
    public static function load(?string $path = null): self
    {
        $requested = $path !== null;
        $path ??= (getcwd() ?: '.') . '/' . self::FILENAME;

        if (!is_file($path)) {
            if ($requested) {
                throw new InvalidGessoConfigurationException(
                    sprintf('Gesso configuration file not found: %s', $path),
                );
            }

            return self::defaults();
        }

        $absolute = realpath($path) ?: $path;

        /** @var mixed $raw */
        $raw = require $absolute;

        if (!is_array($raw)) {
            throw new InvalidGessoConfigurationException(sprintf(
                'Invalid Gesso configuration in %s: the file must return an array, got %s.',
                $absolute,
                get_debug_type($raw),
            ));
        }

        return self::fromArray($raw, dirname($absolute), $absolute);
    }

    /**
     * Every documented default, as though an empty `gesso.php` were present.
     */
    public static function defaults(): self
    {
        return self::fromArray([], getcwd() ?: '.', null);
    }

    /**
     * Validate an already-decoded configuration array.
     *
     * @param array<array-key, mixed> $raw
     * @param string $baseDir directory relative paths resolve against
     * @param null|string $origin file the array came from, named in error messages
     */
    public static function fromArray(array $raw, string $baseDir, ?string $origin = null): self
    {
        $declared = [];
        $values = self::walk($raw, self::SCHEMA, '', rtrim($baseDir, '/'), $origin, $declared);

        return new self($values, $declared, $origin);
    }

    /**
     * The single boolean truth table.
     *
     * `FILTER_VALIDATE_BOOLEAN` semantics, and an unreadable value is FATAL
     * rather than silently true. The PHPUnit extension's historical rule
     * ("anything but `0`/`false`/`no` is true") made `enforce_discriminator="off"`
     * mean the opposite of what it says while the Laravel trait read the same
     * string as `false`; only one of those can survive, and it is the one that
     * agrees with the word.
     *
     * The empty string is `false`, not FATAL: that is what the filter
     * documents alongside `0`/`false`/`off`/`no`, and it is what the Laravel
     * trait already did.
     *
     * @see https://www.php.net/manual/en/filter.filters.validate.php
     *
     * @internal Shared with the surfaces that receive this value as a string.
     */
    public static function parseBool(string $raw, string $keyPath, ?string $origin): bool
    {
        $parsed = filter_var(trim($raw), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw self::invalid($keyPath, $origin, sprintf(
                "expected a boolean, got '%s' (accepted: true/false, 1/0, yes/no, on/off)",
                $raw,
            ));
        }

        return $parsed;
    }

    /**
     * Whether the file set this key itself, as opposed to leaving it at its
     * documented default. Surfaces with their own inputs need the difference
     * to resolve precedence.
     *
     * A key the schema does not declare is a programming error, not `false`:
     * `has('validation.max_error')` answering "not configured" would make a
     * caller's typo silently discard whatever the user did configure, on the
     * very code path that decides precedence.
     */
    public function has(string $key): bool
    {
        self::leafNode($key);

        return array_key_exists($key, $this->declared);
    }

    public function bool(string $key): bool
    {
        $value = $this->typed($key, ['bool']);

        return is_bool($value) ? $value : throw self::corrupt($key);
    }

    public function int(string $key): int
    {
        $value = $this->typed($key, ['int']);

        return is_int($value) ? $value : throw self::corrupt($key);
    }

    public function number(string $key): null|float|int
    {
        $value = $this->typed($key, ['percent']);

        return $value === null || is_int($value) || is_float($value) ? $value : throw self::corrupt($key);
    }

    public function string(string $key): ?string
    {
        $value = $this->typed($key, ['string', 'enum', 'path']);

        return $value === null || is_string($value) ? $value : throw self::corrupt($key);
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->typed($key, ['strings']);
        if (!is_array($value)) {
            throw self::corrupt($key);
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * The one key whose value is a boolean widened by a named mode.
     */
    public function boolOrString(string $key): bool|string
    {
        $value = $this->typed($key, ['bool_enum']);

        return is_bool($value) || is_string($value) ? $value : throw self::corrupt($key);
    }

    /**
     * The file this configuration came from, or `null` when it is defaults.
     */
    public function sourcePath(): ?string
    {
        return $this->sourcePath;
    }

    /**
     * @param array<array-key, mixed> $raw
     * @param array<string, mixed> $schema
     * @param array<string, true> $declared
     *
     * @return array<string, mixed>
     */
    private static function walk(
        array $raw,
        array $schema,
        string $prefix,
        string $baseDir,
        ?string $origin,
        array &$declared,
    ): array {
        foreach ($raw as $key => $ignored) {
            if (!is_string($key) || !array_key_exists($key, $schema)) {
                throw self::invalid(self::join($prefix, (string) $key), $origin, 'is not a known configuration key');
            }
        }

        $values = [];

        foreach ($schema as $key => $node) {
            if (!is_array($node)) {
                continue;
            }

            $path = self::join($prefix, $key);
            $present = array_key_exists($key, $raw);

            if (!isset($node['type'])) {
                if ($present && !is_array($raw[$key])) {
                    throw self::invalid($path, $origin, sprintf(
                        'expected a section (array of keys), got %s',
                        get_debug_type($raw[$key]),
                    ));
                }

                /** @var array<array-key, mixed> $branch */
                $branch = $present ? $raw[$key] : [];

                $values[$key] = self::walk($branch, $node, $path, $baseDir, $origin, $declared);
                continue;
            }

            if (!$present) {
                $values[$key] = $node['default'] ?? null;
                continue;
            }

            $declared[$path] = true;
            $values[$key] = self::leafValue($node, $raw[$key], $path, $baseDir, $origin);
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private static function leafValue(
        array $node,
        mixed $value,
        string $path,
        string $baseDir,
        ?string $origin,
    ): mixed {
        /** @var list<string> $allowed */
        $allowed = is_array($node['values'] ?? null) ? $node['values'] : [];

        return match ($node['type']) {
            'bool' => self::boolValue($value, $path, $origin),
            // A named mode wins over the boolean reading, so `'bearer'` stays
            // itself rather than being rejected as an unparseable boolean.
            'bool_enum' => is_string($value) && self::isAllowed($value, $allowed)
                ? $value
                : self::boolValue($value, $path, $origin),
            'enum' => self::enumValue($value, $allowed, $path, $origin),
            'int' => self::intValue($value, $path, $origin),
            'percent' => self::percentValue($value, $path, $origin),
            'string' => $value === null ? null : self::expectString($value, $path, $origin, 'a string'),
            'path' => $value === null ? null : self::pathValue($value, $path, $baseDir, $origin),
            'strings' => self::stringListValue(
                $value,
                $path,
                $origin,
                is_string($node['items'] ?? null) ? $node['items'] : null,
            ),
            default => $value,
        };
    }

    private static function boolValue(mixed $value, string $path, ?string $origin): bool
    {
        return is_bool($value)
            ? $value
            : self::parseBool(self::expectString($value, $path, $origin, 'a boolean'), $path, $origin);
    }

    /**
     * @param list<string> $allowed
     */
    private static function enumValue(mixed $value, array $allowed, string $path, ?string $origin): string
    {
        if (is_string($value) && self::isAllowed($value, $allowed)) {
            return $value;
        }

        throw self::invalid($path, $origin, sprintf(
            'expected one of %s, got %s',
            implode(', ', $allowed),
            self::describe($value),
        ));
    }

    private static function intValue(mixed $value, string $path, ?string $origin): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw self::invalid($path, $origin, sprintf(
            'expected a non-negative integer, got %s',
            self::describe($value),
        ));
    }

    private static function percentValue(mixed $value, string $path, ?string $origin): null|float|int
    {
        if ($value === null) {
            return null;
        }

        $number = match (true) {
            is_int($value) => $value,
            // NAN passes `is_float()` and every comparison against it is
            // false, so a range check alone lets it through — and a coverage
            // gate then treats it as a threshold nothing can ever meet.
            is_float($value) => is_finite($value) ? $value : null,
            is_string($value) && preg_match('/^\d+(\.\d+)?$/', trim($value)) === 1 => (float) trim($value),
            default => null,
        };

        if ($number === null || $number < 0 || $number > 100) {
            throw self::invalid($path, $origin, sprintf(
                'expected a number between 0 and 100, got %s',
                self::describe($value),
            ));
        }

        return $number;
    }

    /**
     * Resolve against the config file's directory, so the same `gesso.php`
     * names the same directory whether the runner started at the project root
     * or in a subdirectory.
     */
    private static function pathValue(mixed $value, string $path, string $baseDir, ?string $origin): string
    {
        $raw = trim(self::expectString($value, $path, $origin, 'a path'));

        if ($raw === '') {
            throw self::invalid($path, $origin, 'expected a path, got an empty string');
        }

        return self::isAbsolutePath($raw) ? $raw : $baseDir . '/' . $raw;
    }

    /**
     * @param null|string $items extra per-entry constraint, from the schema
     *
     * @return list<string>
     */
    private static function stringListValue(mixed $value, string $path, ?string $origin, ?string $items): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($path, $origin, sprintf(
                'expected a list of strings, got %s',
                self::describe($value),
            ));
        }

        $list = [];

        foreach ($value as $index => $entry) {
            $entryPath = $path . '[' . $index . ']';

            if (!is_string($entry)) {
                throw self::invalid($entryPath, $origin, sprintf(
                    'expected a string, got %s',
                    self::describe($entry),
                ));
            }

            // No entry of any of these lists — a spec name, a path prefix, a
            // scheme name, a namespace, a status-code pattern — means anything
            // when it is blank, and every consumer either rejects it or
            // matches nothing with it.
            if (trim($entry) === '') {
                throw self::invalid($entryPath, $origin, 'expected a non-empty string');
            }

            $list[] = $entry;
        }

        if ($items === 'status_code_pattern') {
            self::assertStatusCodePatterns($list, $path, $origin);
        }

        return $list;
    }

    /**
     * Compile the patterns the way the validators will, so an unclosed group
     * is rejected at the file rather than at the first response it is matched
     * against.
     *
     * @param list<string> $patterns
     */
    private static function assertStatusCodePatterns(array $patterns, string $path, ?string $origin): void
    {
        try {
            new StatusCodePatternSet($patterns, $path);
        } catch (InvalidArgumentException $e) {
            throw new InvalidGessoConfigurationException(sprintf(
                'Invalid Gesso configuration in %s: %s',
                $origin ?? self::FILENAME,
                $e->getMessage(),
            ), 0, $e);
        }
    }

    private static function expectString(mixed $value, string $path, ?string $origin, string $expected): string
    {
        if (!is_string($value)) {
            throw self::invalid($path, $origin, sprintf('expected %s, got %s', $expected, self::describe($value)));
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function isAllowed(string $value, array $allowed): bool
    {
        return count($allowed) > 0 && in_array($value, $allowed, true);
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') ||
            str_starts_with($path, '\\\\') ||
            preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? "'" . $value . "'" : get_debug_type($value);
    }

    private static function join(string $prefix, string $key): string
    {
        return $prefix === '' ? $key : $prefix . '.' . $key;
    }

    private static function invalid(string $keyPath, ?string $origin, string $reason): InvalidGessoConfigurationException
    {
        return new InvalidGessoConfigurationException(sprintf(
            'Invalid Gesso configuration in %s: "%s" %s.',
            $origin ?? self::FILENAME,
            $keyPath,
            $reason,
        ));
    }

    /**
     * The schema descriptor for a dotted key path.
     *
     * Anything that is not a declared leaf — a misspelling, or a section
     * addressed as though it were a key — throws. Callers ask for settings by
     * name from all over the codebase, and a reader that answers a name the
     * schema never had is how a setting goes silently unread.
     *
     * @return array<array-key, mixed>
     */
    private static function leafNode(string $key): array
    {
        /** @var mixed $node */
        $node = self::SCHEMA;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                throw new InvalidGessoConfigurationException(sprintf(
                    'Unknown Gesso configuration key "%s" requested.',
                    $key,
                ));
            }

            /** @var mixed $node */
            $node = $node[$segment];
        }

        if (!is_array($node) || !isset($node['type'])) {
            throw new InvalidGessoConfigurationException(sprintf(
                'Gesso configuration key "%s" is a section, not a setting.',
                $key,
            ));
        }

        return $node;
    }

    /**
     * The accessor that reads each schema type. Reading a key through the
     * wrong one is a programming error, not a conversion: `bool()` over
     * `laravel.auto_inject_dummy_credentials` would turn the narrow `'bearer'`
     * mode into `true` and inject dummy credentials for every scheme.
     */
    private static function accessorFor(string $type): string
    {
        return match ($type) {
            'bool' => 'bool()',
            'int' => 'int()',
            'percent' => 'number()',
            'string', 'enum', 'path' => 'string()',
            'strings' => 'strings()',
            'bool_enum' => 'boolOrString()',
            default => 'the matching accessor',
        };
    }

    private static function corrupt(string $key): InvalidGessoConfigurationException
    {
        return new InvalidGessoConfigurationException(sprintf(
            'Gesso configuration key "%s" holds a value its own schema type rejects.',
            $key,
        ));
    }

    /**
     * @param list<string> $accepted schema types this accessor reads
     */
    private function typed(string $key, array $accepted): mixed
    {
        $type = self::leafNode($key)['type'];

        if (!is_string($type) || !in_array($type, $accepted, true)) {
            throw new InvalidGessoConfigurationException(sprintf(
                'Gesso configuration key "%s" is a %s setting; read it with %s.',
                $key,
                is_string($type) ? $type : get_debug_type($type),
                self::accessorFor(is_string($type) ? $type : ''),
            ));
        }

        return $this->leaf($key);
    }

    private function leaf(string $key): mixed
    {
        $node = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                throw new InvalidGessoConfigurationException(sprintf(
                    'Unknown Gesso configuration key "%s" requested.',
                    $key,
                ));
            }

            $node = $node[$segment];
        }

        return $node;
    }
}
