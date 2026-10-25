<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\CurlCommandFormatter;
use Studio\Gesso\Internal\RequestUriBuilder;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\FormBodyDecoder;

use function array_is_list;
use function array_map;
use function base64_encode;
use function get_object_vars;
use function http_build_query;
use function implode;
use function is_array;
use function is_object;
use function json_decode;
use function json_encode;
use function sprintf;

/**
 * One generated request case produced by {@see OpenApiEndpointExplorer}.
 *
 * `body` is null when the operation declares no `requestBody` (or no media
 * type the explorer can synthesize; see explorer for the loud-failure cases).
 * `contentType` names the media type the body was generated for:
 * `application/json` unless the operation's only schema-carrying media type
 * is a `+json` one or `application/x-www-form-urlencoded`. Dispatchers send
 * {@see self::bodyAsString()} with `Content-Type: $case->contentType`.
 * `query`, `headers`, `cookies`, and `pathParams` are name → value maps, where
 * the `pathParams` keys are the placeholder *names* extracted from
 * `matchedPath` (e.g. `petId`), not positional. `matchedPath` is the spec
 * template with `{placeholders}` unsubstituted. Use {@see self::uri()} for a
 * concrete URI.
 *
 * A dispatcher must forward `cookies` to its client's cookie bag — Laravel and
 * Symfony test clients take cookies as a separate `SymfonyRequest::create()`
 * argument, so a `Cookie:` request header alone never reaches
 * `$request->cookie(...)`. A dispatcher that drops them would make a
 * cookie-credential `ignored_auth` probe pass for the wrong reason.
 */
final readonly class ExploredCase
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $pathParams
     *
     * @throws InvalidArgumentException when `matchedPath` is empty — the spec
     *                                  template is the only handle the caller
     *                                  has back to "which operation produced
     *                                  this case", so it must always be set.
     */
    public function __construct(
        public mixed $body,
        public array $query,
        public array $headers,
        public array $pathParams,
        public HttpMethod $method,
        public string $matchedPath,
        public ExplorationCaseKind $kind = ExplorationCaseKind::Valid,
        public ?string $targetKeyword = null,
        public ?string $targetPointer = null,
        /** @var list<int> */
        public array $expectedStatusClasses = [],
        public ?int $seed = null,
        public ?int $caseIndex = null,
        /** @var array<string, mixed> name → value; dispatchers must forward these to the client's cookie bag */
        public array $cookies = [],
        public string $contentType = 'application/json',
    ) {
        if ($matchedPath === '') {
            throw new InvalidArgumentException(
                'ExploredCase requires a non-empty matchedPath; an empty template would prevent callers from '
                . 'constructing a request URI.',
            );
        }
    }

    public function withBody(mixed $body): self
    {
        return $this->copy($body, $this->query, $this->headers, $this->pathParams);
    }

    /** @param array<string, mixed> $query */
    public function withQuery(array $query): self
    {
        return $this->copy($this->body, $query, $this->headers, $this->pathParams);
    }

    /** @param array<string, mixed> $headers */
    public function withHeaders(array $headers): self
    {
        return $this->copy($this->body, $this->query, $headers, $this->pathParams);
    }

    /** @param array<string, mixed> $pathParams */
    public function withPathParams(array $pathParams): self
    {
        return $this->copy($this->body, $this->query, $this->headers, $pathParams);
    }

    /** @param array<string, mixed> $cookies */
    public function withCookies(array $cookies): self
    {
        return $this->copy($this->body, $this->query, $this->headers, $this->pathParams, $cookies);
    }

    /**
     * Convert a generated JSON object or array for array-typed HTTP helpers.
     *
     * Empty JSON objects become empty PHP arrays, so callers that must preserve
     * the distinction between `{}` and `[]` must use {@see self::bodyAsJson()}
     * for HTTP dispatch instead (including nested objects).
     *
     * @return null|array<array-key, mixed>
     *
     * @throws JsonException when the generated body cannot be encoded or decoded
     * @throws LogicException when the generated JSON body is a scalar
     */
    public function bodyAsArray(): ?array
    {
        if ($this->body === null) {
            return null;
        }

        $body = json_decode(json_encode($this->body, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            throw new LogicException(
                'ExploredCase::bodyAsArray() requires a JSON object or array body; encode the scalar body directly.',
            );
        }

        return $body;
    }

    /**
     * Encode the original generated value without changing JSON object/array
     * types. Pass this string as the client's raw request content.
     *
     * A null value encodes to the literal `null`, not an absent body. Dispatchers
     * sending a no-body operation or a missing-required-body probe must omit
     * the body separately; this accessor does not infer presence from a value.
     *
     * @throws JsonException when the body cannot be encoded
     */
    public function bodyAsJson(): string
    {
        return json_encode($this->body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Serialize the body for the wire in the media type `contentType` names:
     * JSON ({@see self::bodyAsJson()}) or, for
     * `application/x-www-form-urlencoded`, {@see self::bodyAsFormFields()}
     * through `http_build_query()`.
     *
     * @throws JsonException when a JSON body cannot be encoded
     * @throws LogicException for a media type this class cannot serialize, or a non-object form body
     */
    public function bodyAsString(): string
    {
        $mediaType = ContentTypeMatcher::normalizeMediaType($this->contentType);
        if ($mediaType === FormBodyDecoder::URLENCODED) {
            return http_build_query($this->bodyAsFormFields());
        }
        if (!ContentTypeMatcher::isJsonContentType($mediaType)) {
            throw new LogicException(sprintf('ExploredCase cannot serialize a body as %s.', $this->contentType));
        }

        return $this->bodyAsJson();
    }

    /**
     * The generated object as form fields, the way a client sends them:
     * scalars as strings (`true` / `false`, numbers verbatim), object-valued
     * properties JSON-encoded (the Encoding Object default for an object),
     * lists as lists of the same, and `null` dropped (an absent field).
     *
     * @return array<string, mixed>
     *
     * @throws JsonException when a field cannot be encoded
     * @throws LogicException when the body is not a JSON object
     */
    public function bodyAsFormFields(): array
    {
        // Read the original value tree: bodyAsArray() would turn `{}` into
        // `[]`, which a form then drops or encodes as a JSON array.
        $body = is_object($this->body) ? get_object_vars($this->body) : $this->body ?? [];
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new LogicException('ExploredCase::bodyAsFormFields() requires a JSON object body.');
        }

        $fields = [];
        foreach ($body as $name => $value) {
            if ($value !== null) {
                $fields[(string) $name] = is_array($value) && array_is_list($value)
                    ? array_map(self::formValue(...), $value)
                    : self::formValue($value);
            }
        }

        return $fields;
    }

    public function uri(string $prefix = ''): string
    {
        return RequestUriBuilder::build($this->matchedPath, $this->pathParams, $this->query, $prefix);
    }

    public function replayToken(): string
    {
        return base64_encode((string) json_encode([
            'method' => $this->method->value,
            'path' => $this->matchedPath,
            'seed' => $this->seed,
            'case' => $this->caseIndex,
            'kind' => $this->kind->value,
            'keyword' => $this->targetKeyword,
            'pointer' => $this->targetPointer,
        ]));
    }

    public function replaySnippet(string $specName): string
    {
        if ($this->kind === ExplorationCaseKind::Invalid) {
            return sprintf(
                "OpenApiEndpointExplorer::exploreInvalid('%s', '%s', '%s', expectedStatusClasses: [%s], cases: %d, seed: %d)",
                $specName,
                $this->method->value,
                $this->matchedPath,
                implode(', ', $this->expectedStatusClasses),
                ($this->caseIndex ?? 0) + 1,
                $this->seed ?? 0,
            );
        }

        return sprintf(
            "OpenApiEndpointExplorer::explore('%s', '%s', '%s', cases: %d, seed: %d)",
            $specName,
            $this->method->value,
            $this->matchedPath,
            ($this->caseIndex ?? 0) + 1,
            $this->seed ?? 0,
        );
    }

    public function curlSnippet(string $baseUrl = '', bool $redactSensitiveHeaders = true): string
    {
        $headers = $this->headers;
        $body = null;
        if ($this->body !== null) {
            $headers['Content-Type'] = $this->contentType;
            $body = $this->bodyAsString();
        }
        if ($this->cookies !== []) {
            // Rendered as the wire form so the pasted command reproduces the
            // request; the formatter redacts `Cookie` values by default.
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . (string) RequestUriBuilder::serialise($value);
            }
            $headers['Cookie'] = implode('; ', $pairs);
        }

        return CurlCommandFormatter::format(
            $this->method->value,
            $this->uri($baseUrl),
            $headers,
            $body,
            $body !== null ? $this->contentType : null,
            $redactSensitiveHeaders,
        );
    }

    /** One form field value: objects (and nested lists) as JSON, scalars as text. */
    private static function formValue(mixed $value): string
    {
        return is_array($value) || is_object($value)
            ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
            : (string) RequestUriBuilder::serialise($value);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $pathParams
     * @param null|array<string, mixed> $cookies null keeps the current cookies
     */
    private function copy(
        mixed $body,
        array $query,
        array $headers,
        array $pathParams,
        ?array $cookies = null,
    ): self {
        return new self(
            $body,
            $query,
            $headers,
            $pathParams,
            $this->method,
            $this->matchedPath,
            $this->kind,
            $this->targetKeyword,
            $this->targetPointer,
            $this->expectedStatusClasses,
            $this->seed,
            $this->caseIndex,
            $cookies ?? $this->cookies,
            $this->contentType,
        );
    }
}
