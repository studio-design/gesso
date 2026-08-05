<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

use function array_diff;
use function array_keys;
use function array_map;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
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
        return json_encode(
            self::toDocument($baseline),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /**
     * @throws InvalidArgumentException on malformed JSON, an unknown
     *                                  coverage_baseline_version, or an
     *                                  invalid entry
     */
    public static function parse(string $document): CoverageBaseline
    {
        try {
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Coverage baseline file is not valid JSON: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Coverage baseline file must decode to a JSON object.');
        }

        return self::parseDocument($decoded);
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
        $version = $decoded['coverage_baseline_version'] ?? null;
        if (!is_int($version) || $version !== self::BASELINE_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported coverage_baseline_version: expected %d.',
                self::BASELINE_VERSION,
            ));
        }

        $responses = $decoded['uncovered_responses'] ?? null;
        if (!is_array($responses)) {
            throw new InvalidArgumentException('Coverage baseline "uncovered_responses" must be an array.');
        }

        $baseline = new CoverageBaseline();
        foreach ($responses as $index => $entry) {
            $baseline->add(self::parseEntry($index, $entry));
        }

        return $baseline;
    }

    /** @throws InvalidArgumentException when the file is unreadable or malformed */
    public static function read(string $path): CoverageBaseline
    {
        $document = @file_get_contents($path);
        if ($document === false) {
            throw new InvalidArgumentException(sprintf('Could not read coverage baseline file: %s', $path));
        }

        return self::parse($document);
    }

    /** @throws RuntimeException when the file cannot be written */
    public static function write(string $path, CoverageBaseline $baseline): void
    {
        if (@file_put_contents($path, self::render($baseline)) === false) {
            throw new RuntimeException(sprintf('Could not write coverage baseline file: %s', $path));
        }
    }

    private static function parseEntry(int|string $index, mixed $entry): CoverageBaselineEntry
    {
        if (!is_array($entry)) {
            throw new InvalidArgumentException(sprintf('Coverage baseline entry #%s must be an object.', $index));
        }

        $unknown = array_diff(array_keys($entry), [...self::REQUIRED_STRING_FIELDS, 'content_type']);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Coverage baseline entry #%s has unknown field(s): %s.',
                $index,
                implode(', ', $unknown),
            ));
        }

        $values = [];
        foreach (self::REQUIRED_STRING_FIELDS as $field) {
            $value = $entry[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf(
                    'Coverage baseline entry #%s field "%s" must be a non-empty string.',
                    $index,
                    $field,
                ));
            }
            $values[$field] = $value;
        }

        // `content_type` is the only field copied verbatim from a spec
        // `content` key, so an empty key round-trips instead of failing a
        // regenerated baseline.
        $contentType = $entry['content_type'] ?? null;
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
