<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use const SORT_STRING;

use function array_key_exists;
use function array_values;
use function count;
use function ksort;

/**
 * Set of known-violation fingerprints (issue #402).
 *
 * Presence-only by design: occurrence counts depend on how many tests
 * exercise an endpoint, not on the amount of contract debt, so entries
 * collapse on {@see ViolationFingerprint::key()} identity.
 *
 * @internal Implementation detail of the violation baseline; the committed
 *           baseline file format is the supported surface, not this class.
 */
final class ViolationBaseline
{
    /** @var array<string, ViolationFingerprint> keyed by fingerprint key */
    private array $entries = [];

    public function add(ViolationFingerprint $fingerprint): void
    {
        $this->entries[$fingerprint->key()] ??= $fingerprint;
    }

    public function contains(ViolationFingerprint $fingerprint): bool
    {
        return array_key_exists($fingerprint->key(), $this->entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Entries in deterministic order: field-by-field on the binary-safe
     * fingerprint key, null before any string value.
     *
     * @return list<ViolationFingerprint>
     */
    public function sorted(): array
    {
        $sorted = $this->entries;
        ksort($sorted, SORT_STRING);

        return array_values($sorted);
    }
}
