<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use const FILTER_VALIDATE_INT;
use const JSON_PRESERVE_ZERO_FRACTION;
use const PHP_INT_MAX;

use function abs;
use function filter_var;
use function intdiv;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_match;
use function str_repeat;
use function strlen;

/** @internal */
final class DecimalMultiple
{
    /**
     * Return the smallest positive integer that is a multiple of the supplied
     * finite JSON number. For example 1.5 (3/2) yields 3, while 0.5 (1/2)
     * yields 1. Values outside the platform integer range return null.
     */
    public static function integerStep(float|int $multipleOf): ?int
    {
        $fraction = self::fraction($multipleOf);

        return $fraction['numerator'] ?? null;
    }

    /**
     * Return the smallest positive value that is an integer multiple of both
     * finite JSON numbers.
     */
    public static function leastCommonMultiple(float|int $left, float|int $right): null|float|int
    {
        $leftFraction = self::fraction($left);
        $rightFraction = self::fraction($right);
        if ($leftFraction === null || $rightFraction === null) {
            return null;
        }

        $numeratorFactor = intdiv(
            $leftFraction['numerator'],
            self::greatestCommonDivisor($leftFraction['numerator'], $rightFraction['numerator']),
        );
        if ($numeratorFactor > intdiv(PHP_INT_MAX, $rightFraction['numerator'])) {
            return null;
        }
        $numerator = $numeratorFactor * $rightFraction['numerator'];
        $denominator = self::greatestCommonDivisor(
            $leftFraction['denominator'],
            $rightFraction['denominator'],
        );

        return $denominator === 1 ? $numerator : $numerator / $denominator;
    }

    /** @return null|array{numerator: int, denominator: int} */
    private static function fraction(float|int $multipleOf): ?array
    {
        if ($multipleOf <= 0) {
            return null;
        }

        $encoded = json_encode($multipleOf, JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encoded) ||
            preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $encoded, $matches) !== 1) {
            return null;
        }

        $fraction = $matches[2] ?? '';
        $exponent = (int) ($matches[3] ?? 0);
        $digits = ltrim($matches[1] . $fraction, '0');
        if ($digits === '') {
            return null;
        }

        $decimalPlaces = strlen($fraction) - $exponent;
        if ($decimalPlaces < 0) {
            $digits .= str_repeat('0', -$decimalPlaces);
            $decimalPlaces = 0;
        }
        // $digits is ltrim'd of leading zeros, so filter_var's octal-looking rejection cannot bite.
        if ($decimalPlaces > 18 || filter_var($digits, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $numerator = (int) $digits;
        $denominator = 10 ** $decimalPlaces;
        $divisor = self::greatestCommonDivisor($numerator, $denominator);

        return [
            'numerator' => intdiv($numerator, $divisor),
            'denominator' => intdiv($denominator, $divisor),
        ];
    }

    private static function greatestCommonDivisor(int $left, int $right): int
    {
        $left = abs($left);
        $right = abs($right);
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }

        return $left;
    }
}
