<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Validation\Support\HeaderNormalizer;
use Studio\Gesso\Validation\Support\HeaderValueValidator;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

use function get_debug_type;
use function in_array;
use function is_array;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Validate the response-side `headers` block against the OpenAPI spec.
 *
 * HTTP header names are case-insensitive (RFC 7230) so both spec keys
 * and caller-supplied header keys are lower-cased before matching.
 * Error messages preserve the spec's original casing so authors can
 * grep their OpenAPI document directly. The `[response-header.<Name>]`
 * prefix distinguishes these errors from request-side `[header.<Name>]`
 * and body `[/<json-pointer>]` errors when they share an
 * `OpenApiValidationResult`.
 *
 * Per OAS 3.0/3.1, a `Content-Type` entry under `responses.<code>.headers`
 * SHALL be ignored — the response's actual content type is governed by
 * content negotiation, not arbitrary header definitions. The validator
 * skips it explicitly so a misplaced spec definition cannot fail tests.
 *
 * Value handling (HeaderBag unwrap, multi-value refusal, scalar guard,
 * coercion) is shared with the request side via {@see HeaderValueValidator}.
 *
 * @phpstan-type HeaderObject array{required?: bool, schema?: array<string, mixed>}
 * @phpstan-type HeadersSpec array<string, HeaderObject|mixed>
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class ResponseHeaderValidator
{
    /**
     * Per OAS 3.0/3.1: response headers map "Content-Type" SHALL be ignored.
     * Lower-cased so the lookup is case-insensitive.
     */
    private const IGNORED_HEADER_NAMES = ['content-type'];
    private readonly HeaderValueValidator $values;

    public function __construct(SchemaValidatorRunner $runner)
    {
        $this->values = new HeaderValueValidator($runner, 'response-header', SchemaContext::Response);
    }

    /**
     * @param HeadersSpec $headersSpec the `responses.<code>.headers` map
     * @param array<array-key, mixed> $actualHeaders the response's actual headers, as returned by HeaderBag::all()
     *
     * @return list<NamedError>
     */
    public function validate(
        array $headersSpec,
        array $actualHeaders,
        OpenApiVersion $version,
        ?string $jsonSchemaDialect = null,
    ): array {
        if ($headersSpec === []) {
            return [];
        }

        $errors = [];
        $normalizedHeaders = HeaderNormalizer::normalize($actualHeaders);

        foreach ($headersSpec as $name => $headerObject) {
            // Malformed entry (e.g. `Location: "string"` from a YAML
            // authoring slip) must surface — silent skip would hide
            // every header from validation.
            if (!is_array($headerObject)) {
                $errors[] = new NamedError((string) $name, sprintf(
                    '[response-header.%s] header definition must be an object; got %s.',
                    $name,
                    get_debug_type($headerObject),
                ));

                continue;
            }

            // Trim defensively before matching the IGNORED list so a spec
            // key like `"Content-Type "` (trailing whitespace from a
            // YAML/JSON authoring slip) still gets the OAS-mandated skip
            // instead of unexpectedly running schema validation.
            $lowerName = strtolower(trim($name));

            if (in_array($lowerName, self::IGNORED_HEADER_NAMES, true)) {
                continue;
            }

            $required = ($headerObject['required'] ?? false) === true;

            // Required headers without a schema would silently pass every
            // response, so surface as a hard spec error. Optional entries
            // without a schema have nothing to validate against — there is
            // no contract to check, so the header is effectively
            // unconstrained even if a value is present.
            if (!isset($headerObject['schema']) || !is_array($headerObject['schema'])) {
                if ($required) {
                    $errors[] = new NamedError((string) $name, sprintf(
                        '[response-header.%s] required header has no schema — cannot validate.',
                        $name,
                    ));
                }

                continue;
            }

            /** @var array<string, mixed> $schema */
            $schema = $headerObject['schema'];

            $errors = [...$errors, ...$this->values->validate((string) $name, $normalizedHeaders[$lowerName] ?? null, $required, $schema, $version, $jsonSchemaDialect)];
        }

        return $errors;
    }
}
