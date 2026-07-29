<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Closure;
use Studio\Gesso\JsonValidationResultRenderer;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

/**
 * Composes the assertion failure message every adapter raises, switching on
 * the process-wide {@see ValidationOutput} format so Laravel, Symfony, Pest,
 * and PSR-7 emit the same shape (issue #282, stage 3).
 *
 * Text mode reproduces the historical output byte-for-byte:
 *
 *     {header}:
 *     {errorMessage()}
 *     Reproduce: {curl}
 *
 * Json mode keeps the one-line header for humans and replaces the rest with
 * the versioned document (the curl command moves into `reproduce_command`,
 * so no separate `Reproduce:` line is emitted):
 *
 *     {header}:
 *     {JsonValidationResultRenderer::render($result, $curl)}
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class FailureOutput
{
    private function __construct() {}

    /**
     * @param string $header adapter-specific prefix without the trailing
     *                       colon, e.g. `OpenAPI schema validation failed for GET /v1/pets (spec: front)`
     * @param Closure(): string $reproduceCommand built lazily so request
     *                                            body streams are only touched when a failure is actually
     *                                            being reported
     */
    public static function compose(
        string $header,
        OpenApiValidationResult $result,
        Closure $reproduceCommand,
    ): string {
        if (ValidationOutput::format() === ValidationOutputFormat::Json) {
            return "{$header}:\n" . JsonValidationResultRenderer::render($result, $reproduceCommand());
        }

        return "{$header}:\n{$result->errorMessage()}\nReproduce: {$reproduceCommand()}";
    }
}
