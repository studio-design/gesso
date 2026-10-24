<?php

declare(strict_types=1);

namespace Studio\Gesso;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SORT_NUMERIC;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\Fuzz\FuzzGenerationException;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\Fuzz\SchemaDataGenerator;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Response\ResponseSchemaResolutionOutcome;
use Studio\Gesso\Validation\Response\ResponseSchemaResolver;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;

use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_map;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_match;
use function sort;
use function sprintf;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * One spec-derived response for a consumer-side fake: status, headers and a
 * body taken from the operation's `example`/`examples`, or generated from
 * its JSON schema when the spec carries none.
 *
 * Used by the `Http::fake()` and `MockHttpClient` responders
 * ({@see Laravel\OpenApiHttpFake}, {@see Symfony\OpenApiHttpFake}); usable on
 * its own to feed any hand-rolled client fake. Building a fake records no
 * coverage.
 */
final readonly class OpenApiFakeResponse
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private int $status,
        private array $headers,
        private mixed $body,
        private ?string $contentType,
        private string $content,
    ) {}

    /**
     * @param string $path spec template (`/v1/pets/{id}`) or a concrete request path
     * @param null|int $status wire status; null selects the lowest declared 2xx (`2XX` or `default` alone fake as 200)
     * @param null|string $contentType media type to fake; null selects the operation's JSON media type, else its first declared one
     *
     * @throws InvalidArgumentException the operation, status, or media type is not declared, or nothing can be faked for it
     * @throws RuntimeException the spec node is malformed (same diagnostics the response validator reports)
     */
    public static function for(
        string $specName,
        string $method,
        string $path,
        ?int $status = null,
        ?string $contentType = null,
    ): self {
        $resolver = new ResponseSchemaResolver();
        $operation = $resolver->resolveOperation($specName, $method, $path);

        if ($operation->outcome === ResponseSchemaResolutionOutcome::MalformedSpec) {
            throw new RuntimeException((string) $operation->message);
        }
        if ($operation->outcome !== ResponseSchemaResolutionOutcome::Resolved || $operation->responses === null) {
            throw new InvalidArgumentException((string) $operation->message);
        }

        $matchedPath = (string) $operation->matchedPath;
        $version = $operation->version ?? OpenApiVersion::V3_0;
        $dialect = $operation->jsonSchemaDialect;
        $status ??= self::defaultStatus($operation->responses, $specName, $method, $matchedPath);
        $resolution = $resolver->resolveResponseSchema($operation, $status, $contentType);

        switch ($resolution->outcome) {
            case ResponseSchemaResolutionOutcome::StatusNotDeclared:
            case ResponseSchemaResolutionOutcome::ContentTypeNotDeclared:
                throw new InvalidArgumentException((string) $resolution->message);
            case ResponseSchemaResolutionOutcome::MalformedResponse:
            case ResponseSchemaResolutionOutcome::MalformedContent:
                throw new RuntimeException((string) $resolution->message);
            case ResponseSchemaResolutionOutcome::NoContent:
                return new self($status, self::declaredHeaders($resolution->responseSpec ?? [], $version, $dialect, $method, $matchedPath), null, null, '');
            default:
                break;
        }

        $responseSpec = $resolution->responseSpec ?? [];
        /** @var array<string, mixed> $content */
        $content = is_array($responseSpec['content'] ?? null) ? $responseSpec['content'] : [];
        // The shared resolver falls back to the first JSON media type for an
        // undeclared JSON Content-Type (a validation policy); an explicit
        // fake selection must name a declared one.
        $explicitKey = $contentType === null ? null : ContentTypeMatcher::findContentTypeKey(ContentTypeMatcher::normalizeMediaType($contentType), $content);
        if ($contentType !== null && $explicitKey === null) {
            throw new InvalidArgumentException(sprintf(
                "Media type '%s' is not declared for %s %s %d in '%s' spec.",
                $contentType,
                strtoupper($method),
                $matchedPath,
                $status,
                $specName,
            ));
        }
        // OpenAPI sets no minimum on `content`: an empty map, like an absent
        // one, declares a response without a body.
        if ($content === []) {
            return new self($status, self::declaredHeaders($responseSpec, $version, $dialect, $method, $matchedPath), null, null, '');
        }
        $key = $explicitKey ?? $resolution->contentType ?? (string) array_key_first($content);
        $wireType = $contentType !== null ? ContentTypeMatcher::normalizeMediaType($contentType) : self::wireType($key);

        $media = is_array($content[$key] ?? null) ? $content[$key] : [];
        [$body, $hasExample] = self::example($media);
        if (!$hasExample) {
            // Generation goes through the shared resolver (dialect,
            // discriminator); it can only generate for the media entry the
            // resolver selected, which excludes `*/*` by design.
            if ($resolution->outcome !== ResponseSchemaResolutionOutcome::Resolved || $resolution->contentType !== $key) {
                throw new InvalidArgumentException(sprintf(
                    "No example and no JSON schema to generate a body for %s %s %d %s in '%s' spec; add an example to the spec or write the fake by hand.",
                    strtoupper($method),
                    $matchedPath,
                    $status,
                    $key,
                    $specName,
                ));
            }

            // Read cases[0] directly: iterating the cases would record SDK
            // exercise coverage, and building a fake exercises nothing.
            $body = OpenApiResponseExplorer::explore($specName, $method, $path, $status, $contentType, 0)->cases[0]->body;
        }

        $headers = self::declaredHeaders($responseSpec, $version, $dialect, $method, $matchedPath);
        $headers['Content-Type'] = $wireType;

        $encoded = ContentTypeMatcher::isJsonContentType(ContentTypeMatcher::normalizeMediaType($wireType)) || !is_string($body)
            ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            : $body;

        return new self($status, $headers, $body, $wireType, $encoded);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Name => value. Includes `Content-Type` when the response has a body.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * The decoded body (`null` when the response declares no content). An
     * empty `{}` inside a spec example stays a `stdClass`, so it encodes back
     * as `{}`; a generated empty object is an empty array.
     */
    public function body(): mixed
    {
        return $this->body;
    }

    /**
     * The wire body: JSON-encoded for JSON media types, a string example
     * verbatim otherwise, `''` when the response declares no content.
     */
    public function content(): string
    {
        return $this->content;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * @param array<array-key, mixed> $responses
     */
    private static function defaultStatus(array $responses, string $specName, string $method, string $matchedPath): int
    {
        $exact = [];
        $range = false;
        $default = false;
        foreach ($responses as $key => $_response) {
            $key = (string) $key;
            if (preg_match('/^2\d\d$/', $key) === 1) {
                $exact[] = (int) $key;
            } elseif (strtoupper($key) === '2XX') {
                $range = true;
            } elseif ($key === 'default') {
                $default = true;
            }
        }

        if ($exact !== []) {
            sort($exact, SORT_NUMERIC);

            return $exact[0];
        }
        if ($range || $default) {
            return 200;
        }

        throw new InvalidArgumentException(sprintf(
            "No 2xx response is declared for %s %s in '%s' spec; pass \$status explicitly.",
            strtoupper($method),
            $matchedPath,
            $specName,
        ));
    }

    /**
     * A spec key that is a media range cannot be sent on the wire;
     * `application/*` and `*&#47;*` are sent as JSON.
     */
    private static function wireType(string $key): string
    {
        if (!str_contains($key, '*')) {
            return $key;
        }

        $normalized = ContentTypeMatcher::normalizeMediaType($key);
        if ($normalized === 'application/*' || $normalized === '*/*') {
            return 'application/json';
        }

        throw new InvalidArgumentException(sprintf("Media type '%s' is a range; pass a concrete \$contentType.", $key));
    }

    /**
     * The example a Media Type or Header Object carries, preferring the
     * singular `example` over the first entry of `examples` (OpenAPI forbids
     * both).
     *
     * @param array<array-key, mixed> $node
     *
     * @return array{mixed, bool}
     */
    private static function example(array $node): array
    {
        if (array_key_exists('example', $node)) {
            return [$node['example'], true];
        }

        $examples = is_array($node['examples'] ?? null) ? $node['examples'] : [];
        foreach ($examples as $example) {
            if (is_array($example) && array_key_exists('value', $example)) {
                return [$example['value'], true];
            }
        }

        return [null, false];
    }

    /**
     * Every declared response header (not only the required ones, so client
     * code reading e.g. `X-RateLimit-Remaining` sees a value), from its
     * example or its schema. `Content-Type` entries are ignored, as OpenAPI
     * requires, and `Content-Length` is left to the client, which derives it
     * from the body. A header with neither example nor usable schema is left
     * out. Malformed nodes throw, as the response validator rejects them.
     *
     * @param array<array-key, mixed> $responseSpec
     *
     * @return array<string, string>
     */
    private static function declaredHeaders(
        array $responseSpec,
        OpenApiVersion $version,
        ?string $dialect,
        string $method,
        string $matchedPath,
    ): array {
        $declared = $responseSpec['headers'] ?? [];
        if (!is_array($declared)) {
            throw new RuntimeException(sprintf(
                "[response-header] spec 'headers' must be an object for %s %s; got %s.",
                strtoupper($method),
                $matchedPath,
                get_debug_type($declared),
            ));
        }

        $headers = [];
        foreach ($declared as $name => $header) {
            $name = (string) $name;
            if (!is_array($header)) {
                throw new RuntimeException(sprintf(
                    '[response-header.%s] header definition must be an object; got %s.',
                    $name,
                    get_debug_type($header),
                ));
            }
            if (in_array(strtolower(trim($name)), ['content-type', 'content-length'], true)) {
                continue;
            }

            $hasSchema = is_array($header['schema'] ?? null);
            if (!$hasSchema && ($header['required'] ?? false) === true) {
                throw new RuntimeException(sprintf(
                    '[response-header.%s] required header has no schema — cannot validate.',
                    $name,
                ));
            }

            [$value, $found] = self::example($header);
            if (!$found && $hasSchema) {
                try {
                    /** @var array<string, mixed> $schema */
                    $schema = $header['schema'];
                    $value = SchemaDataGenerator::generate(
                        OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Response, null, $dialect),
                        1,
                        0,
                    )[0];
                    $found = true;
                } catch (FuzzGenerationException|InvalidArgumentException) {
                    // An ungeneratable header schema leaves the header out
                    // rather than failing the whole fake.
                }
            }

            $rendered = $found ? self::headerValue($value) : null;
            if ($rendered === null && ($header['required'] ?? false) === true) {
                throw new InvalidArgumentException(sprintf(
                    '[response-header.%s] required header cannot be generated from its schema; add an `example` to the header.',
                    $name,
                ));
            }
            if ($rendered !== null) {
                $headers[$name] = $rendered;
            }
        }

        return $headers;
    }

    /**
     * Serialises a header value with the default `simple` style.
     */
    private static function headerValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value) && array_is_list($value)) {
            return implode(',', array_map(static fn(mixed $item): string => is_bool($item) ? ($item ? 'true' : 'false') : (is_scalar($item) ? (string) $item : ''), $value));
        }

        return null;
    }
}
