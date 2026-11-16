<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Support;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;

use function array_key_exists;
use function array_key_first;
use function count;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function json_decode;
use function sprintf;
use function strtolower;

/**
 * A Parameter Object declared with `content` instead of `schema`. OpenAPI
 * 3.0-3.2 §Parameter Object: it "MUST include either a `content` field or a
 * `schema` field, but not both", and the `content` map "MUST only contain one
 * entry".
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ParameterContent
{
    /**
     * Why a parameter declaration breaks the schema-or-content rule, or null
     * when it follows it.
     *
     * @param array<array-key, mixed> $parameter
     */
    public static function defect(array $parameter): ?string
    {
        $hasSchema = array_key_exists('schema', $parameter);
        $hasContent = array_key_exists('content', $parameter);
        if ($hasSchema === $hasContent) {
            return $hasSchema ? "declares both 'schema' and 'content'" : "declares neither 'schema' nor 'content'";
        }
        if (!$hasContent) {
            return null;
        }

        $content = $parameter['content'];
        if (MalformedSpecNode::isMalformed($content) || count($content) !== 1) {
            return "'content' must contain exactly one media type";
        }
        $mediaType = array_key_first($content);
        if (!is_string($mediaType) || MalformedSpecNode::isMalformed($content[$mediaType])) {
            return "'content' entry must be a Media Type Object";
        }

        return null;
    }

    /**
     * The single media type of a `content` parameter and its schema (null when
     * it declares none), or null for a `schema` parameter. Assumes
     * {@see defect()} found nothing.
     *
     * @param array<array-key, mixed> $parameter
     *
     * @return null|array{string, null|array<string, mixed>}
     */
    public static function of(array $parameter): ?array
    {
        $content = $parameter['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }
        $mediaType = (string) array_key_first($content);
        $schema = is_array($content[$mediaType]) ? ($content[$mediaType]['schema'] ?? null) : null;

        /** @var null|array<string, mixed> $schema */
        return [$mediaType, MalformedSpecNode::isMalformed($schema) ? null : $schema];
    }

    /**
     * Why each `content` parameter the request carries went unvalidated: its
     * media type is not JSON, so the JSON Schema engine cannot evaluate the
     * schema it declares. The orchestrator returns these as a Skipped result,
     * like a non-JSON request body. `in: querystring` reports its own media
     * types through a warning instead.
     *
     * @param list<array<string, mixed>> $parameters pre-collected merged parameters
     * @param array<array-key, mixed> $queryParams
     * @param array<array-key, mixed> $headers
     * @param array<array-key, mixed> $cookies
     *
     * @return list<string>
     */
    public static function skipReasons(array $parameters, array $queryParams, array $headers, array $cookies): array
    {
        $headers = HeaderNormalizer::normalize($headers);
        $reasons = [];
        foreach ($parameters as $param) {
            $content = self::of($param);
            if ($content === null || $content[1] === null || self::isJson($content[0])) {
                continue;
            }

            $name = (string) $param['name'];
            $present = match ($param['in']) {
                'path' => true,
                'query' => isset($queryParams[$name]),
                'header' => !in_array($headers[strtolower($name)] ?? null, [null, []], true),
                'cookie' => isset($cookies[$name]),
                default => false,
            };
            if ($present) {
                $reasons[] = sprintf(
                    "parameter '%s.%s' declares a schema for media type '%s', which is not JSON, so its value was not validated",
                    (string) $param['in'],
                    $name,
                    $content[0],
                );
            }
        }

        return $reasons;
    }

    public static function isJson(string $mediaType): bool
    {
        return ContentTypeMatcher::isJsonContentType(ContentTypeMatcher::normalizeMediaType($mediaType));
    }

    /**
     * Decode a JSON-serialized parameter value and validate it against its
     * media type's schema. A value that is not valid JSON fails validation.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<NamedError>
     */
    public static function validateJson(
        SchemaValidatorRunner $runner,
        string $name,
        string $label,
        mixed $raw,
        string $mediaType,
        array $schema,
        OpenApiVersion $version,
        ?string $jsonSchemaDialect,
        ?string $scope,
        SchemaContext $context = SchemaContext::Request,
    ): array {
        if (!is_scalar($raw) || is_bool($raw)) {
            return [new NamedError($name, sprintf('[%s] value must be a single %s string; got %s.', $label, $mediaType, get_debug_type($raw)))];
        }

        try {
            $value = json_decode((string) $raw, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return [new NamedError($name, sprintf('[%s] value is not valid %s: %s.', $label, $mediaType, $e->getMessage()))];
        }

        return $runner->validateNamed($name, $label, $schema, $value, $version, $context, $jsonSchemaDialect, $scope);
    }
}
