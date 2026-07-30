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
use Studio\Gesso\ValidationIssue;

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
        return json_encode(
            self::toDocument($baseline),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /**
     * @throws InvalidArgumentException on malformed JSON, an unknown
     *                                  baseline_version, or an invalid entry
     */
    public static function parse(string $document): ViolationBaseline
    {
        try {
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Baseline file is not valid JSON: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Baseline file must decode to a JSON object.');
        }

        return self::parseDocument($decoded);
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
        $version = $decoded['baseline_version'] ?? null;
        if (!is_int($version) || $version !== self::BASELINE_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported baseline_version: expected %d.',
                self::BASELINE_VERSION,
            ));
        }

        $violations = $decoded['violations'] ?? null;
        if (!is_array($violations)) {
            throw new InvalidArgumentException('Baseline "violations" must be an array.');
        }

        $baseline = new ViolationBaseline();
        foreach ($violations as $index => $entry) {
            $baseline->add(self::parseEntry($index, $entry));
        }

        return $baseline;
    }

    /** @throws InvalidArgumentException when the file is unreadable or malformed */
    public static function read(string $path): ViolationBaseline
    {
        $document = @file_get_contents($path);
        if ($document === false) {
            throw new InvalidArgumentException(sprintf('Could not read baseline file: %s', $path));
        }

        return self::parse($document);
    }

    /** @throws RuntimeException when the file cannot be written */
    public static function write(string $path, ViolationBaseline $baseline): void
    {
        if (@file_put_contents($path, self::render($baseline)) === false) {
            throw new RuntimeException(sprintf('Could not write baseline file: %s', $path));
        }
    }

    private static function parseEntry(int|string $index, mixed $entry): ViolationFingerprint
    {
        if (!is_array($entry)) {
            throw new InvalidArgumentException(sprintf('Baseline violation #%s must be an object.', $index));
        }

        $unknown = array_diff(
            array_keys($entry),
            [...self::REQUIRED_STRING_FIELDS, ...self::NULLABLE_STRING_FIELDS],
        );
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Baseline violation #%s has unknown field(s): %s.',
                $index,
                implode(', ', $unknown),
            ));
        }

        $values = [];
        foreach (self::REQUIRED_STRING_FIELDS as $field) {
            $value = $entry[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf(
                    'Baseline violation #%s field "%s" must be a non-empty string.',
                    $index,
                    $field,
                ));
            }
            $values[$field] = $value;
        }
        foreach (self::NULLABLE_STRING_FIELDS as $field) {
            $value = $entry[$field] ?? null;
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
