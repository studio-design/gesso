<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Closure;
use Studio\Gesso\JsonValidationResultRenderer;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function implode;

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

    /**
     * Composes a failure message for an exchange-style assertion that
     * validated several results at once (PSR-7 request + response).
     *
     * Text mode reproduces the historical merged shape: every error line
     * prefixed with its side label, one trailing `Reproduce:` line. Json mode
     * emits one labelled document per FAILING side — each block after a
     * `[{label}]` line parses standalone against the documented schema, and
     * all blocks share the same `reproduce_command` (the command reproduces
     * the whole exchange):
     *
     *     {header}:
     *     [request]
     *     {document}
     *     [response]
     *     {document}
     *
     * @param array<string, OpenApiValidationResult> $sides label => result,
     *                                                      in output order
     * @param Closure(): string $reproduceCommand see {@see compose()}
     */
    public static function composeExchange(
        string $header,
        array $sides,
        Closure $reproduceCommand,
    ): string {
        if (ValidationOutput::format() === ValidationOutputFormat::Json) {
            $command = $reproduceCommand();
            $blocks = '';
            foreach ($sides as $label => $result) {
                if ($result->isValid()) {
                    continue;
                }

                $blocks .= "[{$label}]\n" . JsonValidationResultRenderer::render($result, $command);
            }

            return "{$header}:\n{$blocks}";
        }

        $lines = [];
        foreach ($sides as $label => $result) {
            foreach ($result->errors() as $error) {
                $lines[] = "[{$label}] {$error}";
            }
        }

        return "{$header}:\n" . implode("\n", $lines) . "\nReproduce: {$reproduceCommand()}";
    }
}
