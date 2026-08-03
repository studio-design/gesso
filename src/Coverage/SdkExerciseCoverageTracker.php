<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const E_USER_WARNING;
use const PHP_INT_MAX;

use InvalidArgumentException;
use Studio\Gesso\Spec\OpenApiOperationResolver;

use function array_is_list;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function ksort;
use function sprintf;
use function strlen;
use function strpos;
use function trigger_error;

/**
 * Aggregates SDK decoder exercise observations per response schema.
 *
 * @internal Owned by response exploration, the PHPUnit extension, and the
 *           parallel coverage merge protocol.
 *
 * @phpstan-type SdkExerciseObservations array<string, array<string, array<int|string, array<string, int>>>>
 * @phpstan-type SdkExerciseState array{version: int, observations: SdkExerciseObservations}
 */
final class SdkExerciseCoverageTracker
{
    public const STATE_FORMAT_VERSION = 1;
    private static ?self $current = null;

    /** @var SdkExerciseObservations */
    private array $observations = [];

    public static function current(): self
    {
        return self::$current ??= new self();
    }

    public static function setCurrent(self $instance): void
    {
        if (self::$current !== null && self::$current->observations !== []) {
            trigger_error(
                '[OpenAPI SDK Exercise] setCurrent() called while the previous '
                . 'instance still holds recorded observations; observations on '
                . 'the outgoing instance will not contribute to reports. '
                . 'Call resetCurrent() first if this is intentional.',
                E_USER_WARNING,
            );
        }

        self::$current = $instance;
    }

    public static function resetCurrent(): void
    {
        self::$current = null;
    }

    public function recordOn(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
    ): void {
        $endpoint = OpenApiOperationResolver::normalizeMethodForKey($method) . ' ' . $path;
        $hits = $this->observations[$specName][$endpoint][$statusKey][$contentTypeKey] ?? 0;
        if ($hits === PHP_INT_MAX) {
            throw new InvalidArgumentException('SDK exercise coverage hit count overflow.');
        }

        $this->observations[$specName][$endpoint][$statusKey][$contentTypeKey] = $hits + 1;
    }

    /**
     * @return array<string, array<int|string, array<string, int>>>
     */
    public function observationsForSpecOn(string $specName): array
    {
        return $this->observations[$specName] ?? [];
    }

    /** @return SdkExerciseState */
    public function exportStateOn(): array
    {
        $observations = $this->observations;
        ksort($observations);
        foreach ($observations as &$endpoints) {
            ksort($endpoints);
            foreach ($endpoints as &$statuses) {
                ksort($statuses);
                foreach ($statuses as &$contentTypes) {
                    ksort($contentTypes);
                }
                unset($contentTypes);
            }
            unset($statuses);
        }
        unset($endpoints);

        return [
            'version' => self::STATE_FORMAT_VERSION,
            'observations' => $observations,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function importStateOn(array $state): void
    {
        $imported = $this->validateState($state);
        $merged = $this->observations;

        foreach ($imported as $specName => $endpoints) {
            foreach ($endpoints as $endpoint => $statuses) {
                foreach ($statuses as $statusKey => $contentTypes) {
                    foreach ($contentTypes as $contentTypeKey => $hits) {
                        $existing = $merged[$specName][$endpoint][$statusKey][$contentTypeKey] ?? 0;
                        if ($existing > PHP_INT_MAX - $hits) {
                            throw new InvalidArgumentException('SDK exercise coverage hit count overflow while importing state.');
                        }

                        $merged[$specName][$endpoint][$statusKey][$contentTypeKey] = $existing + $hits;
                    }
                }
            }
        }

        $this->observations = $merged;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return SdkExerciseObservations
     */
    private function validateState(array $state): array
    {
        $version = $state['version'] ?? null;
        if ($version !== self::STATE_FORMAT_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported SDK exercise coverage state version: got %s, expected %d.',
                is_int($version) ? (string) $version : get_debug_type($version),
                self::STATE_FORMAT_VERSION,
            ));
        }

        $observations = $state['observations'] ?? null;
        if (!is_array($observations) || ($observations !== [] && array_is_list($observations))) {
            throw new InvalidArgumentException('SDK exercise coverage state "observations" must be an object map.');
        }

        foreach ($observations as $specName => $endpoints) {
            if (!is_string($specName) || !is_array($endpoints) || ($endpoints !== [] && array_is_list($endpoints))) {
                throw new InvalidArgumentException('SDK exercise coverage state contains a malformed spec row.');
            }

            foreach ($endpoints as $endpoint => $statuses) {
                if (!is_string($endpoint) || !is_array($statuses) || ($statuses !== [] && array_is_list($statuses))) {
                    throw new InvalidArgumentException('SDK exercise coverage state contains a malformed endpoint row.');
                }
                $space = strpos($endpoint, ' ');
                if ($space === false || $space === 0 || $space === strlen($endpoint) - 1) {
                    throw new InvalidArgumentException("Malformed SDK exercise coverage endpoint key '{$endpoint}'.");
                }

                foreach ($statuses as $statusKey => $contentTypes) {
                    if (
                        $statusKey === '' ||
                        (is_int($statusKey) && ($statusKey < 100 || $statusKey > 599)) ||
                        !is_array($contentTypes) ||
                        ($contentTypes !== [] && array_is_list($contentTypes))
                    ) {
                        throw new InvalidArgumentException('SDK exercise coverage state contains a malformed status row.');
                    }

                    foreach ($contentTypes as $contentTypeKey => $hits) {
                        if (!is_string($contentTypeKey) || $contentTypeKey === '' || !is_int($hits) || $hits < 1) {
                            throw new InvalidArgumentException('SDK exercise coverage state contains a malformed content-type row.');
                        }
                    }
                }
            }
        }

        /** @var SdkExerciseObservations $observations */
        return $observations;
    }
}
