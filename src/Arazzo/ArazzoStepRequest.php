<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

use JsonException;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\Internal\CurlCommandFormatter;
use Studio\Gesso\Internal\RequestUriBuilder;

use function implode;
use function is_array;
use function json_encode;

/**
 * The immutable request one Arazzo step sends, with every runtime
 * expression already evaluated. The dispatch callback receives it and
 * translates it for its framework or HTTP client.
 */
final readonly class ArazzoStepRequest
{
    /**
     * @internal Built by the workflow runner.
     *
     * @param string $method as declared in the source (fixed methods upper-cased)
     * @param string $pathTemplate the source's path template, e.g. `/pets/{petId}`
     * @param array<string, mixed> $pathParams
     * @param array<string, mixed> $query
     * @param array<string, list<string>|string> $headers wire strings (booleans as `true`/`false`), name case preserved
     * @param array<string, list<string>|string> $cookies wire strings
     * @param array<string, string> $queryDelimiters query parameter name → wire delimiter of its non-exploded style
     */
    public function __construct(
        public string $stepId,
        public string $specName,
        public ?string $operationId,
        public string $method,
        public string $pathTemplate,
        public array $pathParams,
        public array $query,
        public array $headers,
        public array $cookies,
        public DecodedBody $body,
        public ?string $contentType,
        private array $queryDelimiters = [],
    ) {}

    /**
     * The request target: the path template with percent-encoded path
     * parameters plus the query string, prefixed with `$prefix`. Lists are
     * serialized by the operation's `style` / `explode`: repeated names by
     * default (`tags=red&tags=blue`), delimited when not exploded
     * (`tags=red,blue`).
     */
    public function uri(string $prefix = ''): string
    {
        $query = RequestUriBuilder::openApiQuery($this->query, $this->queryDelimiters);

        return $prefix . RequestUriBuilder::path($this->pathTemplate, $this->pathParams) . ($query === null ? '' : '?' . $query);
    }

    /**
     * The JSON request body, or null when the step sends none. Objects stay
     * objects (`{}` is not turned into `[]`).
     *
     * @throws JsonException
     */
    public function bodyAsJson(): ?string
    {
        return $this->body->present
            ? json_encode($this->body->value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
            : null;
    }

    /**
     * A one-line curl command reproducing this request; sensitive header,
     * cookie and query values are redacted by default.
     */
    public function curlSnippet(string $baseUrl = '', bool $redactSensitiveHeaders = true): string
    {
        $headers = $this->headers;
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $value = RequestUriBuilder::serialise($value);
                $pairs[] = $name . '=' . (is_array($value) ? implode(',', $value) : (string) $value);
            }
            $headers['Cookie'] = implode('; ', $pairs);
        }

        $body = $this->body->present ? (string) json_encode($this->body->value, JSON_PRESERVE_ZERO_FRACTION) : null;
        if ($body !== null && $this->contentType !== null) {
            $headers['Content-Type'] = $this->contentType;
        }

        return CurlCommandFormatter::format($this->method, $this->uri($baseUrl), $headers, $body, $this->contentType, $redactSensitiveHeaders);
    }
}
