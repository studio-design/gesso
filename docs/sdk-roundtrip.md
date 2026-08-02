# SDK response round-trip testing

Server response validation proves that the provider emits spec-valid data. It
does not prove that a generated client SDK can deserialize every shape allowed
by the same response schema. `OpenApiResponseExplorer` closes that boundary by
generating valid response payloads, handing them to your SDK, and verifying the
SDK's re-encoded output.

## Plain PHPUnit

Configure specs in the normal test bootstrap, then select one response by its
method, path, wire status, and optional content type:

```php
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\Spec\OpenApiSpecLoader;

OpenApiSpecLoader::configure(__DIR__ . '/specs');

OpenApiResponseExplorer::explore(
    'front',
    'POST',
    '/oauth/introspect',
    200,
    contentType: 'application/json',
    seed: 1,
)->each(function (GeneratedResponseCase $case): void {
    // The generated SDK must accept every spec-valid payload.
    $model = ObjectSerializer::deserialize(
        $case->bodyAsObject(),
        IntrospectResponse::class,
    );

    // The SDK must also preserve every generated value when it re-encodes.
    $case->assertRoundTrip(
        ObjectSerializer::sanitizeForSerialization($model),
    );
});
```

Omit `contentType` to select the first JSON-compatible media type exactly as
response validation does. Status resolution also shares validator behavior:
an exact response key wins, followed by an `X00` range key and `default`.

## Laravel

The `ExploresOpenApiEndpoint` trait uses the same spec-name precedence as the
validation traits: method/class `#[OpenApiSpec]`, `openApiSpec()`, then
`default_spec`.

```php
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Laravel\ExploresOpenApiEndpoint;

#[OpenApiSpec('front')]
final class IntrospectSdkTest extends TestCase
{
    use ExploresOpenApiEndpoint;

    public function test_sdk_accepts_every_introspection_shape(): void
    {
        $this->exploreResponseSchema(
            'POST',
            '/oauth/introspect',
            200,
            seed: 1,
        )->each(function (GeneratedResponseCase $case): void {
            $model = ObjectSerializer::deserialize(
                $case->bodyAsObject(),
                IntrospectResponse::class,
            );
            $case->assertRoundTrip(ObjectSerializer::sanitizeForSerialization($model));
        });
    }
}
```

Schema-to-model mapping deliberately stays in your test. Gesso does not guess
class names or conventions from openapi-generator, Kiota, or another SDK tool.

## Branch-complete cases

For a fixed converted schema and seed, the explorer produces at least one case
for every branch of every reachable supported choice point:

- `oneOf` and `anyOf` branches;
- `if`/`then`/`else` and conditional `allOf` branches;
- nullable branches;
- presence and omission of optional object properties, including choice points
  nested beneath those properties.

The number of cases is therefore derived from the schema. `extraCases` defaults
to `0` and appends deterministic rotation-only cases when you want more values;
it does not replace any branch-pinned case. The existing supported-subset and
enumeration bounds remain loud. Every generated value is self-validated before
your callback runs. Omitting `seed` uses the replayable default seed `0`; the
effective seed is stored on every case and included in `replaySnippet()`.

## Case values and fidelity

`GeneratedResponseCase` is readonly and exposes:

| Member | Meaning |
|---|---|
| `body` | Raw generated PHP value |
| `bodyAsObject()` | JSON round-trip with objects decoded as `stdClass`; arrays and scalars retain their JSON shape |
| `bodyAsArray()` | Associative decoded form for object/array bodies; scalar bodies throw |
| `status`, `contentType` | Selected wire status and declared media-type key |
| `seed`, `caseIndex` | Deterministic replay identity |
| `pinnedBranch` | Target JSON Pointer plus zero-based branch, for example `/properties/aud/oneOf@0`; `null` for an extra case |
| `replaySnippet()` | Minimal `OpenApiResponseExplorer::explore(...)` reproduction |

`assertRoundTrip()` makes two assertions in order:

1. The re-encoded value must satisfy the same converted JSON Schema used to
   generate the case, including the selected OpenAPI dialect and discriminator
   enforcement.
2. Every generated object key/value must survive recursively. SDK-specific
   extra object keys are allowed, while generated JSON lists and scalar values
   compare exactly, including scalar types.

Assertion failures include the seed, case index, pinned branch descriptor, and
replay snippet. Exceptions thrown directly by the SDK decoder remain visible to
PHPUnit unchanged.

## Loud unsupported responses

Exploration never returns an empty collection. Resolution failures and response
shapes that cannot represent one buffered JSON document throw
`InvalidArgumentException` with a structured outcome name. These include:

- responses without a `content` block, including 204-style responses;
- responses declaring only non-JSON media types;
- selected media types without `schema`;
- selected non-JSON schemas; and
- OpenAPI 3.2 streaming responses using `itemSchema`.

Named component exploration, spec-wide SDK mapping, and SDK exercise coverage
are separate follow-up phases; this entry point intentionally targets one
resolved operation response at a time.
