# Spec-driven vs consumer-driven contract testing in PHP: when to use Gesso vs Pact

PHP contract testing splits into two shapes: **spec-driven**, where an OpenAPI document is the source of truth and every request and response is validated against it, and **consumer-driven**, where each consumer records its expectations and the provider replays them. Pact PHP is the reference tool for the second shape. Gesso is a reference tool for the first. This guide is a decision framework for picking between them.

## What is spec-driven contract testing?

**Spec-driven contract testing verifies that the live implementation still matches an OpenAPI document you publish.** The spec is the source of truth. The tests confirm that every request the provider accepts and every response it returns conforms to that spec — nothing more, nothing less.

In PHP, this is what Gesso (`studio-design/gesso`) does. You point it at an OpenAPI 3.0/3.1/3.2 file, add one trait to your PHPUnit or Pest test case, and each assertion validates the traffic against the spec while accumulating endpoint coverage.

## What is consumer-driven contract testing?

**Consumer-driven contract testing verifies that a provider still satisfies the expectations recorded by each of its consumers.** The consumers own the contracts. Each consumer runs a mock server, records the requests it sends and the responses it expects, and publishes the resulting pact file. The provider then verifies those pacts against a live implementation.

In PHP, this is what Pact PHP (`pact-foundation/pact-php`) does. Since v10 it binds to the shared Pact core used by every Pact language implementation, so matching rules stay consistent across stacks (this requires ext-ffi with `ffi.enable=true`). It gives you a mock server for consumer tests, and a verifier that replays pacts against your provider.

## Comparison at a glance

| | Spec-driven (Gesso) | Consumer-driven (Pact PHP) |
|---|---|---|
| **Source of truth** | OpenAPI document | Consumer-recorded pacts |
| **Who writes the contract** | The provider team (API author) | Each consumer |
| **What is verified** | Live request/response conform to the spec | Live provider still satisfies each consumer's expectations |
| **Runs where** | Provider's PHPUnit / Pest suite | Consumer's test suite (records the pact) + the provider's verification run — also PHPUnit-based |
| **Shared infrastructure** | None — the spec file lives in the repository | None required — pact files can move as CI artifacts; a Pact Broker / PactFlow is what makes cross-team sharing and `can-i-deploy` practical |
| **Framework surface** | Framework-independent core + Laravel / Symfony / Pest / PSR-7 adapters | Framework-agnostic: provider verification targets any HTTP-reachable app, so no per-framework adapter is needed (v10 runs on the shared Pact core via ext-ffi) |
| **What the tooling reports** | Which spec operations, statuses, and content types are exercised | Verification status for each interaction, per consumer version and environment (via the broker) |
| **Consumers you don't control** | Covered as far as the spec describes them — anything your suite exercises is checked against the same document they code against | Out of scope by design — a contract exists only for consumers that publish one |
| **Expectations the spec doesn't encode** | Out of scope by design — Gesso enforces exactly what the document states | This is the point — each consumer pins what it actually depends on |
| **Detects drift from published docs** | Yes | Not its concern — pacts describe consumer expectations, not the published document (PactFlow's bi-directional contract testing adds this comparison separately) |

## When spec-driven (Gesso) fits better

Reach for spec-driven testing when the spec is the contract in practice.

- **You publish OpenAPI as your API's public documentation.** Every consumer — internal, external, unknown — is expected to code against the spec. Drift between docs and implementation is the failure mode you care about.
- **You own the API and don't control every consumer.** Public APIs, partner APIs, and internal APIs consumed by teams you don't sit with all share this shape.
- **You already generate SDKs, mocks, or docs from OpenAPI.** The spec is load-bearing; contract testing should reinforce it, not compete with it.
- **You want per-endpoint coverage as a first-class output.** Gesso reports the share of operations and `(method, path, status, content-type)` rows exercised by your suite, so a merged PR that quietly stops testing an endpoint shows up in the coverage delta.
- **You want contract enforcement to be a plain assertion in the suite you already run.** No artifact to publish, nothing to coordinate between repositories — the check runs on the same PR that changes the code.

Minimal shape (Symfony; the Laravel, Pest, and plain-PSR-7 variants read almost identically):

```php
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[OpenApiSpec('petstore')]
class PetsApiTest extends WebTestCase
{
    use OpenApiAssertions;

    public function test_get_pets_matches_spec(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pets');

        $this->assertResponseIsSuccessful();
        $this->assertClientMatchesOpenApiSchema($client);
    }
}
```

Every passing assertion also records coverage against the pinned spec.

## When consumer-driven (Pact PHP) fits better

Reach for consumer-driven testing when consumers have expectations the spec can't or shouldn't capture.

- **You own several services that call each other.** You control both sides. Consumers can publish pacts, providers can verify them, and the broker becomes the integration ledger for the whole platform.
- **Consumer expectations are narrower than the spec.** A consumer may only care about three fields of a response even though the spec defines twenty. Pact makes that narrow expectation explicit and lets the provider evolve the rest safely.
- **You want a cross-service break to block the deploy of the service that caused it.** Provider verification runs against a candidate build, and a failed verification makes `can-i-deploy` refuse that service's release — the failure lands on the provider's pipeline instead of surfacing in each consumer later.
- **You want to answer "can I deploy this?" for a specific environment.** The Pact Broker's `can-i-deploy` check is the workflow output; there is no direct equivalent in spec-driven testing.

Minimal shape on the consumer side (Pact PHP v10; requires `ffi.enable=true`):

```php
use PhpPact\Consumer\InteractionBuilder;
use PhpPact\Consumer\Model\ConsumerRequest;
use PhpPact\Consumer\Model\ProviderResponse;
use PhpPact\Standalone\MockService\MockServerConfig;

$config = (new MockServerConfig())
    ->setConsumer('PetsWebApp')
    ->setProvider('PetsApi')
    ->setHost('127.0.0.1')
    ->setPort(7200);

$request = (new ConsumerRequest())->setMethod('GET')->setPath('/pets');
$response = (new ProviderResponse())
    ->setStatus(200)
    ->setBody([['id' => 1, 'name' => 'Fido']]);

$builder = new InteractionBuilder($config);
$builder
    ->uponReceiving('a request for pets')
    ->with($request)
    ->willRespondWith($response); // must be last — registers the interaction

$result = (new PetsClient($config->getBaseUri()))->list();
$this->assertSame('Fido', $result[0]['name']);
$this->assertTrue($builder->verify());
```

The provider later verifies this pact against a real handler wired into its test harness.

## A short decision framework

Pick spec-driven (Gesso) when at least two of these are true:

1. OpenAPI is already the artifact you publish for consumers to code against.
2. You don't fully control every consumer of the API.
3. You want contract enforcement to live inside PHPUnit or Pest with no extra infrastructure.
4. Drift between docs and implementation is the specific failure you keep hitting.

Pick consumer-driven (Pact PHP) when at least two of these are true:

1. You own the consumers and the provider, and they ship independently.
2. Consumer expectations are narrower or more specific than the full spec.
3. You want `can-i-deploy` gating on a broker as part of your release flow.
4. Multiple consumer teams need to encode expectations the provider team doesn't get to write for them.

## When *not* to use Gesso

Spec-driven testing isn't a universal answer. Gesso is not the right pick when:

- **You don't publish an OpenAPI document.** Gesso needs a spec on disk, and writing one only to enable testing is the wrong order. Consumer-driven contracts don't need a spec at all — if you want contract testing now, Pact is the shorter path.
- **The contracts you care about are cross-service, consumer-owned expectations.** Two internal services with a small, stable integration surface get more from a pact than from a full OpenAPI validation.
- **You need `can-i-deploy` gating tied to a broker.** Gesso doesn't provide it; the Pact ecosystem does — the check itself ships with the Pact Broker / PactFlow, not with pact-php.
- **You test against non-HTTP transports** — asynchronous messages (Pact's message pact), or gRPC via the Pact Protobuf plugin. Gesso validates HTTP requests and responses against OpenAPI; it has no message-pact equivalent.
- **Your team is drowning in flaky end-to-end tests between two services you both own.** In that shape, consumer-driven contracts replace those end-to-end tests more directly, because the pact is the thing the two teams argue about.

## Can you use both?

Yes, and larger PHP shops often do. A common pattern:

- Gesso enforces that the provider's implementation still matches the OpenAPI document on every PR. Public / partner consumers rely on the spec being trustworthy.
- Pact covers the handful of internal services with narrow, cross-team expectations that don't belong in the public spec — think an internal billing pipeline consuming three fields from an account service that publishes twenty.

The two tools don't fight; they answer different questions.

## FAQ

**Is Gesso a Pact alternative?**
Only for teams whose question is "does my implementation still match my OpenAPI spec?" If the question is "does my provider still satisfy every consumer's recorded expectations?", Pact PHP is the right tool. The two solve overlapping but distinct problems.

**Do I need a broker to use Gesso?**
No. The OpenAPI file lives in the repository and Gesso runs inside PHPUnit or Pest, so there is no additional service in the loop.

**Which OpenAPI versions does Gesso support?**
3.0.x, 3.1.x, and 3.2.x. `oneOf`/`anyOf`/`allOf`/`not` are validated in every supported dialect, and `discriminator` mappings are enforced rather than treated as a hint. 3.1 and 3.2 run against native JSON Schema 2020-12 semantics where the underlying validator supports them; the handful of 2019-09/2020-12 keywords the validator doesn't implement emit a one-shot warning rather than being silently ignored. 3.0 runs through a Draft 07 compatibility pipeline.

**Can Gesso test consumer code, the way Pact does on the consumer side?**
Not in the pact sense. Gesso validates HTTP traffic against a spec on either side of the wire, so you can point it at a consumer's outgoing requests to check they match the spec. It won't, however, record a pact that a provider then verifies — that workflow belongs to Pact.

**Does spec-driven testing catch the same regressions as consumer-driven testing?**
It catches every drift between the implementation and the published spec. It does *not* catch consumer expectations that the spec never encoded — for example, a consumer that depends on an optional field always being present in practice, or on one particular enum value it branches on. The spec permits both variations; the pact records that this consumer needs one of them. If those expectations matter, Pact is complementary, not redundant.

**Where does Dredd fit in?**
Dredd is closer to spec-driven than consumer-driven — it runs the examples in an API description document as tests. In PHP projects that already publish OpenAPI, Gesso runs that same style of check from inside the existing suite, with coverage as an output. Dredd still fits when the checks need to run outside a PHP test runner.

## Next step

If your API is spec-first and you want to try spec-driven contract testing today, start from the [Core quickstart](quickstarts/core.md), or jump straight to the framework you use: [Laravel](quickstarts/laravel.md), [Symfony](quickstarts/symfony.md), or [Pest](quickstarts/pest.md). See also [Contract testing a Symfony API with OpenAPI](contract-testing-symfony.md) for a full end-to-end walkthrough of the spec-driven path.

If your integration surface is consumer-owned and you'd get more from pacts, the [Pact PHP documentation](https://docs.pact.io/implementation_guides/php/readme) is the right starting point — the choice is a fit question, not a loyalty one.

Source and issue tracker: [github.com/studio-design/gesso](https://github.com/studio-design/gesso). MIT licensed.
