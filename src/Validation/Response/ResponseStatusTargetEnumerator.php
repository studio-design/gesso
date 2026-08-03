<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use InvalidArgumentException;

use function array_keys;
use function intdiv;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtoupper;

/**
 * Enumerates deterministic wire statuses for declared response keys.
 *
 * @internal Shared by spec-wide response exploration and SDK coverage.
 */
final class ResponseStatusTargetEnumerator
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed> $responses
     *
     * @return list<array{declaredStatusKey: string, selector: string, wireStatus: ?int}>
     */
    public static function enumerate(array $responses): array
    {
        $exact = [];
        $ranges = [];

        foreach (array_keys($responses) as $rawStatus) {
            if (is_string($rawStatus) && str_starts_with($rawStatus, 'x-')) {
                continue;
            }

            $selector = self::normalizeSelector($rawStatus);
            if (preg_match('/^[1-5][0-9]{2}$/', $selector) === 1) {
                $exact[(int) $selector] = true;
            } elseif (preg_match('/^[1-5]XX$/', $selector) === 1) {
                $ranges[(int) $selector[0]] = true;
            }
        }

        $targets = [];
        foreach (array_keys($responses) as $rawStatus) {
            if (is_string($rawStatus) && str_starts_with($rawStatus, 'x-')) {
                continue;
            }

            $declaredStatusKey = (string) $rawStatus;
            $selector = self::normalizeSelector($rawStatus);

            if (preg_match('/^[1-5][0-9]{2}$/', $selector) === 1) {
                $wireStatus = (int) $selector;
            } elseif (preg_match('/^([1-5])XX$/', $selector, $matches) === 1) {
                $wireStatus = self::representativeRangeStatus((int) $matches[1], $exact);
            } else {
                $wireStatus = self::representativeDefaultStatus($exact, $ranges);
            }

            $targets[] = [
                'declaredStatusKey' => $declaredStatusKey,
                'selector' => $selector,
                'wireStatus' => $wireStatus,
            ];
        }

        return $targets;
    }

    private static function normalizeSelector(int|string $status): string
    {
        $normalized = (string) $status;
        if (preg_match('/^[1-5][0-9]{2}$/', $normalized) === 1 || $normalized === 'default') {
            return $normalized;
        }
        if (preg_match('/^[1-5](?:XX|xx)$/', $normalized) === 1) {
            return strtoupper($normalized);
        }

        throw new InvalidArgumentException(sprintf(
            "Invalid response key '%s': expected an exact HTTP status, range status, or default.",
            $normalized,
        ));
    }

    /** @param array<int, true> $exact */
    private static function representativeRangeStatus(int $class, array $exact): ?int
    {
        for ($status = $class * 100; $status <= ($class * 100) + 99; $status++) {
            if (!isset($exact[$status])) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @param array<int, true> $exact
     * @param array<int, true> $ranges
     */
    private static function representativeDefaultStatus(array $exact, array $ranges): ?int
    {
        for ($status = 100; $status <= 599; $status++) {
            if (!isset($exact[$status]) && !isset($ranges[intdiv($status, 100)])) {
                return $status;
            }
        }

        return null;
    }
}
