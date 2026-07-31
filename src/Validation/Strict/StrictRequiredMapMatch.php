<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

/**
 * Lookup result returned by {@see StrictRequiredSchemaAnalysis::lookup()}
 * when the observed pointer falls on (or beneath) a map-shaped schema node:
 * one that declares `additionalProperties` (schema form or boolean `true`)
 * and no `properties` at all — i.e. the author has said "this object is a
 * map" (issue #437).
 *
 * Observed keys at such a node are data, not shape: suggesting they be
 * added to `required` would be factually wrong, and there is nothing for
 * the spec author to fix. Callers therefore skip these observations
 * silently — no drift report and, unlike {@see StrictRequiredDisjunctionMatch},
 * no NOTE either.
 *
 * @internal Lookup variants are not part of the SemVer-frozen public API.
 */
final class StrictRequiredMapMatch
{
    /**
     * @param string $coveringPointer schema-side pointer of the map node
     *                                that covers the observation
     */
    public function __construct(
        public readonly string $coveringPointer,
    ) {}
}
