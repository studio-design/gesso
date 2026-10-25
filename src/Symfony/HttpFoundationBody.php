<?php

declare(strict_types=1);

namespace Studio\Gesso\Symfony;

use JsonException;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;
use Studio\Gesso\Validation\Support\FormBodyDecoder;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;
use function strtolower;

/**
 * Shared body extraction for Laravel and Symfony. JSON failures deliberately
 * escape to the adapter so its assertion and baseline policy remain in charge.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class HttpFoundationBody
{
    /** @throws JsonException */
    public static function request(Request $request, string $contentType): DecodedBody
    {
        $content = $request->getContent();
        if (ContentTypeMatcher::isJsonOrUnspecified($contentType)) {
            return DecodedBody::decodeJson($content);
        }

        $fields = HttpFoundationFormBody::fields($request);
        if (FormBodyDecoder::isFormMediaType(ContentTypeMatcher::normalizeMediaType($contentType))) {
            if ($fields !== null) {
                return DecodedBody::present($fields);
            }

            return $content === '' ? DecodedBody::absent() : DecodedBody::present($content);
        }

        // HttpFoundation may only retain parsed parameters / files, not raw
        // bytes. Opaque bodies need a presence bit, not a guessed JSON value.
        return $content !== '' || $fields !== null
            ? DecodedBody::present(null)
            : DecodedBody::absent();
    }

    /**
     * Decode JSON and pass a raw urlencoded body through for the response
     * validator to parse (issue #488); content negotiation handles every
     * other non-JSON type.
     *
     * @throws JsonException
     */
    public static function json(string $content, string $contentType): DecodedBody
    {
        if (ContentTypeMatcher::normalizeMediaType($contentType) === FormBodyDecoder::URLENCODED) {
            // An empty urlencoded body is a form with no fields, not a
            // missing one; the schema decides whether that is acceptable.
            return DecodedBody::present($content);
        }

        if (!ContentTypeMatcher::isJsonOrUnspecified($contentType)) {
            return DecodedBody::absent();
        }

        return DecodedBody::decodeJson($content);
    }

    public static function parseFailure(JsonException $exception, string $contentType, string $subject): string
    {
        return sprintf(
            '%s body could not be parsed as JSON: %s%s',
            $subject,
            $exception->getMessage(),
            $contentType === '' ? sprintf(' (no Content-Type header was present on the %s)', strtolower($subject)) : '',
        );
    }
}
