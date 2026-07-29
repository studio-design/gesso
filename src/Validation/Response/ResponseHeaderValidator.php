<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Response;

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\HeaderNormalizer;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\TypeCoercer;

use function array_key_first;
use function count;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_scalar;
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
 * Header values arriving as `array<string>` (Laravel/Symfony's HeaderBag
 * models repeated occurrences this way) are unwrapped to a single value
 * when the array holds exactly one element. Multi-value arrays against
 * scalar schemas produce a hard error — frameworks disagree on which of
 * the repeated values "wins" (Laravel: first, Symfony: last), so silently
 * picking one would mask a drift the contract test exists to expose.
 * Empty arrays are treated as missing. `style: simple` with
 * `type: array | object` is out of scope; such schemas will fail with a
 * type mismatch because header values are coerced as scalars.
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

    public function __construct(
        private readonly SchemaValidatorRunner $runner,
    ) {}

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

            $rawValue = $normalizedHeaders[$lowerName] ?? null;

            // `null` and `[]` (empty repeated-header array) both collapse to
            // "missing". A repeated header that arrived zero times is
            // semantically absent.
            if ($rawValue === null || $rawValue === []) {
                if ($required) {
                    $errors[] = new NamedError((string) $name, sprintf('[response-header.%s] required header is missing.', $name), keyword: 'required');
                }

                continue;
            }

            if (is_array($rawValue)) {
                if (count($rawValue) > 1) {
                    $errors[] = new NamedError((string) $name, sprintf(
                        '[response-header.%s] multiple values received (count=%d) but schema expects a single value; refusing to pick one silently.',
                        $name,
                        count($rawValue),
                    ));

                    continue;
                }

                $rawValue = $rawValue[array_key_first($rawValue)];
            }

            // Same post-unwrap missing guard as the pre-unwrap branch:
            // `['X-Foo' => [null]]` is shaped identically to an absent
            // header. Letting it through would either silently pass against
            // a `nullable` schema or surface as a `/` type mismatch from opis.
            if ($rawValue === null) {
                if ($required) {
                    $errors[] = new NamedError((string) $name, sprintf('[response-header.%s] required header is missing.', $name), keyword: 'required');
                }

                continue;
            }

            if (!is_scalar($rawValue)) {
                $errors[] = new NamedError((string) $name, sprintf(
                    '[response-header.%s] value must be a scalar (string|int|bool|float); got %s.',
                    $name,
                    get_debug_type($rawValue),
                ));

                continue;
            }

            $coerced = TypeCoercer::coercePrimitive($rawValue, $schema);
            $jsonSchema = OpenApiSchemaConverter::convert($schema, $version, SchemaContext::Response, null, $jsonSchemaDialect);

            $schemaObject = ObjectConverter::convert($jsonSchema);
            $dataObject = ObjectConverter::convert($coerced);

            foreach ($this->runner->validateStructured($schemaObject, $dataObject) as $violation) {
                $suffix = $violation->displayPath() === '/' ? '' : $violation->displayPath();
                $errors[] = new NamedError((string) $name, sprintf('[response-header.%s%s] %s', $name, $suffix, $violation->message), $violation->instancePath, $violation->keyword);
            }
        }

        return $errors;
    }
}
