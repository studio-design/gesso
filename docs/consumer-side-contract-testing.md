# Consumer-side contract testing: fake an external API from its OpenAPI document

Every application that calls an external API fakes it in tests: `Http::fake()`
in Laravel, `MockHttpClient` in Symfony. Those fakes are written by hand, and
they rot silently. When the provider renames a field or tightens a request
schema, the tests stay green against the old shape and the bug reaches
production.

Gesso can build those fakes from the provider's OpenAPI document instead, and
validate every outgoing request against it. The provider's spec is just
another named spec: put it next to your own under `spec_base_path` (for
example `openapi/stripe.json`) and refer to it by name (`'stripe'`). No extra
configuration is needed.

## Laravel: `Http::fake()`

```php
use Illuminate\Support\Facades\Http;
use Studio\Gesso\Laravel\OpenApiHttpFake;

public function test_charges_the_customer(): void
{
    Http::fake(OpenApiHttpFake::respond('stripe'));

    $this->app->make(PaymentService::class)->charge('cus_123', 2000);

    Http::assertSentCount(1);
}
```

For each outgoing request the responder:

1. validates the request (path, query, headers, security, body) against the
   `stripe` spec, and fails the test if the spec rejects it;
2. answers with the operation's lowest declared `2xx` response, built from the
   spec's examples or generated from its schema (see
   [what the body is](#what-the-fake-response-contains)).

Pick a different declared response per operation with a status map keyed by
`METHOD /template/path`, the same key shape the coverage report uses:

```php
Http::fake(OpenApiHttpFake::respond('stripe', ['POST /v1/charges' => 402]));
```

Laravel's own URL-keyed form scopes the fake to one host, so it composes with
other fakes and with `Http::sequence()`:

```php
Http::fake([
    'api.stripe.com/*' => OpenApiHttpFake::respond('stripe'),
    'hooks.slack.com/*' => Http::response('ok'),
]);
```

A rejected request fails the test from inside the `Http::post()` call, with the
same failure output as the other adapters — the validation errors and a
redacted `Reproduce: curl …` line, or the versioned JSON document when
[JSON failure output](validation-json-schema.md) is enabled:

```text
OpenAPI fake rejected outgoing request POST /v1/charges (spec: stripe):
<validation errors>
Reproduce: curl -X POST 'https://api.stripe.com/v1/charges' ...
```

## Symfony: `MockHttpClient`

```php
use Studio\Gesso\Symfony\OpenApiHttpFake;
use Symfony\Component\HttpClient\MockHttpClient;

$client = new MockHttpClient(OpenApiHttpFake::respond('stripe'));
$client = new MockHttpClient(OpenApiHttpFake::respond('stripe', ['POST /v1/charges' => 402]));
```

Behaviour and failure output match the Laravel responder. JSON, form, and
string bodies are read. The prepared request carries no cookie jar, so `apiKey`
security schemes declared `in: cookie` report as missing.

## Asserting hand-written fakes

Fakes you keep writing by hand can still be checked against the provider's
spec with the [PSR-7 assertions](psr7.md#phpunit-assertions). In Laravel, every
recorded pair converts to PSR-7:

```php
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Psr7\OpenApiAssertions;

#[OpenApiSpec('stripe')]
final class PaymentServiceTest extends TestCase
{
    use OpenApiAssertions;

    public function test_hand_written_fake_matches_the_spec(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'ch_1', 'amount' => 2000])]);

        $this->app->make(PaymentService::class)->charge('cus_123', 2000);

        foreach (Http::recorded() as [$request, $response]) {
            $this->assertPsr7ExchangeMatchesOpenApiSchema($request->toPsrRequest(), $response->toPsrResponse());
        }
    }
}
```

To check a single stub before any request is sent, name the operation:

```php
$this->assertPsr7ResponseForOperationMatchesOpenApiSchema(
    'GET',
    '/v1/charges/{id}',
    Http::response(['id' => 'ch_1'])->wait(),
);
```

In Symfony, wrap the mock client in `Symfony\Component\HttpClient\Psr18Client`
(with a PSR-17 factory such as `nyholm/psr7`) to get PSR-7 messages for the same
assertions.

## Framework-agnostic: `OpenApiFakeResponse`

Both responders are built on `Studio\Gesso\OpenApiFakeResponse`, which you can
use with any client or PSR-17 factory:

```php
use Studio\Gesso\OpenApiFakeResponse;

$fake = OpenApiFakeResponse::for('stripe', 'GET', '/v1/charges/{id}');
$fake = OpenApiFakeResponse::for('stripe', 'POST', '/v1/charges', 402);

$fake->status();      // 200
$fake->headers();     // ['X-Request-Id' => '...', 'Content-Type' => 'application/json']
$fake->content();     // the wire body
$fake->body();        // the decoded body
```

The path may be the spec template or a concrete path. See the
[API reference](api-reference.md#openapifakeresponse) for the error contract.

## What the fake response contains

- **Status**: the lowest declared `2xx`; a `2XX` range or `default` alone fakes
  as `200`. An operation with no success response needs an explicit status.
- **Body**: the media type's `example`, else the first `examples` entry, else a
  body generated from its JSON schema by the same engine as
  [SDK response round trips](sdk-roundtrip.md). Generation is deterministic.
  [Faker](fuzzing.md#seed-and-determinism) is optional; without it,
  format-constrained strings fall back to deterministic values with a one-shot
  `[Gesso] fakerphp/faker is not installed` warning.
- **Media type**: the JSON media type when one is declared, else the first one.
  `application/*` and `*/*` are sent as `application/json`; other ranges
  (`image/*`) need a concrete `$contentType` and an example.
- **Headers**: every declared response header, from its example or its schema,
  plus `Content-Type` when there is a body. `Content-Type` entries in the
  `headers` map are ignored, as OpenAPI requires.

## Limits

- `strip_prefixes` applies to every spec in the process
  ([ADR 0006](adr/0006-server-base-paths-and-request-path-matching.md)), so an
  external spec whose `servers[].url` carries a base path matches only when
  your requests use the paths as written in its `paths`.
- A generated empty object is encoded as `[]`; an `{}` in a spec example stays
  `{}`.
- The fake does not re-validate its own response. A spec example that
  contradicts its schema is served as written; run `gesso doctor` on the
  external spec, or assert the recorded pairs as shown above.
- [Violation baselines](baseline.md) do not apply to rejected outgoing
  requests.
- Validated requests record request coverage for the external spec. It appears
  in the coverage report only when that spec is listed in `specs`.
