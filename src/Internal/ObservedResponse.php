<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\HeaderNormalizer;

use function array_key_exists;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * A caller-observed HTTP response normalised for runtime expressions and
 * the response validator. Accepts exactly the two shapes the Links explorer
 * and the Arazzo runner document: a PSR-7 response or
 * `['status' => int, 'headers' => array, 'body' => string]`.
 *
 * @internal Runtime-expression plumbing shared by Links exploration and the Arazzo runner; not public API.
 */
final readonly class ObservedResponse
{
    /**
     * @param array<string, list<string>> $headers lower-cased header name → values
     * @param ?string $contentType raw first `Content-Type` value (parameters kept), null when absent
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public ?string $contentType,
        public string $rawBody,
        public DecodedBody $body,
    ) {}

    /**
     * @throws InvalidArgumentException on an unsupported shape, or a JSON-declared body that does not decode
     */
    public static function from(mixed $response): self
    {
        if ($response instanceof ResponseInterface) {
            return self::build($response->getStatusCode(), $response->getHeaders(), (string) $response->getBody());
        }

        if (!is_array($response) ||
            !array_key_exists('status', $response) || !is_int($response['status']) ||
            !array_key_exists('headers', $response) || !is_array($response['headers']) ||
            !array_key_exists('body', $response) || !is_string($response['body'])) {
            throw new InvalidArgumentException(sprintf(
                'Expected a PSR-7 ResponseInterface or array{status: int, headers: array, body: string}, got %s.',
                get_debug_type($response),
            ));
        }

        return self::build($response['status'], $response['headers'], $response['body']);
    }

    /**
     * @param array<array-key, mixed> $rawHeaders
     */
    private static function build(int $status, array $rawHeaders, string $rawBody): self
    {
        $headers = [];
        foreach (HeaderNormalizer::normalize($rawHeaders) as $name => $value) {
            $values = [];
            foreach (is_array($value) ? $value : [$value] as $single) {
                if (!is_scalar($single)) {
                    throw new InvalidArgumentException(sprintf("Response header '%s' must be a string or a list of strings.", $name));
                }

                $values[] = (string) $single;
            }

            $headers[$name] = $values;
        }

        $contentType = $headers['content-type'][0] ?? null;

        return new self($status, $headers, $contentType, $rawBody, self::decode($rawBody, $contentType));
    }

    private static function decode(string $rawBody, ?string $contentType): DecodedBody
    {
        if ($rawBody === '') {
            return DecodedBody::absent();
        }

        if ($contentType === null || !ContentTypeMatcher::isJsonContentType(ContentTypeMatcher::normalizeMediaType($contentType))) {
            return DecodedBody::present($rawBody);
        }

        try {
            return DecodedBody::decodeJson($rawBody);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(sprintf('Response body declared %s but did not decode: %s', $contentType, $e->getMessage()), previous: $e);
        }
    }
}
