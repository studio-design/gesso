<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Psr7;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\DecodedBody;
use Studio\OpenApiContractTesting\OpenApiRequestValidator;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\OpenApiValidationResult;
use Studio\OpenApiContractTesting\Validation\Support\ContentTypeMatcher;

use function array_merge;
use function explode;
use function json_decode;
use function ltrim;
use function parse_str;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Framework-independent adapter for validating PSR-7 HTTP messages.
 *
 * Body streams are read only when they are seekable. The original cursor is
 * restored after decoding; a non-seekable stream produces a validation error
 * without being consumed.
 */
final class OpenApiPsr7Validator
{
    private readonly OpenApiRequestValidator $requestValidator;
    private readonly OpenApiResponseValidator $responseValidator;

    /**
     * @param string[] $skipResponseCodes
     * @param string[] $skipRequestValidationResponseCodes
     */
    public function __construct(
        private readonly string $specName,
        int $maxErrors = 20,
        array $skipResponseCodes = OpenApiResponseValidator::DEFAULT_SKIP_RESPONSE_CODES,
        array $skipRequestValidationResponseCodes = OpenApiRequestValidator::DEFAULT_SKIP_REQUEST_VALIDATION_RESPONSE_CODES,
    ) {
        $this->requestValidator = new OpenApiRequestValidator(
            maxErrors: $maxErrors,
            skipRequestValidationResponseCodes: $skipRequestValidationResponseCodes,
        );
        $this->responseValidator = new OpenApiResponseValidator(
            maxErrors: $maxErrors,
            skipResponseCodes: $skipResponseCodes,
        );
    }

    /**
     * Validate a PSR-7 request. ServerRequestInterface supplies its parsed
     * query/cookie parameters; a client RequestInterface is parsed from the
     * URI query and Cookie header.
     */
    public function validateRequest(
        RequestInterface $request,
        ?int $responseStatusCode = null,
    ): OpenApiValidationResult {
        $method = $request->getMethod();
        $path = self::requestPath($request);
        $contentType = self::contentType($request);
        $decoded = $this->decodeBody($request->getBody(), $contentType, 'Request');

        if ($request instanceof ServerRequestInterface) {
            /** @var array<string, mixed> $queryParams */
            $queryParams = $request->getQueryParams();
            /** @var array<string, mixed> $cookies */
            $cookies = $request->getCookieParams();
        } else {
            $queryParams = self::parseQuery($request);
            $cookies = self::parseCookieHeader($request);
        }

        $result = $this->requestValidator->validate(
            $this->specName,
            $method,
            $path,
            $queryParams,
            $request->getHeaders(),
            $decoded['body'],
            $contentType,
            $cookies,
            $responseStatusCode,
        );
        $result = self::withAdapterErrors($result, $decoded['errors']);

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::recordRequest(
                $this->specName,
                $method,
                $result->matchedPath(),
                $result->isSkipped() ? $result->skipReason() : null,
            );
        }

        return $result;
    }

    /**
     * Resolve the operation from a PSR-7 request and validate the response.
     */
    public function validateResponse(
        RequestInterface $request,
        ResponseInterface $response,
    ): OpenApiValidationResult {
        return $this->validateResponseForOperation(
            $request->getMethod(),
            self::requestPath($request),
            $response,
        );
    }

    /**
     * Validate a response for an explicit OpenAPI operation address.
     */
    public function validateResponseForOperation(
        string $method,
        string $requestPath,
        ResponseInterface $response,
    ): OpenApiValidationResult {
        $contentType = self::contentType($response);
        $decoded = $this->decodeBody($response->getBody(), $contentType, 'Response');
        $result = $this->responseValidator->validate(
            $this->specName,
            $method,
            $requestPath,
            $response->getStatusCode(),
            $decoded['body'],
            $contentType,
            $response->getHeaders(),
        );
        $result = self::withAdapterErrors($result, $decoded['errors']);

        if ($result->matchedPath() !== null) {
            OpenApiCoverageTracker::recordResponse(
                $this->specName,
                $method,
                $result->matchedPath(),
                $result->matchedStatusCode() ?? (string) $response->getStatusCode(),
                $result->matchedContentType(),
                schemaValidated: !$result->isSkipped(),
                skipReason: $result->skipReason(),
            );
        }

        return $result;
    }

    /**
     * Validate both sides of one PSR-7 exchange against the request operation.
     */
    public function validateExchange(
        RequestInterface $request,
        ResponseInterface $response,
    ): OpenApiPsr7ValidationResult {
        return new OpenApiPsr7ValidationResult(
            $this->validateRequest($request, $response->getStatusCode()),
            $this->validateResponse($request, $response),
        );
    }

    /** @return array{body: DecodedBody, errors: non-empty-list<string>} */
    private static function bodyReadFailure(string $subject, string $reason): array
    {
        return [
            'body' => DecodedBody::absent(),
            'errors' => [sprintf('%s %s.', $subject, $reason)],
        ];
    }

    /** @param list<string> $adapterErrors */
    private static function withAdapterErrors(
        OpenApiValidationResult $result,
        array $adapterErrors,
    ): OpenApiValidationResult {
        if ($adapterErrors === []) {
            return $result;
        }

        return OpenApiValidationResult::failure(
            array_merge($adapterErrors, $result->errors()),
            $result->matchedPath(),
            $result->matchedStatusCode(),
            $result->matchedContentType(),
        );
    }

    private static function requestPath(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        return $path === '' ? '/' : $path;
    }

    private static function contentType(RequestInterface|ResponseInterface $message): ?string
    {
        $contentType = trim($message->getHeaderLine('Content-Type'));

        return $contentType === '' ? null : $contentType;
    }

    /** @return array<string, mixed> */
    private static function parseQuery(RequestInterface $request): array
    {
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    /** @return array<string, string> */
    private static function parseCookieHeader(RequestInterface $request): array
    {
        $cookies = [];
        foreach ($request->getHeader('Cookie') as $header) {
            foreach (explode(';', $header) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $pair, 2);
                $name = trim($name);
                if ($name === '') {
                    continue;
                }

                $cookies[$name] = rawurldecode(ltrim($value));
            }
        }

        return $cookies;
    }

    /**
     * @return array{body: DecodedBody, errors: list<string>}
     */
    private function decodeBody(
        StreamInterface $stream,
        ?string $contentType,
        string $subject,
    ): array {
        if ($contentType !== null && !ContentTypeMatcher::isJsonContentType(
            ContentTypeMatcher::normalizeMediaType($contentType),
        )) {
            return ['body' => DecodedBody::absent(), 'errors' => []];
        }

        if ($stream->getSize() === 0) {
            return ['body' => DecodedBody::absent(), 'errors' => []];
        }

        if (!$stream->isReadable()) {
            return self::bodyReadFailure($subject, 'body stream is not readable');
        }

        if (!$stream->isSeekable()) {
            return self::bodyReadFailure(
                $subject,
                'body stream is not seekable; validation was refused to avoid consuming caller state',
            );
        }

        try {
            $position = $stream->tell();
            $stream->rewind();
            $content = $stream->getContents();
        } catch (RuntimeException $e) {
            return self::bodyReadFailure($subject, 'body stream could not be read: ' . $e->getMessage());
        } finally {
            if (isset($position)) {
                try {
                    $stream->seek($position);
                } catch (RuntimeException $e) {
                    return self::bodyReadFailure($subject, 'body stream cursor could not be restored: ' . $e->getMessage());
                }
            }
        }

        if ($content === '') {
            return ['body' => DecodedBody::absent(), 'errors' => []];
        }

        try {
            /** @var mixed $value */
            $value = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

            return ['body' => DecodedBody::present($value), 'errors' => []];
        } catch (JsonException $e) {
            return [
                'body' => DecodedBody::present($content),
                'errors' => [sprintf('%s body could not be parsed as JSON: %s', $subject, $e->getMessage())],
            ];
        }
    }
}
