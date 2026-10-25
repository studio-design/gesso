<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\Internal\RuntimeExpression;
use Studio\Gesso\Internal\RuntimeExpressionContext;
use Studio\Gesso\Internal\RuntimeExpressionEvaluator;

use function count;
use function get_debug_type;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpbrk;
use function strpos;
use function substr;

/**
 * The supported subset of Arazzo `simple` success criteria:
 *
 *     condition  := comparison ( '&&' comparison )*
 *     comparison := operand [ ( == | != | < | <= | > | >= ) operand ]
 *     operand    := runtime-expression | true | false | null | number | 'string'
 *
 * String comparison is case-insensitive, numeric strings coerce for
 * numeric comparison, and `null` equals only `null` (Arazzo 1.1 §Criterion).
 * `||`, `!`, grouping, indexing and `.` property access are rejected by name.
 *
 * @internal Arazzo runner implementation detail; not public API.
 */
final readonly class ArazzoSimpleCondition
{
    private const OPERATORS = ['==', '!=', '<=', '>=', '<', '>'];

    /**
     * @param list<array{left: array{0: bool, 1: mixed}, operator: ?string, right: ?array{0: bool, 1: mixed}}> $comparisons operand = [isExpression, RuntimeExpression|literal]
     */
    private function __construct(
        public string $condition,
        private array $comparisons,
    ) {}

    /**
     * @throws InvalidArgumentException naming the unsupported construct
     * @throws RuntimeException a malformed runtime expression (RuntimeExpressionException)
     */
    public static function parse(string $condition): self
    {
        $tokens = self::tokenize($condition);
        $comparisons = [];
        $index = 0;
        $count = count($tokens);
        while (true) {
            $left = self::operand($tokens[$index] ?? null, $condition);
            $index++;
            $operator = null;
            $right = null;
            if (isset($tokens[$index]) && $tokens[$index][0] === 'op' && in_array($tokens[$index][1], self::OPERATORS, true)) {
                $operator = (string) $tokens[$index][1];
                $right = self::operand($tokens[$index + 1] ?? null, $condition);
                $index += 2;
            }

            $comparisons[] = ['left' => $left, 'operator' => $operator, 'right' => $right];
            if ($index >= $count) {
                break;
            }

            if ($tokens[$index] !== ['op', '&&']) {
                throw new InvalidArgumentException(sprintf("Unexpected '%s' in condition '%s'; comparisons are joined with &&.", (string) $tokens[$index][1], $condition));
            }

            $index++;
        }

        return new self($condition, $comparisons);
    }

    /**
     * @return list<RuntimeExpression>
     */
    public function expressions(): array
    {
        $expressions = [];
        foreach ($this->comparisons as $comparison) {
            foreach ([$comparison['left'], $comparison['right']] as $operand) {
                if ($operand !== null && $operand[0] && $operand[1] instanceof RuntimeExpression) {
                    $expressions[] = $operand[1];
                }
            }
        }

        return $expressions;
    }

    /**
     * @throws RuntimeException when an operand cannot be evaluated or compared
     */
    public function evaluate(RuntimeExpressionContext $context): bool
    {
        foreach ($this->comparisons as $comparison) {
            $left = self::value($comparison['left'], $context);
            if ($comparison['operator'] === null || $comparison['right'] === null) {
                if ($left !== true && $left !== false && $left !== null) {
                    throw new RuntimeException(sprintf('%s is not a boolean (got %s).', self::describe($comparison['left']), get_debug_type($left)));
                }

                if ($left !== true) {
                    return false;
                }

                continue;
            }

            if (!self::compare($left, $comparison['operator'], self::value($comparison['right'], $context))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private static function tokenize(string $condition): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($condition);
        $m = [];
        while ($offset < $length) {
            $rest = substr($condition, $offset);
            $char = $rest[0];
            if (preg_match('/^\s+/', $rest, $m) === 1) {
                $offset += strlen($m[0]);

                continue;
            }

            if ($char === "'") {
                [$value, $consumed] = self::quoted($rest, $condition);
                $tokens[] = ['literal', $value];
                $offset += $consumed;

                continue;
            }

            if (preg_match('/^(==|!=|<=|>=|&&|<|>)/', $rest, $m) === 1) {
                $tokens[] = ['op', $m[1]];
                $offset += strlen($m[1]);

                continue;
            }

            $unsupported = match (true) {
                str_starts_with($rest, '||') => "'||' (or)",
                $char === '!' => "'!' (not)",
                $char === '(' || $char === ')' => "'()' grouping",
                $char === '[' || $char === ']' => "'[]' indexing; use a '#/pointer'",
                $char === '"' => 'double-quoted strings; use single quotes',
                default => null,
            };
            if ($unsupported !== null) {
                throw new InvalidArgumentException(sprintf("Condition '%s' uses %s, which simple criteria do not support yet.", $condition, $unsupported));
            }

            $end = strpbrk($rest, " \t\r\n=!<>&|()[]");
            $word = $end === false ? $rest : substr($rest, 0, strlen($rest) - strlen($end));
            if ($word === '') {
                throw new InvalidArgumentException(sprintf("Unexpected '%s' in condition '%s'.", $char, $condition));
            }

            $offset += strlen($word);
            $tokens[] = self::word($word, $condition);
        }

        if ($tokens === []) {
            throw new InvalidArgumentException('A condition must not be empty.');
        }

        return $tokens;
    }

    /**
     * @return array{0: string, 1: int} value, consumed bytes
     */
    private static function quoted(string $rest, string $condition): array
    {
        $value = '';
        $offset = 1;
        while (true) {
            $close = strpos($rest, "'", $offset);
            if ($close === false) {
                throw new InvalidArgumentException(sprintf("Unterminated string literal in condition '%s'.", $condition));
            }

            $value .= substr($rest, $offset, $close - $offset);
            if (($rest[$close + 1] ?? '') !== "'") {
                return [$value, $close + 1];
            }

            $value .= "'";
            $offset = $close + 2;
        }
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private static function word(string $word, string $condition): array
    {
        if (str_starts_with($word, '$')) {
            if (preg_match('/^\$(request|response|message)\.body\./', $word) === 1) {
                throw new InvalidArgumentException(sprintf("Condition '%s' uses '.' property access on a body; use a JSON Pointer such as \$response.body#/name.", $condition));
            }

            return ['expr', RuntimeExpression::parse($word)];
        }

        return match (true) {
            $word === 'true' => ['literal', true],
            $word === 'false' => ['literal', false],
            $word === 'null' => ['literal', null],
            preg_match('/^-?\d+$/D', $word) === 1 => ['literal', self::integer($word, $condition)],
            preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/D', $word) === 1 => ['literal', (float) $word],
            default => throw new InvalidArgumentException(sprintf(
                "Condition '%s' contains the bare word '%s'; quote string literals with single quotes.",
                $condition,
                $word,
            )),
        };
    }

    private static function integer(string $word, string $condition): int
    {
        // `(int)` would saturate out-of-range digits, making distinct literals compare equal.
        $number = is_numeric($word) ? 0 + $word : null;

        return is_int($number) ? $number : throw new InvalidArgumentException(sprintf(
            "Condition '%s' contains the integer literal '%s', which is outside the 64-bit range and cannot be compared exactly.",
            $condition,
            $word,
        ));
    }

    /**
     * @param null|array{0: string, 1: mixed} $token
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function operand(?array $token, string $condition): array
    {
        if ($token === null || $token[0] === 'op') {
            throw new InvalidArgumentException(sprintf("Condition '%s' is missing an operand.", $condition));
        }

        return [$token[0] === 'expr', $token[1]];
    }

    /**
     * @param array{0: bool, 1: mixed} $operand
     */
    private static function value(array $operand, RuntimeExpressionContext $context): mixed
    {
        return $operand[0] && $operand[1] instanceof RuntimeExpression
            ? RuntimeExpressionEvaluator::evaluate($operand[1], $context)
            : $operand[1];
    }

    /**
     * @param array{0: bool, 1: mixed} $operand
     */
    private static function describe(array $operand): string
    {
        return $operand[1] instanceof RuntimeExpression ? $operand[1]->raw : 'The literal operand';
    }

    private static function compare(mixed $left, string $operator, mixed $right): bool
    {
        if ($operator === '==' || $operator === '!=') {
            return self::equals($left, $right) === ($operator === '==');
        }

        $a = self::number($left);
        $b = self::number($right);
        if ($a === null || $b === null) {
            throw new RuntimeException(sprintf("'%s' needs two numbers, got %s and %s.", $operator, get_debug_type($left), get_debug_type($right)));
        }

        $order = $a <=> $b;

        return match ($operator) {
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            default => $order >= 0,
        };
    }

    private static function equals(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if (is_string($left) && is_string($right)) {
            return strcasecmp($left, $right) === 0;
        }

        if (is_bool($left) || is_bool($right)) {
            return $left === $right;
        }

        $a = self::number($left);
        $b = self::number($right);

        // `<=>` keeps int-vs-int exact (no float rounding of large IDs).
        return $a !== null && $b !== null && ($a <=> $b) === 0;
    }

    private static function number(mixed $value): null|float|int
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value) || !is_numeric($value) || str_contains($value, ' ')) {
            return null;
        }

        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            return (float) $value;
        }

        // An integer string beyond the int range would only compare as a rounded float; refuse it.
        $number = 0 + $value;

        return is_int($number) ? $number : throw new RuntimeException(sprintf("The integer '%s' is outside the exactly comparable range.", $value));
    }
}
