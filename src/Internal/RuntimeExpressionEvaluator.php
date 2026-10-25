<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

use Studio\Gesso\DecodedBody;
use Studio\Gesso\Spec\OpenApiOperationResolver;

use function array_key_exists;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function sprintf;
use function str_contains;
use function strtolower;

/**
 * Evaluates runtime expressions as typed lookups against a
 * {@see RuntimeExpressionContext}. A whole-value expression preserves the
 * referenced JSON type; an embedded `{expression}` renders scalars only.
 * Anything unsupported, unavailable or missing throws — nothing silently
 * becomes `null` or the expression's own text.
 *
 * @internal Runtime-expression plumbing shared by Links exploration and the Arazzo runner; not public API.
 */
final class RuntimeExpressionEvaluator
{
    private function __construct() {}

    /**
     * @throws RuntimeExpressionException
     */
    public static function evaluate(RuntimeExpression|string $expression, RuntimeExpressionContext $context): mixed
    {
        if (is_string($expression)) {
            $expression = RuntimeExpression::parse($expression);
        }

        return match ($expression->family) {
            RuntimeExpressionFamily::Url => $context->url ?? throw self::unavailable($expression, 'no request URL in this context.'),
            RuntimeExpressionFamily::Method => $context->method !== null
                ? OpenApiOperationResolver::normalizeMethodForKey($context->method)
                : throw self::unavailable($expression, 'no request method in this context.'),
            RuntimeExpressionFamily::StatusCode => $context->response->statusCode ?? throw self::unavailable($expression, 'no response in this context.'),
            RuntimeExpressionFamily::Request => self::request($expression, $context),
            RuntimeExpressionFamily::Response => self::response($expression, $context),
            default => throw new RuntimeExpressionException(
                RuntimeExpressionFailure::UnsupportedFamily,
                $expression->raw,
                sprintf("the '%s' family is not supported here.", $expression->family->value),
            ),
        };
    }

    /**
     * Render an `expression-string` (`'req-{$steps.a.outputs.id}'`).
     *
     * @throws RuntimeExpressionException
     */
    public static function evaluateEmbedded(string $template, RuntimeExpressionContext $context): string
    {
        $rendered = '';
        foreach (RuntimeExpression::parseEmbedded($template) as $chunk) {
            if (is_string($chunk)) {
                $rendered .= $chunk;

                continue;
            }

            $value = self::evaluate($chunk, $context);
            $rendered .= match (true) {
                is_string($value) => $value,
                $value === null, is_bool($value), is_int($value), is_float($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                // ADR 0002 (amended): Arazzo 1.1 would JSON-serialise here; lifting this is tracked under #430.
                default => throw new RuntimeExpressionException(
                    RuntimeExpressionFailure::NonScalarEmbedded,
                    $chunk->raw,
                    'an embedded expression must produce a scalar or null, got an array or object.',
                ),
            };
        }

        return $rendered;
    }

    /**
     * Authored values: non-strings pass through, a string that parses as a
     * whole expression is one, any other string containing `{` is an
     * expression string, `$...` is a (malformed) whole-value expression,
     * anything else is a literal.
     *
     * @throws RuntimeExpressionException
     */
    public static function evaluateValue(mixed $value, RuntimeExpressionContext $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (RuntimeExpression::isExpression($value)) {
            try {
                $expression = RuntimeExpression::parse($value);
            } catch (RuntimeExpressionException $e) {
                // `${$request.body#/price}` is an expression string, not a whole-value expression.
                return str_contains($value, '{') ? self::evaluateEmbedded($value, $context) : throw $e;
            }

            // A JSON Pointer may contain braces: `$request.body#/a{b}` stays a whole-value expression.
            return self::evaluate($expression, $context);
        }

        return str_contains($value, '{') ? self::evaluateEmbedded($value, $context) : $value;
    }

    private static function request(RuntimeExpression $expression, RuntimeExpressionContext $context): mixed
    {
        return match ($expression->source) {
            'header' => self::header($expression, $context->requestHeaders),
            'query' => self::member($expression, $context->requestQuery),
            'path' => self::member($expression, $context->requestPath),
            default => self::body($expression, $context->requestBody ?? throw self::unavailable($expression, 'no request body in this context.')),
        };
    }

    private static function response(RuntimeExpression $expression, RuntimeExpressionContext $context): mixed
    {
        $response = $context->response ?? throw self::unavailable($expression, 'no response in this context.');

        return match ($expression->source) {
            'header' => self::header($expression, $response->headers),
            'body' => self::body($expression, $response->body),
            default => throw self::unavailable($expression, sprintf('a response has no %s parameters.', (string) $expression->source)),
        };
    }

    /**
     * @param array<string, mixed> $headers lower-cased names
     */
    private static function header(RuntimeExpression $expression, array $headers): string
    {
        $name = strtolower((string) $expression->name);
        if (!array_key_exists($name, $headers)) {
            throw self::notFound($expression, sprintf("header '%s' is not present.", (string) $expression->name));
        }

        $value = $headers[$name];

        // RFC 9110 §5.3: multiple field lines combine with ", ".
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function member(RuntimeExpression $expression, array $values): mixed
    {
        if (!array_key_exists((string) $expression->name, $values)) {
            throw self::notFound($expression, sprintf("parameter '%s' is not present.", (string) $expression->name));
        }

        return $values[(string) $expression->name];
    }

    private static function body(RuntimeExpression $expression, DecodedBody $body): mixed
    {
        if (!$body->present) {
            throw self::notFound($expression, 'the body is absent.');
        }

        [$found, $value] = JsonPointer::walk($expression->pointerSegments(), $body->value);
        if (!$found) {
            throw self::notFound($expression, sprintf("JSON Pointer '%s' does not resolve in the body.", (string) $expression->pointer));
        }

        return $value;
    }

    private static function unavailable(RuntimeExpression $expression, string $detail): RuntimeExpressionException
    {
        return new RuntimeExpressionException(RuntimeExpressionFailure::ContextUnavailable, $expression->raw, $detail);
    }

    private static function notFound(RuntimeExpression $expression, string $detail): RuntimeExpressionException
    {
        return new RuntimeExpressionException(RuntimeExpressionFailure::NotFound, $expression->raw, $detail);
    }
}
