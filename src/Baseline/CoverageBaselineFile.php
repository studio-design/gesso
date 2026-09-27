<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use InvalidArgumentException;
use RuntimeException;

use function array_map;
use function is_string;
use function sprintf;

/**
 * Versioned wire format of the committed coverage baseline file (issue #481).
 *
 * Deterministic like the violation baseline — fully sorted entries, no
 * timestamps — so regenerating an unchanged suite produces a byte-identical
 * file and the ratchet shows up as a reviewable diff. Parsing validates the
 * whole payload before returning, and re-normalizes hand-edited entries
 * (fixed-HTTP-method casing) so a literal `get` still matches its canonical
 * runtime form.
 *
 * @internal The committed baseline file format is the supported, versioned
 *           compatibility surface (docs/versioning.md); this class is its
 *           implementation.
 */
final class CoverageBaselineFile
{
    /**
     * Coverage-baseline wire-format version. Parsers reject unknown values
     * rather than guessing — a baseline written by a future library version
     * must fail loudly instead of silently mis-matching entries.
     */
    public const BASELINE_VERSION = 1;

    private const LABEL = 'Coverage baseline';
    private const REQUIRED_STRING_FIELDS = ['spec', 'method', 'path', 'status'];

    private function __construct() {}

    /**
     * @return array{coverage_baseline_version: int, uncovered_responses: list<array<string, string>>}
     */
    public static function toDocument(CoverageBaseline $baseline): array
    {
        return [
            'coverage_baseline_version' => self::BASELINE_VERSION,
            'uncovered_responses' => array_map(
                static fn(CoverageBaselineEntry $entry): array => $entry->toArray(),
                $baseline->sorted(),
            ),
        ];
    }

    public static function render(CoverageBaseline $baseline): string
    {
        return BaselineFileFormat::render(self::toDocument($baseline));
    }

    /**
     * @throws InvalidArgumentException on malformed JSON, an unknown
     *                                  coverage_baseline_version, or an
     *                                  invalid entry
     */
    public static function parse(string $document): CoverageBaseline
    {
        return self::parseDocument(BaselineFileFormat::decode($document, self::LABEL));
    }

    /**
     * @param array<mixed, mixed> $decoded
     *
     * @throws InvalidArgumentException on an unknown
     *                                  coverage_baseline_version or an
     *                                  invalid entry
     */
    public static function parseDocument(array $decoded): CoverageBaseline
    {
        $responses = BaselineFileFormat::entries($decoded, self::LABEL, 'coverage_baseline_version', self::BASELINE_VERSION, 'uncovered_responses');

        $baseline = new CoverageBaseline();
        foreach ($responses as $index => $entry) {
            $baseline->add(self::parseEntry($index, $entry));
        }

        return $baseline;
    }

    /** @throws InvalidArgumentException when the file is unreadable or malformed */
    public static function read(string $path): CoverageBaseline
    {
        return self::parse(BaselineFileFormat::readFile($path, self::LABEL));
    }

    /** @throws RuntimeException when the file cannot be written */
    public static function write(string $path, CoverageBaseline $baseline): void
    {
        BaselineFileFormat::writeFile($path, self::render($baseline), self::LABEL);
    }

    private static function parseEntry(int|string $index, mixed $entry): CoverageBaselineEntry
    {
        $values = BaselineFileFormat::entry($index, $entry, 'Coverage baseline entry', self::REQUIRED_STRING_FIELDS, ['content_type']);

        // `content_type` is the only field copied verbatim from a spec
        // `content` key, so an empty key round-trips instead of failing a
        // regenerated baseline.
        $contentType = $values['content_type'] ?? null;
        if (!is_string($contentType)) {
            throw new InvalidArgumentException(sprintf(
                'Coverage baseline entry #%s field "content_type" must be a string.',
                $index,
            ));
        }

        return CoverageBaselineEntry::create(
            $values['spec'],
            $values['method'],
            $values['path'],
            $values['status'],
            $contentType,
        );
    }
}
