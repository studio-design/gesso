<?php

declare(strict_types=1);

namespace Studio\Gesso\Psr7;

use const JSON_THROW_ON_ERROR;
use const UPLOAD_ERR_OK;

use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\UploadedPart;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\FormBodyDecoder;
use Studio\Gesso\ValidationIssue;

use function array_is_list;
use function array_key_exists;
use function array_merge;
use function array_pad;
use function array_values;
use function explode;
use function is_array;
use function json_decode;
use function ltrim;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function trim;
use function urldecode;

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
            strictRequiredTracker: StrictRequiredTracker::current(),
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
        $decoded = $this->decodeRequestBody($request, $contentType);

        if ($request instanceof ServerRequestInterface) {
            /** @var array<string, mixed> $queryParams */
            $queryParams = $request->getQueryParams();
            /** @var array<string, mixed> $cookies */
            $cookies = $request->getCookieParams();
        } else {
            $queryParams = self::parseQuery($request);
            $cookies = self::parseCookieHeader($request);
        }

        $rawQueryString = $request->getUri()->getQuery();

        // validateWithoutRecording(): withAdapterErrors() below can promote
        // the outcome (a decode failure turns Skipped into Failure), so this
        // adapter owns the exchange's single coverage record and takes it
        // from the FINAL result (issue #535 review).
        $result = $this->requestValidator->validateWithoutRecording(
            $this->specName,
            $method,
            $path,
            $queryParams,
            $request->getHeaders(),
            $decoded['body'],
            $contentType,
            $cookies,
            $responseStatusCode,
            $rawQueryString === '' ? null : $rawQueryString,
        );

        $result = self::withAdapterErrors(
            $result,
            $decoded['errors'],
            'request.body',
            $method,
            statusCode: null,
            contentType: self::requestBodyIssueContentType($result),
        );

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
        // validateWithoutRecording(): withAdapterErrors() below can promote
        // the outcome (a decode failure turns Skipped into Failure), so this
        // adapter owns the exchange's single coverage record and takes it
        // from the FINAL result (issue #535 review).
        $result = $this->responseValidator->validateWithoutRecording(
            $this->specName,
            $method,
            $requestPath,
            $response->getStatusCode(),
            $decoded['body'],
            $contentType,
            $response->getHeaders(),
        );

        $result = self::withAdapterErrors(
            $result,
            $decoded['errors'],
            'response.body',
            $method,
            statusCode: $result->matchedStatusCode(),
            contentType: $result->matchedContentType(),
        );

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

    /**
     * Prepend adapter-level body errors (unreadable/non-seekable stream, JSON
     * parse failure) to the validator result. Adapter errors are tagged with
     * the given body category (`request.body` / `response.body`) and the
     * synthetic `parse` keyword — the violation baseline uses it to keep a
     * decode failure distinct from a genuinely empty body and to identify
     * same-category sibling issues as placeholder artifacts. The validator's
     * structured issues are kept as-is, in the same order as `errors()`, so
     * `issues()` never degrades to `unknown` here.
     *
     * `$statusCode` / `$contentType` are the issue context for the adapter
     * entries and are side-specific: the caller passes the result's matched
     * values on the response side, and `statusCode: null` on the request side
     * — request issues never carry a status, and the result-level
     * matchedStatusCode of a downgraded (documented-4xx) request result is a
     * response spec key that must not leak into request issue context.
     *
     * @param list<string> $adapterErrors
     */
    private static function withAdapterErrors(
        OpenApiValidationResult $result,
        array $adapterErrors,
        string $category,
        string $method,
        ?string $statusCode,
        ?string $contentType,
    ): OpenApiValidationResult {
        if ($adapterErrors === []) {
            return $result;
        }

        $adapterIssues = [];
        foreach ($adapterErrors as $message) {
            $adapterIssues[] = new ValidationIssue(
                $category,
                $message,
                keyword: ViolationFingerprint::KEYWORD_PARSE,
                method: $method,
                path: $result->matchedPath(),
                statusCode: $statusCode,
                contentType: $contentType,
            );
        }

        return OpenApiValidationResult::failure(
            array_merge($adapterErrors, $result->errors()),
            $result->matchedPath(),
            $result->matchedStatusCode(),
            $result->matchedContentType(),
            array_merge($adapterIssues, $result->issues()),
        );
    }

    /**
     * Media-type key for a request-side adapter body issue. A failed request
     * result carries the resolved request media-type on its tagged
     * `request.body` issues — reuse theirs so the adapter entry and its
     * sibling body issues report the same key. A downgraded (documented-4xx)
     * request result is Skipped and has no issues; there the key resolved
     * before the downgrade is carried at result level instead. Null when
     * neither channel has one (no `requestBody` in the spec, success).
     */
    private static function requestBodyIssueContentType(OpenApiValidationResult $result): ?string
    {
        foreach ($result->issues() as $issue) {
            if ($issue->category === 'request.body') {
                return $issue->contentType;
            }
        }

        return $result->matchedContentType();
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
        /** @var array<string, mixed> $query */
        $query = [];
        $queryString = $request->getUri()->getQuery();
        if ($queryString === '') {
            return $query;
        }

        // OpenAPI `style: form, explode: true` serializes arrays by repeating
        // the parameter name (`tags=a&tags=b`). PHP's parse_str() overwrites
        // earlier unbracketed values, so parse the wire pairs directly and
        // promote a repeated key to an ordered list instead.
        foreach (explode('&', $queryString) as $pair) {
            [$encodedName, $encodedValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($encodedName);
            if ($name === '') {
                continue;
            }

            $value = urldecode($encodedValue);
            if (!array_key_exists($name, $query)) {
                $query[$name] = $value;

                continue;
            }

            if (!is_array($query[$name])) {
                $query[$name] = [$query[$name]];
            }
            $query[$name][] = $value;
        }

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
     * Map PSR-7 uploaded files onto the validator's {@see UploadedPart}
     * envelope, preserving the nesting of `files[0][avatar]`-style names.
     *
     * PSR-7 defines `UPLOAD_ERR_OK` as the only successful upload, so a part
     * carrying any other error (no file sent, size limit hit, partial write)
     * is dropped instead of mapped — the server never received a file, and a
     * failed upload must not satisfy a `required` part.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    private static function uploadedParts(array $files): array
    {
        // A dropped element must not leave a hole: `files[0]` failing would
        // otherwise turn the remaining list into the map `{1: ...}`, which
        // reaches the schema as an object and fails `type: array` even though
        // a valid file is still there.
        $wasList = array_is_list($files);

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFileInterface) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    unset($files[$key]);

                    continue;
                }

                $files[$key] = new UploadedPart($file->getClientMediaType(), $file->getClientFilename());
            } elseif (is_array($file)) {
                $files[$key] = self::uploadedParts($file);
            }
        }

        return $wasList ? array_values($files) : $files;
    }

    /**
     * @return array{content: null, errors: non-empty-list<string>}
     */
    private static function readFailure(string $subject, string $reason): array
    {
        return ['content' => null, 'errors' => self::bodyReadFailure($subject, $reason)['errors']];
    }

    /**
     * Decode a request body, routing form media types to their parsed field
     * map so the validator can apply the media type's schema (issue #405).
     *
     * A `ServerRequestInterface` already carries the parsed fields and uploaded
     * files. For a client `RequestInterface` only the raw bytes exist: an
     * urlencoded payload is forwarded for the validator to parse, while a raw
     * multipart payload is left undecoded (the validator then reports a skip
     * rather than a silent pass).
     *
     * @return array{body: DecodedBody, errors: list<string>}
     */
    private function decodeRequestBody(RequestInterface $request, ?string $contentType): array
    {
        if ($contentType === null || !FormBodyDecoder::isFormMediaType(
            ContentTypeMatcher::normalizeMediaType($contentType),
        )) {
            return $this->decodeBody($request->getBody(), $contentType, 'Request');
        }

        if ($request instanceof ServerRequestInterface) {
            /** @var mixed $parsed */
            $parsed = $request->getParsedBody();
            $uploaded = $request->getUploadedFiles();

            // Emptiness is judged before failed uploads are dropped: a request
            // whose only file failed to upload still went through the parsed
            // path, and must reach the schema as an empty field map (a loud
            // "required part missing") rather than fall through to the raw
            // body and be skipped.
            if ((is_array($parsed) && $parsed !== []) || $uploaded !== []) {
                return [
                    'body' => DecodedBody::present(array_merge(
                        is_array($parsed) ? $parsed : [],
                        self::uploadedParts($uploaded),
                    )),
                    'errors' => [],
                ];
            }
        }

        $read = $this->readBody($request->getBody(), 'Request');

        if ($read['errors'] !== []) {
            return ['body' => DecodedBody::absent(), 'errors' => $read['errors']];
        }

        if ($read['content'] === null || $read['content'] === '') {
            return ['body' => DecodedBody::absent(), 'errors' => []];
        }

        return ['body' => DecodedBody::present($read['content']), 'errors' => []];
    }

    /**
     * Read a body stream without disturbing the caller's cursor. `content` is
     * null when the stream is empty or could not be read; `errors` is
     * non-empty only in the latter case.
     *
     * @return array{content: null|string, errors: list<string>}
     */
    private function readBody(StreamInterface $stream, string $subject): array
    {
        if ($stream->getSize() === 0) {
            return ['content' => null, 'errors' => []];
        }

        if (!$stream->isReadable()) {
            return self::readFailure($subject, 'body stream is not readable');
        }

        if (!$stream->isSeekable()) {
            return self::readFailure(
                $subject,
                'body stream is not seekable; validation was refused to avoid consuming caller state',
            );
        }

        try {
            $position = $stream->tell();
            $stream->rewind();
            $content = $stream->getContents();
        } catch (RuntimeException $e) {
            return self::readFailure($subject, 'body stream could not be read: ' . $e->getMessage());
        } finally {
            if (isset($position)) {
                try {
                    $stream->seek($position);
                } catch (RuntimeException $e) {
                    return self::readFailure($subject, 'body stream cursor could not be restored: ' . $e->getMessage());
                }
            }
        }

        return ['content' => $content, 'errors' => []];
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

        $read = $this->readBody($stream, $subject);

        if ($read['errors'] !== []) {
            return ['body' => DecodedBody::absent(), 'errors' => $read['errors']];
        }

        $content = $read['content'];

        if ($content === null || $content === '') {
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
