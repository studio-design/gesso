<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const PATHINFO_EXTENSION;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;

use function pathinfo;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function strcspn;
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
     *
     * @throws InvalidOpenApiSpecException when the URL cannot be fetched, decoded, or has no detectable format
     */
    public static function loadDocument(
        string $url,
        ClientInterface $client,
        RequestFactoryInterface $requestFactory,
        array &$documentCache,
    ): LoadedDocument {
        $canonicalUri = self::canonicalizeUri($url);

        if (isset($documentCache[$canonicalUri])) {
            return new LoadedDocument($canonicalUri, $documentCache[$canonicalUri]);
        }

        $safeUrl = self::redactSensitiveUrlData($url);
        $request = $requestFactory->createRequest('GET', $canonicalUri);

        try {
            $response = $client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $safeTransportMessage = self::redactSensitiveUrlData($e->getMessage());

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf('HTTP $ref fetch failed: %s (%s)', $safeUrl, $safeTransportMessage),
                ref: $safeUrl,
                // Exception stringification includes the entire previous chain.
                // Do not reattach an exception whose message required redaction.
                previous: $safeTransportMessage === $e->getMessage() ? $e : null,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            // Surface the redirect explicitly: PSR-18 clients diverge on
            // whether they auto-follow (Guzzle defaults to follow, Symfony
            // to not). A bare 3xx is almost always "user's client has
            // redirect-following disabled", not a server bug. Including
            // the Location target makes the next step obvious.
            $location = self::redactSensitiveUrlData($response->getHeaderLine('Location'));

            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf(
                    'HTTP $ref fetch returned redirect status %d: %s%s. '
                    . 'Configure your PSR-18 client to follow redirects, '
                    . 'or pin the spec to the canonical URL.',
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

        // Read via getContents() rather than (string) cast so a stream
        // I/O failure surfaces as a real exception (PSR-7 permits
        // __toString() to silently return '' on read errors, which
        // would then mis-classify as MalformedJson/Yaml).
        $bodyStream = $response->getBody();

        try {
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
            }
            $body = $bodyStream->getContents();
        } catch (RuntimeException $e) {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::RemoteRefFetchFailed,
                sprintf('HTTP $ref response body unreadable: %s (%s)', $safeUrl, $e->getMessage()),
                ref: $safeUrl,
                previous: $e,
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

    /**
     * Remove URL userinfo and query values before diagnostics reach stderr or
     * CI logs. This also accepts surrounding transport-error prose because
     * PSR-18 exception messages commonly embed the request URL.
     */
    private static function redactSensitiveUrlData(string $value): string
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
