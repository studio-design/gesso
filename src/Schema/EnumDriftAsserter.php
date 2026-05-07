<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Schema;

use const E_USER_WARNING;
use const JSON_THROW_ON_ERROR;

use BackedEnum;
use JsonException;
use ReflectionEnum;
use ReflectionException;
use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;
use Studio\OpenApiContractTesting\Exception\EnumBindingException;
use Studio\OpenApiContractTesting\Exception\EnumBindingReason;
use Studio\OpenApiContractTesting\Exception\EnumDriftException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function enum_exists;
use function file_exists;
use function file_get_contents;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function rtrim;
use function sprintf;
use function trigger_error;

/**
 * Verify that backed PHP enums marked with `#[BoundToOpenApiEnum]` agree
 * with their bound OpenAPI `enum` arrays.
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
 * ]);
 * ```
 *
 * The bound spec path on each `#[BoundToOpenApiEnum]` is resolved relative
 * to the configured spec root (`OpenApiSpecLoader::getBasePath()`).
 */
final class EnumDriftAsserter
{
    /**
     * Compare each enum against its bound spec file and either throw
     * `EnumDriftException` (when `$failOnDrift` is true, the default) or
     * fire `E_USER_WARNING` (when false) if any drift is detected.
     *
     * Misconfigured bindings (missing attribute, missing file, malformed
     * JSON, etc.) always throw `EnumBindingException` regardless of
     * `$failOnDrift` — those are setup errors, not drift signals.
     *
     * `$enumFqcns` are validated at runtime via `enum_exists()`; the type
     * is intentionally `list<string>` rather than `list<class-string>` so
     * tests can pass deliberately-bogus names through the misconfiguration
     * paths without static-analysis friction.
     *
     * @param list<string> $enumFqcns
     *
     * @throws EnumBindingException when any binding cannot be resolved
     * @throws EnumDriftException when drift is detected and `$failOnDrift` is true
     */
    public static function assertNoDrift(array $enumFqcns, bool $failOnDrift = true): void
    {
        $reports = self::detectAll($enumFqcns);
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
     * Compare each enum against its bound spec file and return all reports
     * — including ones that have no drift. Useful for inspection layers
     * (CI dashboards, Markdown summaries) that want the full picture rather
     * than only failures.
     *
     * @param list<string> $enumFqcns
     *
     * @return list<EnumDriftReport>
     *
     * @throws EnumBindingException when any binding cannot be resolved
     */
    public static function detectAll(array $enumFqcns): array
    {
        $reports = [];

        foreach ($enumFqcns as $fqcn) {
            $reports[] = self::detectOne($fqcn);
        }

        return $reports;
    }

    private static function detectOne(string $fqcn): EnumDriftReport
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
            // enum_exists() returned true but reflection failed — practically
            // impossible; surface it as a target-not-enum error rather than
            // letting a raw ReflectionException escape.
            throw new EnumBindingException(
                EnumBindingReason::TargetIsNotEnum,
                sprintf('Failed to reflect %s as an enum: %s', $fqcn, $e->getMessage()),
                enumFqcn: $fqcn,
                previous: $e,
            );
        }

        $attrs = $reflection->getAttributes(BoundToOpenApiEnum::class);
        if ($attrs === []) {
            throw new EnumBindingException(
                EnumBindingReason::AttributeMissing,
                sprintf(
                    '%s is missing the #[BoundToOpenApiEnum] attribute. Add it with the spec-relative path of the JSON file containing the bound enum array.',
                    $fqcn,
                ),
                enumFqcn: $fqcn,
            );
        }

        /** @var BoundToOpenApiEnum $binding */
        $binding = $attrs[0]->newInstance();
        $specPath = $binding->specPath;

        $specValues = self::loadSpecEnumValues($fqcn, $specPath);
        $phpValues = self::extractCaseValues($fqcn);

        return EnumDriftDetector::detect(
            enumFqcn: $fqcn,
            specPath: $specPath,
            phpValues: $phpValues,
            specValues: $specValues,
        );
    }

    /**
     * @return list<int|string>
     */
    private static function extractCaseValues(string $fqcn): array
    {
        $values = [];
        foreach ($fqcn::cases() as $case) {
            // The asserter targets backed enums — pure enums have no scalar
            // identity that can bind to a spec `enum:` array. If a pure
            // enum somehow carries the attribute, it contributes zero values
            // here, and the resulting diff will surface every spec value as
            // "spec-only" drift — a loud and correct signal that the binding
            // is misapplied.
            if ($case instanceof BackedEnum) {
                $values[] = $case->value;
            }
        }

        return $values;
    }

    /**
     * @return list<int|string>
     */
    private static function loadSpecEnumValues(string $fqcn, string $specPath): array
    {
        try {
            $basePath = OpenApiSpecLoader::getBasePath();
        } catch (InvalidOpenApiSpecException $e) {
            // OpenApiSpecLoader throws this with reason BasePathNotConfigured
            // when configure() hasn't been called. Re-shape into a
            // domain-appropriate exception so the caller branches on a
            // single exception type for binding-resolution failures.
            $reason = $e->reason === InvalidOpenApiSpecReason::BasePathNotConfigured
                ? EnumBindingReason::BasePathNotConfigured
                : EnumBindingReason::BasePathNotConfigured;

            throw new EnumBindingException(
                $reason,
                sprintf(
                    'Cannot resolve #[BoundToOpenApiEnum(%s)] on %s: %s',
                    $specPath,
                    $fqcn,
                    $e->getMessage(),
                ),
                enumFqcn: $fqcn,
                specPath: $specPath,
                previous: $e,
            );
        }

        $absolute = rtrim($basePath, '/') . '/' . $specPath;

        if (!file_exists($absolute)) {
            throw new EnumBindingException(
                EnumBindingReason::SpecFileNotFound,
                sprintf(
                    'Bound spec file not found: %s (resolved to %s) for %s',
                    $specPath,
                    $absolute,
                    $fqcn,
                ),
                enumFqcn: $fqcn,
                specPath: $specPath,
            );
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new EnumBindingException(
                EnumBindingReason::SpecFileUnreadable,
                sprintf('Failed to read bound spec file: %s for %s', $absolute, $fqcn),
                enumFqcn: $fqcn,
                specPath: $specPath,
            );
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EnumBindingException(
                EnumBindingReason::MalformedJson,
                sprintf(
                    'Failed to parse bound spec file %s: %s',
                    $absolute,
                    $e->getMessage(),
                ),
                enumFqcn: $fqcn,
                specPath: $specPath,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new EnumBindingException(
                EnumBindingReason::NonMappingRoot,
                sprintf('Bound spec file %s must decode to a JSON object', $absolute),
                enumFqcn: $fqcn,
                specPath: $specPath,
            );
        }

        if (!isset($decoded['enum'])) {
            throw new EnumBindingException(
                EnumBindingReason::EnumKeyMissing,
                sprintf(
                    'Bound spec file %s has no "enum" key. Expected an OpenAPI enum schema like {"type": "string", "enum": [...]}.',
                    $absolute,
                ),
                enumFqcn: $fqcn,
                specPath: $specPath,
            );
        }

        if (!is_array($decoded['enum'])) {
            throw new EnumBindingException(
                EnumBindingReason::EnumKeyNotArray,
                sprintf(
                    'Bound spec file %s has a non-array "enum" key — OpenAPI requires "enum" to be an array of values.',
                    $absolute,
                ),
                enumFqcn: $fqcn,
                specPath: $specPath,
            );
        }

        $values = [];
        foreach ($decoded['enum'] as $value) {
            // OpenAPI permits any JSON value in `enum`, but a backed PHP
            // enum can only carry int or string. Skip non-scalar entries
            // (null / bool / nested arrays) — they will surface as drift
            // since they cannot appear on the PHP side, prompting the user
            // to either narrow the spec or unbind the enum.
            if (is_string($value) || is_int($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<EnumDriftReport> $reports
     */
    private static function renderMessage(array $reports, bool $failOnDrift): string
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
