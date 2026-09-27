# Validate Scramble-generated contracts

Scramble generates OpenAPI from your Laravel application. Gesso checks actual
HTTP test responses against that document and reports which documented responses
your tests exercised. This gives teams already using Scramble a contract-testing
path without maintaining a second schema by hand.

The runnable [Scramble example](https://github.com/studio-design/gesso/tree/main/examples/scramble)
uses Laravel 12, Scramble 0.13.45, and PHPUnit 12. CI verifies passing responses,
a response type mismatch, and a missing 422 test. The checkout example uses one
root `gesso.php` for PHPUnit and Laravel; this shared integration is unreleased
and is not included in 2.6.0. See [Shared configuration](../configuration.md).
The installation instructions below retain the published v2 configuration.

## Run the example

From a checkout of Gesso, with PHP 8.3+, Composer, and PDO SQLite:

```bash
cd examples/scramble
composer install
composer setup
composer test
composer verify
```

`setup` creates the example's SQLite tables before exporting the spec, so
Scramble can inspect Eloquent models. HTTP tests use an in-memory database with
`RefreshDatabase`. Expect six tests, twelve assertions, and six covered responses:

| HTTP route | Tested responses |
| --- | --- |
| `POST /api/users` | 201 resource; 422 validation error |
| `GET /api/users/{user}` | 200 resource; 404 missing model |
| `GET /api/profile` | 200 authenticated resource; 401 unauthenticated error |

## Add Gesso to an existing Scramble app

Install Gesso as a development dependency. If the app still uses PHPUnit 11,
upgrade to a compatible PHPUnit version as part of installation: Gesso 2.x
requires PHPUnit 12 or 13. The example uses 12, which supports PHP 8.3.

```bash
composer require --dev "studio-design/gesso:^2.6" "phpunit/phpunit:^12.0" --with-all-dependencies
php artisan vendor:publish --tag=gesso
```

Keep your existing compatible PHPUnit version if it is already 12 or 13.
See [Scramble's setup guide](https://scramble.dedoc.co/installation) if you have
not installed the generator yet.

Create an `openapi` directory, prepare the database used for model inspection,
then [export Scramble's document](https://scramble.dedoc.co/usage/export):

```bash
mkdir -p openapi build
php artisan scramble:export --path=openapi/api.json --fail-on-unknown
vendor/bin/gesso doctor --spec=openapi/api.json
```

The example pins a Scramble version supporting `--fail-on-unknown`. Inspect
export diagnostics as well: unknown schema types and unavailable model tables
need to be resolved before relying on the generated contract.

Set these values in the published `config/gesso.php`:

```php
'default_spec' => 'api',
'spec_base_path' => 'openapi',
'strip_prefixes' => ['/api'],
```

Register the PHPUnit extension too. It configures the runtime spec loader;
the Laravel `spec_base_path` setting is used by Gesso's Artisan commands.

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi"/>
        <parameter name="specs" value="api"/>
        <parameter name="strip_prefixes" value="/api"/>
        <parameter name="json_output" value="build/coverage.json"/>
    </bootstrap>
</extensions>
```

In this example, Scramble puts `/api` in `servers[].url` and writes `/users` in
`paths`, while Laravel receives `/api/users`. The prefix settings align these
paths. Adapt them to your own exported document and route prefix.

Add `Studio\Gesso\Laravel\ValidatesOpenApiSchema` to your test class, then assert
the real response after its expected status:

```php
namespace Tests\Feature;

use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Tests\TestCase;

final class UserContractTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_invalid_user_matches_contract(): void
    {
        $response = $this->postJson('/api/users', []);
        $response->assertUnprocessable();
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
```

The example uses explicit response assertions. Its `actingAs()` test exercises
Laravel's authentication middleware and the documented response; it does not
prove token validation or enforce an OpenAPI security scheme.

## Keep generated expectations accurate

When a controller returns a newly created Eloquent model as a resource, Laravel
can send 201. In the pinned Scramble version, this example needs `@status 201`
on the **return statement** to document that status:

```php
/** @status 201 */
return new UserResource($user);
```

See [Scramble's response documentation](https://scramble.dedoc.co/usage/response)
for response inference and overrides. The example combines this annotation with
a real `assertCreated()` test so an incorrect status cannot silently pass.

Keep the exported document fixed while testing a change against an accepted
contract. In the example, changing `UserResource`'s integer `id` to a string
without re-exporting fails at `/data/id`. If you regenerate the schema from that
same changed implementation, both may agree on the new type. For compatibility
checks, retain the accepted spec and review the generated diff before accepting
an updated contract. Gesso's coverage gate does not classify breaking changes.

## Fail CI on newly documented but untested responses

Run the full PHPUnit suite to produce `build/coverage.json`. A passing assertion
for 201 does not exercise the 422 response on the same endpoint.

```bash
vendor/bin/phpunit
vendor/bin/gesso coverage:gate \
  --base-spec=build/base-api.json \
  --spec=openapi/api.json \
  --coverage=build/coverage.json
```

Supply `build/base-api.json` from the PR's base revision and `openapi/api.json`
from the proposed revision. Fail CI if either PHPUnit or the gate fails. See the
[changed-response gate guide](../coverage-gate.md) for the complete workflow.

The example's `composer verify` creates a controlled base with only 422 removed.
When the 422 test is omitted in an isolated app copy, PHPUnit still passes, but
coverage falls to 5/6 and the gate exits 1. With all six tests, it exits 0.
This demonstrates a newly added response; an unchanged, already-uncovered
response is outside that gate's scope. Use a
[coverage threshold](../coverage.md#coverage-threshold-gate) to enforce overall coverage.

Gesso does not persist coverage reports for partial PHPUnit selections such as
`--filter`. Generate the gate's report from a full run, and do not reuse a stale
report after a filtered run. Coverage records exercised responses; PHPUnit's
result determines whether those responses conform to the schema.

## Generate the missing 422 test

When the full report shows an untested 422, generate a Laravel test using the
application's mount path:

```bash
php artisan gesso:stubs \
  --coverage=build/coverage.json \
  --request-prefix=/api
```

For the example's missing-422 scenario this creates
`tests/Feature/Contract/PostUsersTest.php`, calling `/api/users`. Review the
generated test and remove its `markTestIncomplete()` call when it is ready.
The generated empty JSON object is appropriate here: this endpoint rejects it
with 422. Other cases may need payload, authentication, or database fixtures.

Run the full suite again, then the coverage gate using the same base spec. This
response should now be covered. Keep `strip_prefixes=/api` in the PHPUnit
configuration: `--request-prefix` controls generated requests, while
`strip_prefixes` controls runtime matching against the spec.

`composer verify` automates the entire sequence in an isolated app copy. It
checks that CLI and Artisan generate the same test, that the fresh test is
incomplete, and that removing only its incomplete marker brings coverage back
from 5/6 to 6/6 and makes the gate pass. The saved
`build/scenarios/build/coverage-missing-422.json` preserves the report before
the gap was closed. See [stub options](../stubs.md) for other adapters and prefix
validation. The new option is available in the repository's development version;
check `gesso stubs --help` before using it with an older installed release.
