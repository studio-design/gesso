<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use RuntimeException;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;

use function sprintf;

/**
 * Per-sub-validator error boundary used by {@see OpenApiRequestValidator}
 * and {@see OpenApiResponseValidator}. Wraps a single sub-validator call so a
 * `\RuntimeException` thrown from its internals is converted into a standard
 * error-string entry rather than aborting the whole orchestrator and
 * discarding errors already collected from sibling validators.
 *
 * The catch is intentionally narrowed to `\RuntimeException` rather than the
 * broader `\Exception`: it covers the practical target — opis/json-schema
 * exceptions implementing the `SchemaException` **interface** (all current
 * concrete implementers extend `\RuntimeException`: `ParseException`,
 * `InvalidKeywordException`, `UnresolvedReferenceException`, ...) — while
 * keeping `\LogicException` (e.g. `\InvalidArgumentException` thrown by
 * `Opis\JsonSchema\Validator::validate()` on an invalid schema/URI, or by
 * our own `SchemaValidatorRunner` on invalid construction arguments) and
 * `\Error` (TypeError, AssertionError, ...) bubbling. Both of those families
 * indicate programmer bugs and must not be silently downgraded to a
 * contract-validation error. Mirrors the narrower catch at
 * {@see ValidatesOpenApiSchema::shouldAutoInjectDummyBearer()}.
 *
 * If the thrown `\RuntimeException` carries a `getPrevious()` chain (opis
 * wraps lower-level errors), the previous class + message is appended so the
 * synthetic error line retains the root cause that would otherwise be lost
 * when the stack trace is discarded.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ValidatorErrorBoundary
{
    /**
     * @param callable(): string[] $fn
     *
     * @return string[]
     */
    public static function safely(
        string $stage,
        string $specName,
        string $method,
        string $matchedPath,
        callable $fn,
    ): array {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return [self::captureMessage($stage, $specName, $method, $matchedPath, $e)];
        }
    }

    /**
     * Same boundary for sub-validators that return named errors. The capture
     * entry carries no name — a thrown exception is not attributable to a
     * single parameter.
     *
     * @param callable(): list<NamedError> $fn
     *
     * @return list<NamedError>
     */
    public static function safelyNamed(
        string $stage,
        string $specName,
        string $method,
        string $matchedPath,
        callable $fn,
    ): array {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return [new NamedError(null, self::captureMessage($stage, $specName, $method, $matchedPath, $e))];
        }
    }

    private static function captureMessage(
        string $stage,
        string $specName,
        string $method,
        string $matchedPath,
        RuntimeException $e,
    ): string {
        $previous = $e->getPrevious();
        $previousSuffix = $previous !== null
            ? sprintf(' (caused by %s: %s)', $previous::class, $previous->getMessage())
            : '';

        return sprintf(
            "[%s] %s %s in '%s' spec: %s threw: %s%s",
            $stage,
            $method,
            $matchedPath,
            $specName,
            $e::class,
            $e->getMessage(),
            $previousSuffix,
        );
    }
}
