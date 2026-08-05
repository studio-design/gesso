<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use const SORT_STRING;

use function array_key_exists;
use function array_values;
use function count;
use function ksort;

/**
 * Set of responses the suite is known not to cover (issue #481).
 *
 * Presence-only, like {@see ViolationBaseline}: an entry says "this response
 * is not covered today", and nothing else about it is worth committing.
 *
 * @internal Implementation detail of the coverage baseline; the committed
 *           baseline file format is the supported surface, not this class.
 */
final class CoverageBaseline
{
    /** @var array<string, CoverageBaselineEntry> keyed by entry key */
    private array $entries = [];

    public function add(CoverageBaselineEntry $entry): void
    {
        $this->entries[$entry->key()] ??= $entry;
    }

    public function contains(CoverageBaselineEntry $entry): bool
    {
        return array_key_exists($entry->key(), $this->entries);
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Entries in deterministic order: field-by-field on the binary-safe
     * entry key.
     *
     * @return list<CoverageBaselineEntry>
     */
    public function sorted(): array
    {
        $sorted = $this->entries;
        ksort($sorted, SORT_STRING);

        return array_values($sorted);
    }
}
