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
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function lcfirst;
use function sprintf;

/**
 * JSON envelope (version field plus one entry list) shared by the committed
 * coverage and violation baseline files; each passes its own labels and keys
 * so error text stays per-format.
 *
 * @internal Implementation detail of {@see CoverageBaselineFile} and {@see ViolationBaselineFile}.
 */
final class BaselineFileFormat
{
    private function __construct() {}

    /** @param array<string, mixed> $document */
    public static function render(array $document): string
    {
        return json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    /** @return array<mixed, mixed> */
    public static function decode(string $document, string $label): array
    {
        try {
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('%s file is not valid JSON: %s', $label, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('%s file must decode to a JSON object.', $label));
        }

        return $decoded;
    }

    /**
     * @param array<mixed, mixed> $decoded
     *
     * @return array<mixed, mixed>
     */
    public static function entries(array $decoded, string $label, string $versionKey, int $version, string $listKey): array
    {
        $found = $decoded[$versionKey] ?? null;
        if (!is_int($found) || $found !== $version) {
            throw new InvalidArgumentException(sprintf('Unsupported %s: expected %d.', $versionKey, $version));
        }

        $entries = $decoded[$listKey] ?? null;
        if (!is_array($entries)) {
            throw new InvalidArgumentException(sprintf('%s "%s" must be an array.', $label, $listKey));
        }

        return $entries;
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     *
     * @return array<string, mixed>
     */
    public static function entry(int|string $index, mixed $entry, string $label, array $required, array $optional): array
    {
        if (!is_array($entry)) {
            throw new InvalidArgumentException(sprintf('%s #%s must be an object.', $label, $index));
        }

        $unknown = array_diff(array_keys($entry), [...$required, ...$optional]);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('%s #%s has unknown field(s): %s.', $label, $index, implode(', ', $unknown)));
        }

        foreach ($required as $field) {
            $value = $entry[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException(sprintf('%s #%s field "%s" must be a non-empty string.', $label, $index, $field));
            }
        }

        /** @var array<string, mixed> $entry */
        return $entry;
    }

    public static function readFile(string $path, string $label): string
    {
        $document = @file_get_contents($path);
        if ($document === false) {
            throw new InvalidArgumentException(sprintf('Could not read %s file: %s', lcfirst($label), $path));
        }

        return $document;
    }

    public static function writeFile(string $path, string $contents, string $label): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write %s file: %s', lcfirst($label), $path));
        }
    }
}
