<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Closure;
use Studio\Gesso\JsonValidationResultRenderer;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
use Studio\Gesso\ValidationIssue;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function array_slice;
use function count;
use function explode;
use function implode;
use function preg_replace;

/**
 * Composes the assertion failure message every adapter raises, switching on
 * the process-wide {@see ValidationOutput} format so Laravel, Symfony, Pest,
 * and PSR-7 emit the same shape (issue #282, stage 3).
 *
 * Text mode prints one line per issue, led by its failure code:
 *
 *     {header}:
 *     [GSO212] {message}
 *     Reproduce: {curl}
 *
 * Agent mode (v3 vision item A2) packs every field an agent needs onto the
 * issue line, so a wrapper that keeps the failure message verbatim (such as
 * laravel/pao) still carries it; no pretty-printed JSON, no ANSI:
 *
 *     {header}:
 *     [GSO212] response GET /v1/pets 200 application/json schema=/items/type actual=1 expected="string" {message}
 *     Reproduce: {curl}
 *     Explain: vendor/bin/gesso explain GSO212
 *
 * Text and agent mode print at most `validation.max_errors` issues per result
 * and end the list with `... N more` instead of dropping the rest silently.
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

        return "{$header}:\n" . implode("\n", self::taggedLines($result)) . "\nReproduce: {$reproduceCommand()}" . self::explainHint([$result]);
    }

    /**
     * Composes a failure message for an exchange-style assertion that
     * validated several results at once (PSR-7 request + response).
     *
     * Text mode keeps the merged shape: every tagged issue line
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
            foreach (self::taggedLines($result) as $line) {
                $lines[] = "[{$label}] {$line}";
            }
        }

        return "{$header}:\n" . implode("\n", $lines) . "\nReproduce: {$reproduceCommand()}" . self::explainHint($sides);
    }

    /**
     * One line per issue, led by its failure code (`[GSO212] [/name] ...`).
     * `issues()` mirrors `errors()` one-to-one, so text-mode messages are
     * unchanged; agent mode adds the location and schema context. Lines past
     * the `validation.max_errors` budget collapse into one `... N more` line.
     *
     * @return list<string>
     */
    private static function taggedLines(OpenApiValidationResult $result): array
    {
        $agent = ValidationOutput::format() === ValidationOutputFormat::Agent;
        $issues = $result->issues();
        $budget = ValidationPolicyDefaults::maxErrors();
        $shown = $budget > 0 ? array_slice($issues, 0, $budget) : $issues;

        $lines = [];
        foreach ($shown as $issue) {
            $code = $issue->code();
            $parts = $code === null ? [] : [FailureCatalogue::tag($code)];
            if ($agent) {
                $parts = [...$parts, ...self::agentContext($issue)];
            }
            $parts[] = $agent ? self::oneLine($issue->message) : $issue->message;
            $lines[] = implode(' ', $parts);
        }

        $omitted = count($issues) - count($shown);
        if ($omitted > 0) {
            $lines[] = "... {$omitted} more";
        }

        return $lines;
    }

    /**
     * `request|response`, method, path, status, content type, then the
     * schema pointer and value excerpts when the validator reported them.
     *
     * @return list<string>
     */
    private static function agentContext(ValidationIssue $issue): array
    {
        $parts = [explode('.', $issue->category, 2)[0]];
        foreach ([$issue->method, $issue->path, $issue->statusCode, $issue->contentType] as $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $value;
            }
        }
        foreach (['schema' => $issue->schemaPointer, 'actual' => $issue->actual, 'expected' => $issue->expected] as $key => $value) {
            if ($value !== null) {
                $parts[] = $key . '=' . self::oneLine($value);
            }
        }

        return $parts;
    }

    /** Collapse line breaks and tabs so one issue stays on one line. */
    private static function oneLine(string $value): string
    {
        return preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
    }

    /**
     * Agent mode ends with one `Explain:` line naming the first failure code.
     *
     * @param array<array-key, OpenApiValidationResult> $results
     */
    private static function explainHint(array $results): string
    {
        if (ValidationOutput::format() !== ValidationOutputFormat::Agent) {
            return '';
        }

        foreach ($results as $result) {
            foreach ($result->issues() as $issue) {
                $code = $issue->code();
                if ($code !== null) {
                    return "\nExplain: vendor/bin/gesso explain {$code}";
                }
            }
        }

        return '';
    }
}
