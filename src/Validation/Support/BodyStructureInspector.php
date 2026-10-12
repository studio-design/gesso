<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use function array_key_exists;
use function sprintf;

/**
 * One structural walk for runtime body validation and doctor diagnostics.
 * Runtime stops at the first defect; doctor consumes every yielded defect.
 * This checks shapes, not media-type capabilities or schema semantics.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class BodyStructureInspector
{
    /**
     * @param array<array-key, mixed> $operation
     *
     * @return iterable<array{location: string, node: mixed}>
     */
    public static function request(array $operation): iterable
    {
        // Keep the runtime convention: missing/null requestBody or content
        // are not structural errors. Required-body checks run separately.
        if (!isset($operation['requestBody'])) {
            return;
        }
        $body = $operation['requestBody'];
        if (MalformedSpecNode::isMalformed($body)) {
            yield ['location' => 'requestBody', 'node' => $body];

            return;
        }
        if (!isset($body['content'])) {
            return;
        }
        if (MalformedSpecNode::isMalformed($body['content'])) {
            yield ['location' => 'requestBody.content', 'node' => $body['content']];

            return;
        }

        yield from self::content($body['content'], 'requestBody.content', inspectEncoding: true);
    }

    /**
     * @param array<array-key, mixed> $content
     *
     * @return iterable<array{location: string, node: mixed}>
     */
    public static function content(array $content, string $location, bool $inspectEncoding = false): iterable
    {
        foreach ($content as $mediaType => $mediaSpec) {
            $mediaLocation = sprintf('%s["%s"]', $location, (string) $mediaType);
            if (MalformedSpecNode::isMalformed($mediaSpec)) {
                yield ['location' => $mediaLocation, 'node' => $mediaSpec];

                continue;
            }
            foreach (['schema', 'itemSchema'] as $key) {
                if (array_key_exists($key, $mediaSpec) && MalformedSpecNode::isMalformed($mediaSpec[$key])) {
                    yield ['location' => $mediaLocation . '.' . $key, 'node' => $mediaSpec[$key]];
                }
            }

            // Encoding is request-only before OpenAPI 3.2. Sharing the walk
            // must not widen the 3.0 / 3.1 response structural checks.
            if (!$inspectEncoding || !array_key_exists('encoding', $mediaSpec)) {
                continue;
            }
            $encoding = $mediaSpec['encoding'];
            if (MalformedSpecNode::isMalformed($encoding)) {
                yield ['location' => $mediaLocation . '.encoding', 'node' => $encoding];

                continue;
            }
            foreach ($encoding as $name => $part) {
                if (MalformedSpecNode::isMalformed($part)) {
                    yield ['location' => sprintf('%s.encoding["%s"]', $mediaLocation, (string) $name), 'node' => $part];
                }
            }
        }
    }
}
