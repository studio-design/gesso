<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\ValidationIssue;

use function array_map;
use function is_string;
use function sprintf;

/**
 * Versioned wire format of the committed violation baseline file
 * (issue #402).
 *
 * The rendered document is deterministic — fully sorted entries, no
 * timestamps — so re-generating an unchanged contract produces a
 * byte-identical file. Parsing validates the whole payload before returning
 * (mirroring `StrictRequiredTracker::importState()`), and re-normalizes
 * hand-edited entries (fixed-HTTP-method casing, instance-path
 * canonicalization) so a literal `/data/0/id` still matches its canonical
 * runtime form. OpenAPI 3.2 custom `additionalOperations` methods keep
 * their exact spelling — they are case-sensitive.
 *
 * @internal The committed baseline file format is the supported,
 *           versioned compatibility surface (docs/versioning.md); this
 *           class is its implementation.
 */
final class ViolationBaselineFile
{
    /**
     * Baseline wire-format version. Parsers reject unknown values rather
     * than guessing — a baseline written by a future library version must
     * fail loudly instead of silently mis-matching fingerprints.
     */
    public const BASELINE_VERSION = 1;

    private const LABEL = 'Baseline';
    private const REQUIRED_STRING_FIELDS = ['spec', 'method', 'path', 'category'];
    private const NULLABLE_STRING_FIELDS = ['status_code', 'content_type', 'parameter', 'instance_path', 'keyword'];

    private function __construct() {}

    /**
     * The baseline document as a plain array — the shape {@see render()}
     * serializes and the shape the v3 sidecar envelope embeds verbatim
     * (issue #417), so both carriers share one versioned format.
     *
     * @return array{baseline_version: int, violations: list<array<string, null|string>>}
     */
    public static function toDocument(ViolationBaseline $baseline): array
    {
        return [
            'baseline_version' => self::BASELINE_VERSION,
            'violations' => array_map(
                static fn(ViolationFingerprint $fingerprint): array => $fingerprint->toArray(),
                $baseline->sorted(),
            ),
        ];
    }

    public static function render(ViolationBaseline $baseline): string
    {
        return BaselineFileFormat::render(self::toDocument($baseline));
    }

    /**
     * @throws InvalidArgumentException on malformed JSON, an unknown
     *                                  baseline_version, or an invalid entry
     */
    public static function parse(string $document): ViolationBaseline
    {
        return self::parseDocument(BaselineFileFormat::decode($document, self::LABEL));
    }

    /**
     * Validate an already-decoded baseline document — the merge CLI parses
     * documents embedded in sidecar envelopes (issue #417), where the JSON
     * decode already happened at the envelope layer.
     *
     * @param array<mixed, mixed> $decoded
     *
     * @throws InvalidArgumentException on an unknown baseline_version or an
     *                                  invalid entry
     */
    public static function parseDocument(array $decoded): ViolationBaseline
    {
        $violations = BaselineFileFormat::entries($decoded, self::LABEL, 'baseline_version', self::BASELINE_VERSION, 'violations');

        $baseline = new ViolationBaseline();
        foreach ($violations as $index => $entry) {
            $baseline->add(self::parseEntry($index, $entry));
        }

        return $baseline;
    }

    /** @throws InvalidArgumentException when the file is unreadable or malformed */
    public static function read(string $path): ViolationBaseline
    {
        return self::parse(BaselineFileFormat::readFile($path, self::LABEL));
    }

    /** @throws RuntimeException when the file cannot be written */
    public static function write(string $path, ViolationBaseline $baseline): void
    {
        BaselineFileFormat::writeFile($path, self::render($baseline), self::LABEL);
    }

    private static function parseEntry(int|string $index, mixed $entry): ViolationFingerprint
    {
        $values = BaselineFileFormat::entry($index, $entry, 'Baseline violation', self::REQUIRED_STRING_FIELDS, self::NULLABLE_STRING_FIELDS);

        foreach (self::NULLABLE_STRING_FIELDS as $field) {
            $value = $values[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Baseline violation #%s field "%s" must be a string or null.',
                    $index,
                    $field,
                ));
            }
            $values[$field] = $value;
        }

        // Route through fromIssue() so hand-edited entries get the same
        // normalization (method casing, numeric-segment canonicalization)
        // as fingerprints produced at runtime.
        return ViolationFingerprint::fromIssue(
            $values['spec'],
            new ValidationIssue(
                $values['category'],
                '',
                instancePath: $values['instance_path'],
                keyword: $values['keyword'],
                method: $values['method'],
                path: $values['path'],
                statusCode: $values['status_code'],
                contentType: $values['content_type'],
                parameter: $values['parameter'],
            ),
            $values['method'],
            $values['path'],
        );
    }
}
