<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const PATHINFO_EXTENSION;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;

use function ctype_digit;
use function ltrim;
use function pathinfo;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function strcmp;
use function strcspn;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Resolves HTTP / HTTPS external `$ref` target documents via a
 * user-provided PSR-18 ClientInterface + PSR-17 RequestFactoryInterface.
 *
 * This is opt-in. The resolver only reaches this loader when the caller
 * explicitly passed `allowRemoteRefs: true` to
 * `OpenApiSpecLoader::configure()`. The library does not bundle an HTTP
 * client implementation; users must wire one of Guzzle / Symfony
 * HttpClient / Buzz / etc. themselves.
 *
 * Each resolution call passes its own `$documentCache`, so the same URL
 * is fetched once even if multiple sibling refs target different
 * fragments of it. The cache lives only for the duration of one
 * `OpenApiRefResolver::resolve()` call (no global / cross-call cache —
 * each test gets a fresh fetch).
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class HttpRefLoader
{
    public const DEFAULT_MAX_RESPONSE_BYTES = 10_485_760;
    private const READ_CHUNK_BYTES = 8192;
    private const MAX_EMPTY_READ_RETRIES = 3;

    private function __construct() {}

    /**
     * Fetch `$url`, decode the response body, and return the canonical URL
     * together with the decoded array.
     *
     * Format detection prefers the URL's filename extension
     * (`.json` / `.yaml` / `.yml`), falling back to the response's
     * `Content-Type` header. Refs to URLs that have neither cue throw
     * `UnsupportedExtension`.
     *
     * @param array<string, array<string, mixed>> $documentCache by-ref cache keyed by canonical URL
     * @param list<string> $allowedRemoteRefHosts exact normalized or user-provided host allowlist
     *
     * @throws InvalidOpenApiSpecException when the URL cannot be fetched, decoded, or has no detectable format
     */
    public static function loadDocument(
        string $url,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        array &$documentCache,
        array $allowedRemoteRefHosts,
        int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ): LoadedDocument {
        $canonicalUri = self::canonicalizeUri($url);
        $safeUrl = self::redactSensitiveUrlData($url);

        // PHP may include live function arguments when stringifying an
        // exception with zend.exception_ignore_args=Off. The raw URI is
        // already captured for the request and cache key, so replace the
        // parameter slot before any downstream operation can throw.
        $url = $safeUrl;
        $request = $requestFactory->createRequest('GET', $canonicalUri);
        self::assertHostAllowed($safeUrl, $request->getUri()->getHost(), $allowedRemoteRefHosts);

        if (isset($documentCache[$canonicalUri])) {
            return new LoadedDocument($canonicalUri, $documentCache[$canonicalUri]);
        }

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $safeTransportMessage = self::redactSensitiveUrlData($e->getMessage());

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf('HTTP $ref fetch failed: %s (%s)', $safeUrl, $safeTransportMessage),
                ref: $safeUrl,
                // Exception stringification includes every nested previous
                // message. A PSR-18 client may wrap a credential-bearing
                // exception below a harmless top-level message, so the raw
                // transport chain cannot be safely reattached here.
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            // Redirects must remain disabled because following one happens
            // below Gesso's host allowlist boundary and could reach an
            // unapproved host. The Location is diagnostic only, so callers
            // can configure the canonical URL directly.
            $location = self::redactSensitiveUrlData($response->getHeaderLine('Location'));

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf(
                    'HTTP $ref fetch returned redirect status %d: %s%s. '
                    . 'Keep redirects disabled and use the canonical URL directly.',
                    $status,
                    $safeUrl,
                    $location !== '' ? sprintf(' (Location: %s)', $location) : '',
                ),
                ref: $safeUrl,
            );
        }
        if ($status < 200 || $status >= 400) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf('HTTP $ref fetch returned status %d: %s', $status, $safeUrl),
                ref: $safeUrl,
            );
        }

        $contentLength = trim($response->getHeaderLine('Content-Length'));
        if ($contentLength !== '' && ctype_digit($contentLength) && self::decimalExceeds($contentLength, $maxResponseBytes)) {
            throw self::responseTooLarge($safeUrl, $maxResponseBytes);
        }

        // Read incrementally rather than casting or calling getContents().
        // PSR-7 bodies may be arbitrarily large or have no known size, so
        // the configured limit must be enforced against bytes actually read.
        $bodyStream = $response->getBody();

        try {
            $knownSize = $bodyStream->getSize();
            if ($knownSize !== null && $knownSize > $maxResponseBytes) {
                throw self::responseTooLarge($safeUrl, $maxResponseBytes);
            }
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
            }
            $body = self::readLimitedBody($bodyStream, $safeUrl, $maxResponseBytes);
        } catch (InvalidOpenApiSpecException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $safeBodyMessage = self::redactSensitiveUrlData($e->getMessage());

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf('HTTP $ref response body unreadable: %s (%s)', $safeUrl, $safeBodyMessage),
                ref: $safeUrl,
                // A response stream is supplied by the injected HTTP client
                // and may include request credentials in its exception or
                // nested causes. Do not reconnect the raw chain to a public
                // exception that is commonly rendered in CI logs.
            );
        }

        $format = self::detectFormat($canonicalUri, $response->getHeaderLine('Content-Type'));
        if ($format === null) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedExtension,
                sprintf(
                    'HTTP $ref %s has no detectable format (no .json/.yaml/.yml extension on the URL '
                    . 'and no recognised Content-Type header).',
                    $safeUrl,
                ),
                ref: $safeUrl,
            );
        }

        $decoded = $format === 'json'
            ? SpecDocumentDecoder::decodeJson($body, $safeUrl)
            : SpecDocumentDecoder::decodeYaml($body, $safeUrl);

        $documentCache[$canonicalUri] = $decoded;

        return new LoadedDocument($canonicalUri, $decoded);
    }

    /** @internal Shared with remote-ref configuration validation. */
    public static function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }

    /**
     * Remove URL userinfo and query values before diagnostics reach stderr or
     * CI logs. This also accepts surrounding transport-error prose because
     * PSR-18 exception messages commonly embed the request URL.
     *
     * @internal Shared with the ref resolver's exception-trace boundary.
     */
    public static function redactSensitiveUrlData(string $value): string
    {
        // Cover both absolute (`https://user:pass@host`) and network-path
        // (`//user:pass@host`) references. HTTP client exception messages may
        // contain either form rather than only the original URL string.
        $redacted = preg_replace('#((?:[a-z][a-z0-9+.-]*:)?//)[^/@\s]+@#i', '$1', $value);
        if ($redacted === null) {
            return $value;
        }

        // Query values frequently carry signed-URL tokens and API keys. Keep
        // parameter names for diagnostics without exposing their values.
        $withoutQueryValues = preg_replace('~([?&][^=\s&#]+)=([^&#\s]*)~', '$1=[redacted]', $redacted);

        return $withoutQueryValues ?? $redacted;
    }

    private static function readLimitedBody(StreamInterface $stream, string $safeUrl, int $maxResponseBytes): string
    {
        $body = '';
        $emptyReadRetries = 0;
        while (!$stream->eof()) {
            $remaining = $maxResponseBytes - strlen($body);
            $readLength = $remaining >= self::READ_CHUNK_BYTES
                ? self::READ_CHUNK_BYTES
                : $remaining + 1;
            $chunk = $stream->read($readLength);
            if ($chunk === '') {
                $emptyReadRetries++;
                if ($emptyReadRetries > self::MAX_EMPTY_READ_RETRIES) {
                    throw new RuntimeException(sprintf(
                        'response body stream made no progress after %d retries before EOF',
                        self::MAX_EMPTY_READ_RETRIES,
                    ));
                }

                continue;
            }

            $emptyReadRetries = 0;
            $body .= $chunk;
            if (strlen($body) > $maxResponseBytes) {
                throw self::responseTooLarge($safeUrl, $maxResponseBytes);
            }
        }

        return $body;
    }

    private static function responseTooLarge(string $safeUrl, int $maxResponseBytes): InvalidOpenApiSpecException
    {
        return new InvalidOpenApiSpecException(
            InvalidOpenApiSpecReason::RemoteRefFetchFailed,
            sprintf(
                'HTTP $ref response exceeds the configured limit of %d bytes: %s.',
                $maxResponseBytes,
                $safeUrl,
            ),
            ref: $safeUrl,
        );
    }

    private static function decimalExceeds(string $decimal, int $limit): bool
    {
        $decimal = ltrim($decimal, '0');
        $decimal = $decimal === '' ? '0' : $decimal;
        $limitString = (string) $limit;

        return strlen($decimal) > strlen($limitString) ||
            (strlen($decimal) === strlen($limitString) && strcmp($decimal, $limitString) > 0);
    }

    /** @param list<string> $allowedRemoteRefHosts */
    private static function assertHostAllowed(string $safeUrl, string $host, array $allowedRemoteRefHosts): void
    {
        $normalizedHost = self::normalizeHost($host);
        if ($normalizedHost !== '') {
            foreach ($allowedRemoteRefHosts as $allowedHost) {
                if ($normalizedHost === self::normalizeHost($allowedHost)) {
                    return;
                }
            }
        }

        throw new InvalidOpenApiSpecException(
            InvalidOpenApiSpecReason::RemoteRefHostDisallowed,
            sprintf(
                'HTTP(S) $ref host is not allowed: %s. Add the exact host to $allowedRemoteRefHosts.',
                $safeUrl,
            ),
            ref: $safeUrl,
        );
    }

    private static function canonicalizeUri(string $url): string
    {
        // Trim only — case-folding scheme/host or removing default ports
        // could collapse URIs the server treats as distinct.
        return trim($url);
    }

    /**
     * Pick the spec format from the URL's file extension first; fall back
     * to a coarse Content-Type sniff. Returns `'json'`, `'yaml'`, or null
     * when neither cue is conclusive.
     */
    private static function detectFormat(string $url, string $contentType): ?string
    {
        $path = self::stripQueryAndFragment($url);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            return 'json';
        }
        if ($extension === 'yaml' || $extension === 'yml') {
            return 'yaml';
        }

        $type = strtolower(trim($contentType));
        if ($type === '') {
            return null;
        }

        // RFC-violating servers occasionally emit duplicate Content-Type
        // headers; PSR-7's getHeaderLine() concatenates them with `, `,
        // and a server may also tack on a charset / boundary with `;`.
        // Split on either separator so the first usable type wins.
        $type = trim((string) (preg_split('/\s*[,;]\s*/', $type)[0] ?? ''));

        // application/json, application/openapi+json, application/problem+json …
        if ($type === 'application/json' || str_ends_with($type, '+json')) {
            return 'json';
        }

        if ($type === 'application/yaml' ||
            $type === 'application/x-yaml' ||
            $type === 'text/yaml' ||
            $type === 'text/x-yaml' ||
            str_ends_with($type, '+yaml')
        ) {
            return 'yaml';
        }

        return null;
    }

    private static function stripQueryAndFragment(string $url): string
    {
        $cut = strcspn($url, '?#');

        return rtrim(substr($url, 0, $cut));
    }
}
