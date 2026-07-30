<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use const PHP_INT_MAX;

use InvalidArgumentException;
use Studio\Gesso\Spec\OpenApiOperationResolver;

use function array_is_list;
use function array_keys;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function ksort;
use function sort;
use function sprintf;
use function strlen;
use function strpos;

/**
 * Aggregates undocumented response properties per operation.
 *
 * @internal Owned by the PHPUnit extension and parallel merge protocol.
 *
 * @phpstan-type StrictAdditionalPropertiesState array{
 *   version: int,
 *   evaluations: int,
 *   observations: array<string, array<string, array<string, array<string, array{property: string, hits: int}>>>>
 * }
 */
final class StrictAdditionalPropertiesTracker
{
    public const STATE_FORMAT_VERSION = 1;
    public const ANY_CONTENT_TYPE = '*';
    private static ?self $current = null;

    /**
     * @var array<string, array<string, array<string, array<string, array{property: string, hits: int}>>>>
     */
    private array $observations = [];
    private int $evaluations = 0;

    public static function current(): self
    {
        return self::$current ??= new self();
    }

    public static function setCurrent(self $tracker): void
    {
        self::$current = $tracker;
    }

    public static function resetCurrent(): void
    {
        self::$current = null;
    }

    /**
     * @param array<string, string> $findings pointer => property name
     */
    public function recordOn(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
        array $findings,
    ): void {
        if ($this->evaluations === PHP_INT_MAX) {
            throw new InvalidArgumentException('Strict additional-properties evaluation count overflow.');
        }
        $this->evaluations++;
        $endpoint = OpenApiOperationResolver::normalizeMethodForKey($method) . ' ' . $path;
        $response = $statusKey . ':' . $contentTypeKey;
        foreach ($findings as $pointer => $property) {
            $this->recordFinding($specName, $endpoint, $response, $pointer, $property);
        }
    }

    public function evaluationsOn(): int
    {
        return $this->evaluations;
    }

    /**
     * @return list<string>
     */
    public function recordedSpecsOn(): array
    {
        $specs = array_keys($this->observations);
        sort($specs);

        return $specs;
    }

    /**
     * @return array<string, array<string, array<string, array{property: string, hits: int}>>>
     */
    public function getObservationsOn(string $specName): array
    {
        return $this->observations[$specName] ?? [];
    }

    /**
     * @return StrictAdditionalPropertiesState
     */
    public function exportStateOn(): array
    {
        $observations = $this->observations;
        ksort($observations);
        foreach ($observations as &$endpoints) {
            ksort($endpoints);
            foreach ($endpoints as &$responses) {
                ksort($responses);
                foreach ($responses as &$pointers) {
                    ksort($pointers);
                }
                unset($pointers);
            }
            unset($responses);
        }
        unset($endpoints);

        return [
            'version' => self::STATE_FORMAT_VERSION,
            'evaluations' => $this->evaluations,
            'observations' => $observations,
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function importStateOn(array $state): void
    {
        $version = $state['version'] ?? null;
        if ($version !== self::STATE_FORMAT_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported strict additional-properties state version: got %s, expected %d.',
                is_int($version) ? (string) $version : get_debug_type($version),
                self::STATE_FORMAT_VERSION,
            ));
        }
        $observations = $state['observations'] ?? null;
        $evaluations = $state['evaluations'] ?? null;
        if (!is_int($evaluations) || $evaluations < 0) {
            throw new InvalidArgumentException('Strict additional-properties state "evaluations" must be a non-negative integer.');
        }
        if (!is_array($observations) || ($observations !== [] && array_is_list($observations))) {
            throw new InvalidArgumentException('Strict additional-properties state "observations" must be an object map.');
        }

        foreach ($observations as $specName => $endpoints) {
            if (!is_string($specName) || !is_array($endpoints)) {
                throw new InvalidArgumentException('Strict additional-properties state contains a malformed spec row.');
            }
            foreach ($endpoints as $endpoint => $responses) {
                if (!is_string($endpoint) || !is_array($responses)) {
                    throw new InvalidArgumentException('Strict additional-properties state contains a malformed endpoint row.');
                }
                $space = strpos($endpoint, ' ');
                if ($space === false || $space === 0 || $space === strlen($endpoint) - 1) {
                    throw new InvalidArgumentException("Malformed strict additional-properties endpoint key '{$endpoint}'.");
                }
                foreach ($responses as $response => $pointers) {
                    if (!is_string($response) || !is_array($pointers)) {
                        throw new InvalidArgumentException('Strict additional-properties state contains a malformed response row.');
                    }
                    $colon = strpos($response, ':');
                    if ($colon === false || $colon === 0 || $colon === strlen($response) - 1) {
                        throw new InvalidArgumentException("Malformed strict additional-properties response key '{$response}'.");
                    }
                    foreach ($pointers as $pointer => $row) {
                        if (
                            !is_string($pointer) ||
                            !is_array($row) ||
                            !is_string($row['property'] ?? null) ||
                            !is_int($row['hits'] ?? null) ||
                            $row['hits'] < 1
                        ) {
                            throw new InvalidArgumentException('Strict additional-properties state contains a malformed finding row.');
                        }
                        $this->recordFinding($specName, $endpoint, $response, $pointer, $row['property'], $row['hits']);
                    }
                }
            }
        }
        if ($this->evaluations > PHP_INT_MAX - $evaluations) {
            throw new InvalidArgumentException('Strict additional-properties evaluation count overflow while importing state.');
        }
        $this->evaluations += $evaluations;
    }

    private function recordFinding(
        string $specName,
        string $endpoint,
        string $response,
        string $pointer,
        mixed $property,
        int $hits = 1,
    ): void {
        if ($pointer === '' || !is_string($property) || $property === '' || $hits < 1) {
            throw new InvalidArgumentException('Strict additional-properties findings require non-empty string pointers and property names.');
        }
        $row = $this->observations[$specName][$endpoint][$response][$pointer] ?? [
            'property' => $property,
            'hits' => 0,
        ];
        if ($row['property'] !== $property) {
            throw new InvalidArgumentException(sprintf(
                "Strict additional-properties pointer '%s' changed property identity from '%s' to '%s'.",
                $pointer,
                $row['property'],
                $property,
            ));
        }
        if ($row['hits'] > PHP_INT_MAX - $hits) {
            throw new InvalidArgumentException('Strict additional-properties finding hit count overflow while importing state.');
        }
        $row['hits'] += $hits;
        $this->observations[$specName][$endpoint][$response][$pointer] = $row;
    }
}
