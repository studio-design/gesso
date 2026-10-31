<?php

declare(strict_types=1);

namespace Studio\Gesso\Schema;

use const E_USER_WARNING;

use BackedEnum;
use ReflectionEnum;
use ReflectionException;
use Studio\Gesso\Attribute\BoundToOpenApiEnum;
use Studio\Gesso\Exception\EnumBindingException;
use Studio\Gesso\Exception\EnumBindingReason;
use Studio\Gesso\Exception\EnumDriftException;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function enum_exists;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function str_ends_with;
use function strrchr;
use function substr;
use function trigger_error;

/**
 * Verify that backed PHP enums agree with the OpenAPI `enum` arrays they
 * are bound to.
 *
 * A backed enum binds to the `components.schemas` entry (carrying `enum`)
 * named after its short class name in any of the given spec names — an
 * `Enum` suffix on either side is ignored, the exact name wins. An explicit
 * `#[BoundToOpenApiEnum('Name')]` overrides the name match.
 *
 * Two failure modes that runtime contract validation cannot catch:
 *
 *  1. **PHP-only values** — a case is added to the PHP enum but the spec is
 *     not updated. Runtime validation only flags it on the code paths that
 *     actually return the new value; untested paths drift silently.
 *  2. **Spec-only values** — a value is added to the spec but no PHP case
 *     exists. Runtime validation can never observe this because the value
 *     cannot be produced by the implementation.
 *
 * Static set-membership checking is the only way to close both holes.
 *
 * Usage:
 *
 * ```php
 * EnumDriftAsserter::assertNoDrift([
 *     \App\Enums\NotificationCodeEnum::class,
 *     \App\Enums\ValidationErrorCodeEnum::class,
 * ], specNames: ['front']);
 * ```
 *
 * @phpstan-type ComponentIndex array{
 *     specs: list<string>,
 *     components: array<string, array{specs: list<string>, enum: mixed, conflict: bool}>,
 * }
 */
final class EnumDriftAsserter
{
    private const COMPONENT_POINTER = '#/components/schemas/';

    /**
     * Compare each enum against its bound component and either throw
     * `EnumDriftException` (when `$failOnDrift` is true, the default) or
     * fire `E_USER_WARNING` (when false) if any drift is detected.
     *
     * Misconfigured bindings (no matching component, a malformed `enum`,
     * etc.) always throw `EnumBindingException` regardless of
     * `$failOnDrift` — those are setup errors, not drift signals.
     *
     * `$enumFqcns` are validated at runtime via `enum_exists()`; the type
     * is intentionally `list<string>` rather than `list<class-string>` so
     * tests can pass deliberately-bogus names through the misconfiguration
     * paths without static-analysis friction.
     *
     * @param list<string> $enumFqcns
     * @param list<string> $specNames spec names `OpenApiSpecLoader::load()` resolves; searched for component bindings
     *
     * @throws EnumBindingException when any binding cannot be resolved
     * @throws EnumDriftException when drift is detected and `$failOnDrift` is true
     */
    public static function assertNoDrift(array $enumFqcns, bool $failOnDrift = true, array $specNames = []): void
    {
        $reports = self::detectAll($enumFqcns, $specNames);
        $drifting = array_values(array_filter(
            $reports,
            static fn(EnumDriftReport $r): bool => $r->hasDrift(),
        ));

        if ($drifting === []) {
            return;
        }

        $message = self::renderMessage($drifting, $failOnDrift);

        if ($failOnDrift) {
            throw new EnumDriftException($drifting, $message);
        }

        trigger_error($message, E_USER_WARNING);
    }

    /**
     * Compare each enum against its bound component and return all reports
     * — including ones that have no drift. Useful for inspection layers
     * (CI dashboards, Markdown summaries) that want the full picture rather
     * than only failures.
     *
     * @param list<string> $enumFqcns
     * @param list<string> $specNames spec names `OpenApiSpecLoader::load()` resolves; searched for component bindings
     * @param bool $skipUnbound drop enums with neither an attribute nor a component name match instead of throwing `AttributeMissing`
     *
     * @return list<EnumDriftReport>
     *
     * @throws EnumBindingException when any binding cannot be resolved
     */
    public static function detectAll(array $enumFqcns, array $specNames = [], bool $skipUnbound = false): array
    {
        $index = self::componentIndex($specNames);
        $reports = [];

        foreach ($enumFqcns as $fqcn) {
            $report = self::detectOne($fqcn, $index, $skipUnbound);
            if ($report !== null) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    /**
     * Render the diagnostic block describing every drifting binding.
     *
     * @param list<EnumDriftReport> $reports
     *
     * @internal Exposed only so the PHPUnit extension can produce the same
     *           block at bootstrap when auto-discovery runs in lenient mode
     *           (where `assertNoDrift` is not called).
     */
    public static function renderMessage(array $reports, bool $failOnDrift): string
    {
        $severity = $failOnDrift ? 'FATAL' : 'WARNING';
        $count = count($reports);
        $header = sprintf(
            "[OpenAPI Enum Drift] %s: %d enum binding(s) drift from spec.\n",
            $severity,
            $count,
        );

        $bodies = array_map(
            static function (EnumDriftReport $report): string {
                $lines = [
                    sprintf('  %s  ->  %s', $report->enumFqcn, $report->specPath),
                ];
                if ($report->phpOnly !== []) {
                    $lines[] = sprintf(
                        '    PHP-only (%d): %s',
                        count($report->phpOnly),
                        self::formatValueList($report->phpOnly),
                    );
                }
                if ($report->specOnly !== []) {
                    $lines[] = sprintf(
                        '    Spec-only (%d): %s',
                        count($report->specOnly),
                        self::formatValueList($report->specOnly),
                    );
                }

                return implode("\n", $lines);
            },
            $reports,
        );

        $footer = "\nAction: align the PHP enum cases with the spec, or update the spec's enum array.";

        return $header . "\n" . implode("\n\n", $bodies) . "\n" . $footer;
    }

    /**
     * @param ComponentIndex $index
     */
    private static function detectOne(string $fqcn, array $index, bool $skipUnbound): ?EnumDriftReport
    {
        if (!enum_exists($fqcn)) {
            throw new EnumBindingException(
                EnumBindingReason::TargetIsNotEnum,
                sprintf(
                    '%s is not an enum. #[BoundToOpenApiEnum] only applies to backed enums.',
                    $fqcn,
                ),
                enumFqcn: $fqcn,
            );
        }

        try {
            $reflection = new ReflectionEnum($fqcn);
        } catch (ReflectionException $e) {
            // enum_exists() returned true yet reflection failed — typically
            // an autoloader race or stub-class mismatch. Carries its own
            // reason so consumers can branch on the genuinely unusual case
            // instead of conflating it with TargetIsNotEnum.
            throw new EnumBindingException(
                EnumBindingReason::ReflectionFailed,
                sprintf('Failed to reflect %s as an enum: %s', $fqcn, $e->getMessage()),
                enumFqcn: $fqcn,
                previous: $e,
            );
        }

        if (!$reflection->isBacked()) {
            // Pure enums have no scalar identity that can bind to a spec
            // `enum:` array. Fail loud here so the user sees a clear
            // diagnostic rather than chasing every spec value as
            // mysterious "spec-only" drift.
            throw new EnumBindingException(
                EnumBindingReason::TargetIsNotBackedEnum,
                sprintf(
                    '%s is a pure enum. #[BoundToOpenApiEnum] requires a backed enum (`enum X: string` or `enum X: int`).',
                    $fqcn,
                ),
                enumFqcn: $fqcn,
            );
        }

        $attrs = $reflection->getAttributes(BoundToOpenApiEnum::class);
        if ($attrs !== []) {
            try {
                $binding = $attrs[0]->newInstance();
            } catch (Throwable $e) {
                // A bare #[BoundToOpenApiEnum] throws ArgumentCountError (an Error).
                throw new EnumBindingException(
                    EnumBindingReason::AttributeInvalid,
                    sprintf('%s: %s', $fqcn, $e->getMessage()),
                    enumFqcn: $fqcn,
                    previous: $e,
                );
            }
            [$label, $specValues] = self::componentValues($index, $binding->component, $fqcn)
                ?? throw new EnumBindingException(
                    EnumBindingReason::ComponentNotFound,
                    sprintf(
                        '%s binds to components.schemas.%s, which no configured spec (%s) defines with an "enum" array.',
                        $fqcn,
                        $binding->component,
                        $index['specs'] === [] ? 'none configured' : implode(', ', $index['specs']),
                    ),
                    enumFqcn: $fqcn,
                    specPath: self::COMPONENT_POINTER . $binding->component,
                );
        } else {
            [$short, $alternate] = self::nameCandidates($fqcn);
            $match = self::componentValues($index, $short, $fqcn) ?? self::componentValues($index, $alternate, $fqcn);
            if ($match === null) {
                if ($skipUnbound) {
                    return null;
                }

                throw new EnumBindingException(
                    EnumBindingReason::AttributeMissing,
                    sprintf(
                        '%s is not bound to a spec enum: no components.schemas entry named %s (or %s) carries an "enum" array in the configured specs, and the class has no #[BoundToOpenApiEnum(component: ...)] attribute.',
                        $fqcn,
                        $short,
                        $alternate,
                    ),
                    enumFqcn: $fqcn,
                );
            }
            [$label, $specValues] = $match;
        }

        return EnumDriftDetector::detect(
            enumFqcn: $fqcn,
            specPath: $label,
            phpValues: self::extractCaseValues($fqcn),
            specValues: $specValues,
        );
    }

    /**
     * Index every `components.schemas` entry carrying an `enum` key across
     * `$specNames`. A name defined with different value sets in two specs is
     * recorded as a conflict, and a non-array `enum` is kept as is: both are
     * thrown only once an enum binds to the name (unrelated APIs may both
     * define `Status`).
     *
     * @param list<string> $specNames
     *
     * @return ComponentIndex
     */
    private static function componentIndex(array $specNames): array
    {
        $components = [];
        foreach ($specNames as $specName) {
            $schemas = OpenApiSpecLoader::load($specName)['components']['schemas'] ?? [];
            if (!is_array($schemas)) {
                continue;
            }
            $objectEnums = OpenApiSpecLoader::objectShapedComponentEnums($specName);
            foreach ($schemas as $name => $schema) {
                if (!is_array($schema) || !array_key_exists('enum', $schema)) {
                    continue;
                }
                $name = (string) $name;
                // load() collapses `enum: {"0": "red"}` to the list ["red"];
                // keep the object so componentValues() rejects it.
                $enum = $objectEnums[$name] ?? $schema['enum'];
                $existing = $components[$name] ?? null;
                if ($existing === null) {
                    $components[$name] = ['specs' => [$specName], 'enum' => $enum, 'conflict' => false];
                } elseif (!$existing['conflict'] && !self::sameValues($existing['enum'], $enum)) {
                    $components[$name] = ['specs' => [...$existing['specs'], $specName], 'enum' => null, 'conflict' => true];
                }
            }
        }

        return ['specs' => $specNames, 'components' => $components];
    }

    /**
     * @param ComponentIndex $index
     *
     * @return null|array{0: string, 1: list<int|string>}
     */
    private static function componentValues(array $index, string $name, string $fqcn): ?array
    {
        $entry = $index['components'][$name] ?? null;
        if ($entry === null) {
            return null;
        }

        $label = self::COMPONENT_POINTER . $name;
        if ($entry['conflict']) {
            throw new EnumBindingException(
                EnumBindingReason::ComponentConflict,
                sprintf(
                    'components.schemas.%s is defined with different "enum" values in specs %s; align them before binding %s to it.',
                    $name,
                    implode(', ', $entry['specs']),
                    $fqcn,
                ),
                enumFqcn: $fqcn,
                specPath: $label,
            );
        }

        if (!is_array($entry['enum']) || !array_is_list($entry['enum'])) {
            throw new EnumBindingException(
                EnumBindingReason::EnumKeyNotArray,
                sprintf(
                    'components.schemas.%s has a non-array "enum" key — OpenAPI requires "enum" to be an array of values.',
                    $name,
                ),
                enumFqcn: $fqcn,
                specPath: $label,
            );
        }

        return [$label, self::scalarEnumValues($entry['enum'], $fqcn, 'components.schemas.' . $name, $label)];
    }

    /**
     * Short class name plus its `Enum`-suffix counterpart (added when absent,
     * stripped when present).
     *
     * @return array{0: string, 1: string}
     */
    private static function nameCandidates(string $fqcn): array
    {
        $short = substr((string) strrchr('\\' . $fqcn, '\\'), 1);

        return [$short, str_ends_with($short, 'Enum') ? substr($short, 0, -4) : $short . 'Enum'];
    }

    /**
     * Order-insensitive strict set equality; non-lists compare strictly.
     */
    private static function sameValues(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b) || !array_is_list($a) || !array_is_list($b)) {
            return $a === $b;
        }

        foreach ([[$a, $b], [$b, $a]] as [$left, $right]) {
            foreach ($left as $value) {
                if (!in_array($value, $right, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Caller must have already verified the target is a backed enum
     * (see `detectOne`'s `isBacked()` guard). The `instanceof` here is
     * a static-narrowing aid for PHPStan, not a runtime safety net.
     *
     * @return list<int|string>
     */
    private static function extractCaseValues(string $fqcn): array
    {
        $values = [];
        foreach ($fqcn::cases() as $case) {
            if ($case instanceof BackedEnum) {
                $values[] = $case->value;
            }
        }

        return $values;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<int|string>
     */
    private static function scalarEnumValues(array $raw, string $fqcn, string $subject, string $specPath): array
    {
        $values = [];
        foreach ($raw as $index => $value) {
            // OpenAPI permits any JSON value in `enum`, but a backed PHP
            // enum can only carry int or string. Bail loudly on the first
            // unsupported entry — silently dropping non-scalars would let
            // a malformed spec pass the asserter clean (the dropped value
            // never reaches the diff, so neither phpOnly nor specOnly
            // surfaces it).
            if (!is_string($value) && !is_int($value)) {
                throw new EnumBindingException(
                    EnumBindingReason::EnumValueUnsupported,
                    sprintf(
                        '%s has an unsupported "enum" value at index %d (got %s). Backed PHP enums can only carry string or int.',
                        $subject,
                        $index,
                        get_debug_type($value),
                    ),
                    enumFqcn: $fqcn,
                    specPath: $specPath,
                );
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param list<int|string> $values
     */
    private static function formatValueList(array $values): string
    {
        return implode(', ', array_map(
            static fn(int|string $v): string => is_string($v) ? sprintf('"%s"', $v) : (string) $v,
            $values,
        ));
    }
}
