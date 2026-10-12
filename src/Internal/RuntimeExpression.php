<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const PREG_UNMATCHED_AS_NULL;

use function is_string;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * A parsed OpenAPI 3.2 / Arazzo 1.1 runtime expression. Parsing knows every
 * family so malformed text is distinguishable from a recognised family the
 * evaluator does not support yet.
 *
 * Field meaning per family:
 * - Request / Response / Message: `source` is `header|query|path|body|payload`,
 *   `name` the header token or parameter name.
 * - Inputs / Outputs: `name` is the input/output name (dots allowed).
 * - Steps: `name` is the step id, `member` the output name.
 * - Workflows: `name` is the workflow id, `source` `inputs|outputs`, `member` the field name.
 * - SourceDescriptions: `name` is the source name, `member` the reference.
 * - Components: `name` is the component type, `member` the component name.
 *
 * `pointer` is the RFC 6901 string form after `#` (`''` = whole value), or
 * null when the expression carries no `#`.
 *
 * @internal Runtime-expression plumbing shared by Links exploration and the Arazzo runner; not public API.
 */
final readonly class RuntimeExpression
{
    private const TOKEN = "[!#$%&'*+\\-.^_`|~0-9A-Za-z]+";
    private const IDENTIFIER = '[A-Za-z0-9._-]+';
    private const IDENTIFIER_STRICT = '[A-Za-z0-9_-]+';
    private const POINTER = '(?:#(?<pointer>.*))?';

    public function __construct(
        public RuntimeExpressionFamily $family,
        public ?string $source,
        public ?string $name,
        public ?string $member,
        public ?string $pointer,
        public string $raw,
    ) {}

    /**
     * @throws RuntimeExpressionException Malformed
     */
    public static function parse(string $raw): self
    {
        foreach ([RuntimeExpressionFamily::Url, RuntimeExpressionFamily::Method, RuntimeExpressionFamily::StatusCode, RuntimeExpressionFamily::Self] as $exact) {
            if ($raw === $exact->value) {
                return new self($exact, null, null, null, null, $raw);
            }
        }

        $m = [];
        if (preg_match('/^\$(request|response|message)\.(header|query|path|body|payload)(.*)$/s', $raw, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return self::parseSource(RuntimeExpressionFamily::from('$' . $m[1]), $m[2], $m[3], $raw);
        }

        if (preg_match('/^\$(inputs|outputs)\.(?<name>' . self::IDENTIFIER . ')' . self::POINTER . '$/s', $raw, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return self::build(RuntimeExpressionFamily::from('$' . $m[1]), null, $m['name'], null, $m['pointer'], $raw);
        }

        if (preg_match('/^\$steps\.(?<name>' . self::IDENTIFIER_STRICT . ')\.outputs\.(?<member>' . self::IDENTIFIER . ')' . self::POINTER . '$/s', $raw, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return self::build(RuntimeExpressionFamily::Steps, null, $m['name'], $m['member'], $m['pointer'], $raw);
        }

        if (preg_match('/^\$workflows\.(?<name>' . self::IDENTIFIER_STRICT . ')\.(?<source>inputs|outputs)\.(?<member>' . self::IDENTIFIER . ')' . self::POINTER . '$/s', $raw, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return self::build(RuntimeExpressionFamily::Workflows, $m['source'], $m['name'], $m['member'], $m['pointer'], $raw);
        }

        if (preg_match('/^\$sourceDescriptions\.(?<name>' . self::IDENTIFIER_STRICT . ')\.(?<member>.+)$/s', $raw, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return new self(RuntimeExpressionFamily::SourceDescriptions, null, $m['name'], $m['member'], null, $raw);
        }

        if (preg_match('/^\$components\.(?<name>parameters|successActions|failureActions)\.(?<member>' . self::IDENTIFIER . ')$/', $raw, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            return new self(RuntimeExpressionFamily::Components, null, $m['name'], $m['member'], null, $raw);
        }

        throw new RuntimeExpressionException(RuntimeExpressionFailure::Malformed, $raw, 'not a recognised runtime expression.');
    }

    public static function isExpression(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '$');
    }

    /**
     * Split an Arazzo/OpenAPI `expression-string` into literal chunks and
     * embedded `{expression}` parts. A stray `{` or `}` is malformed.
     *
     * @return list<self|string>
     *
     * @throws RuntimeExpressionException Malformed
     */
    public static function parseEmbedded(string $template): array
    {
        $chunks = [];
        $offset = 0;
        $length = strlen($template);
        while ($offset < $length) {
            $open = strpos($template, '{', $offset);
            $close = strpos($template, '}', $offset);
            if ($close !== false && ($open === false || $close < $open)) {
                throw new RuntimeExpressionException(RuntimeExpressionFailure::Malformed, $template, "unmatched '}' in an embedded expression string.");
            }

            if ($open === false) {
                $chunks[] = substr($template, $offset);

                break;
            }

            if ($open > $offset) {
                $chunks[] = substr($template, $offset, $open - $offset);
            }

            $nextOpen = strpos($template, '{', $open + 1);
            if ($close === false || ($nextOpen !== false && $nextOpen < $close)) {
                throw new RuntimeExpressionException(RuntimeExpressionFailure::Malformed, $template, "unterminated '{' in an embedded expression string.");
            }

            $chunks[] = self::parse(substr($template, $open + 1, $close - $open - 1));
            $offset = $close + 1;
        }

        return $chunks;
    }

    /**
     * Decoded RFC 6901 segments of `pointer` (empty for a whole-value or absent pointer).
     *
     * @return list<string>
     */
    public function pointerSegments(): array
    {
        return $this->pointer === null ? [] : JsonPointer::segments($this->pointer);
    }

    private static function parseSource(RuntimeExpressionFamily $family, string $source, string $rest, string $raw): self
    {
        $m = [];
        $valid = match ($source) {
            'header' => preg_match('/^\.(?<name>' . self::TOKEN . ')$/', $rest, $m, PREG_UNMATCHED_AS_NULL) === 1,
            'query', 'path' => preg_match('/^\.(?<name>[^{}]+)$/s', $rest, $m, PREG_UNMATCHED_AS_NULL) === 1,
            'body' => preg_match('/^' . self::POINTER . '$/s', $rest, $m, PREG_UNMATCHED_AS_NULL) === 1,
            'payload' => $family === RuntimeExpressionFamily::Message && preg_match('/^' . self::POINTER . '$/s', $rest, $m, PREG_UNMATCHED_AS_NULL) === 1,
            default => false,
        };

        if (!$valid) {
            throw new RuntimeExpressionException(RuntimeExpressionFailure::Malformed, $raw, 'not a recognised runtime expression.');
        }

        return self::build($family, $source, $m['name'] ?? null, null, $m['pointer'] ?? null, $raw);
    }

    private static function build(RuntimeExpressionFamily $family, ?string $source, ?string $name, ?string $member, ?string $pointer, string $raw): self
    {
        if ($pointer !== null) {
            try {
                JsonPointer::segments($pointer);
            } catch (RuntimeExpressionException $e) {
                throw new RuntimeExpressionException(RuntimeExpressionFailure::Malformed, $raw, $e->getMessage());
            }
        }

        return new self($family, $source, $name, $member, $pointer, $raw);
    }
}
