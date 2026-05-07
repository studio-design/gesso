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
use function error_get_last;
use function file_exists;
use function file_get_contents;
use function get_debug_type;
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
     * @return list<int|string>
     */
    private static function loadSpecEnumValues(string $fqcn, string $specPath): array
    {
        try {
            $basePath = OpenApiSpecLoader::getBasePath();
        } catch (InvalidOpenApiSpecException $e) {
            // The loader currently only throws BasePathNotConfigured here.
            // If a future loader change leaks a different reason through
            // getBasePath(), re-throw unwrapped rather than silently
            // mislabeling it — the caller can then handle the surprise
            // category explicitly instead of branching on a wrong
            // EnumBindingReason.
            if ($e->reason !== InvalidOpenApiSpecReason::BasePathNotConfigured) {
                throw $e;
            }

            throw new EnumBindingException(
                EnumBindingReason::BasePathNotConfigured,
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

        $content = @file_get_contents($absolute);
        if ($content === false) {
            // file_exists() passed but the read failed — typically a TOCTOU
            // unlink, a permission flip, or a dangling symlink. Surface the
            // raw error_get_last() text so the user can distinguish chmod
            // from race-with-cleanup without running strace.
            $lastError = error_get_last()['message'] ?? 'unknown error';

            throw new EnumBindingException(
                EnumBindingReason::SpecFileNotFound,
                sprintf(
                    'Failed to read bound spec file %s for %s: %s',
                    $absolute,
                    $fqcn,
                    $lastError,
                ),
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
        foreach ($decoded['enum'] as $index => $value) {
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
                        'Bound spec file %s has an unsupported "enum" value at index %d (got %s). Backed PHP enums can only carry string or int.',
                        $absolute,
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
