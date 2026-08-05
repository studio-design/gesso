# Test stub scaffolding

`gesso stubs` writes test skeletons for the responses your suite does not
exercise yet. Point it at a spec and a [coverage document](coverage-json-schema.md)
and it generates one test class per operation, one test method per uncovered
`(status, content-type)` tuple — the same granularity the
[coverage report](coverage.md) measures.

```bash
vendor/bin/gesso stubs \
  --spec=openapi.json \
  --coverage=build/coverage.json \
  --adapter=laravel
```

```text
[Gesso] Wrote 3 files covering 7 uncovered responses to tests/Feature/Contract:
  + tests/Feature/Contract/DeletePetsPetIdTest.php
  + tests/Feature/Contract/PostPetsTest.php
  + tests/Feature/Contract/PutPetsPetIdTest.php

1 file already exists and was left untouched:
  = tests/Feature/Contract/GetPetsTest.php
```

Without `--coverage` the whole spec is scaffolded. With it, a response the
document reports as `validated` is skipped; `uncovered` and `skipped` responses
both get a stub, because a skipped response is untested too.

Laravel users can run the same thing through Artisan, which takes the spec from
`gesso.default_spec` / `gesso.spec_base_path`:

```bash
php artisan openapi:stubs --coverage=build/coverage.json
```

## Options

| Option | Default | Meaning |
|---|---|---|
| `--spec=<path>` | required | OpenAPI document (`.json` / `.yaml` / `.yml`). |
| `--coverage=<path>` | — | Coverage JSON (`schema_version` 3). Omit to scaffold the whole spec. |
| `--spec-name=<name>` | `--spec` filename | Key under `specs` in the coverage document, and the spec name written into the generated tests. |
| `--adapter=<name>` | `phpunit` | `phpunit`, `laravel`, `symfony`, or `pest`. |
| `--output=<dir>` | per adapter | Where to write. `tests/Contract`, or `tests/Feature/Contract` for `laravel` and `pest`. |
| `--namespace=<ns>` | per adapter | Namespace for the generated classes. Ignored by `pest`. |
| `--base-class=<fqcn>` | per adapter | Test class to extend — `PHPUnit\Framework\TestCase`, or `Tests\TestCase` for `laravel`. Ignored by `pest`. |
| `--dry-run` | off | Report what would be written without writing it. |

Exit code `0` means stubs were written or there was nothing left to stub; `2`
means a usage error or an unreadable input.

The Artisan command takes the same options. Its `--spec` accepts a spec *name*
resolved under `gesso.spec_base_path` as well as a path.

## What ends up in a stub

Each generated test is marked incomplete — `markTestIncomplete()`, or `->todo()`
for Pest — so a freshly scaffolded suite reports outstanding work instead of
failures. Removing that line is how you say the stub is finished.

Everything the spec pins down is filled in:

- **Path and query.** Path template variables are substituted, and required
  query parameters are appended. Values come from the parameter's `example`,
  then `schema.default` / `schema.enum`, then a type- and format-shaped
  placeholder (`1` for an integer, a zero UUID for `format: uuid`, …). `TODO`
  is the last resort.
- **Required headers.** `in: header` parameters marked `required` are passed on
  the request.
- **Bodies.** A request body or response `example` (or the first entry of
  `examples`) becomes the literal in the stub. Without one you get `[]` under a
  `// TODO` comment. The call shape follows the media type: JSON objects go
  through the JSON helpers, `application/x-www-form-urlencoded` and
  `multipart/form-data` are sent as request fields so the form decoders see a
  field map, and anything else goes out as a raw body. Whichever shape it
  takes, the declared media type is set explicitly — `Request::create()` would
  otherwise default a form request to `application/x-www-form-urlencoded` (and
  leave `PATCH` with no `Content-Type` at all), so a multipart operation would
  never match its own `requestBody`. A multipart stub points at `UploadedFile`
  for the file parts rather than hand-building a boundary.

  "JSON" here means what the validator means by it — `application/json` or a
  `+json` suffix. `application/vnd.acme+json` uses the JSON helpers;
  `application/notjson` does not. A spec key that is a *range* (`application/*`,
  `*/*`) is not something a client can put on the wire, so the stub sends a
  concrete type the range covers.

  A form body on an `additionalOperations` method goes out as raw urlencoded
  bytes rather than request fields: `Request::create()` only moves its
  parameters into the request bag for POST/PUT/PATCH/DELETE/QUERY, and routes
  anything else into the query bag. `multipart/form-data` has no raw-byte form
  the decoder can parse back, so a multipart body on a custom method is
  reported as not stubbable for the Laravel, Pest, and Symfony adapters rather
  than emitted as a request that would silently validate as skipped. The
  `phpunit` adapter is unaffected — it only validates responses.
- **Status codes.** A range key such as `4XX`, or `default`, is exercised as a
  concrete code with a comment saying which one was picked. The code is chosen
  the way the runtime resolver reads the spec — exact keys win over ranges, and
  ranges over `default` — so an operation declaring both `400` and `4XX` gets a
  `4XX` stub sending 401, not one that would silently validate the `400` schema.
  A key no status can reach (a `4XX` alongside all 100 exact 4xx codes) is
  reported rather than stubbed, because no test could ever cover it. A key that
  is not a status, a range, or `default` is reported as malformed and does not
  affect its operation's valid keys — run [`gesso doctor`](doctor.md) for those.
- **Specification extensions.** An `x-` key on a Responses Object is not a
  response and gets no stub.
- **Content negotiation.** Each response media type gets its own `Accept`
  header, so two media types declared under one status do not both resolve to
  the same response. As on the request side, a range key (`application/*`) is
  exercised as a concrete type it covers — sending the range itself would make
  the validator read the body as non-JSON and skip the schema, so a violating
  body would pass. The declared key still names the test and the coverage tuple
  it closes.
- **Responses without `content`.** These become a single "no content" test, the
  same tuple the coverage tracker records for a 204.

```php
#[OpenApiSpec('petstore')]
final class PostPetsTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_post_pets_201_application_json(): void
    {
        $this->markTestIncomplete('Exercise POST /pets returns 201 application/json.');

        // TODO: adjust the payload your application expects.
        $payload = [
            'name' => 'Fido',
        ];

        $response = $this->postJson(
            '/pets',
            $payload,
            [
                'Accept' => 'application/json',
            ],
        );

        $response->assertStatus(201);
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
```

Each adapter follows its own [quickstart](quickstarts/laravel.md) idiom, so
generated code reads like the documented usage rather than a dialect of its own.
A request body that Laravel's JSON helpers cannot carry — a scalar, or a
non-JSON media type — is sent through `call()` with an explicit `Content-Type`
instead of `postJson()`.

Pest stubs default into `tests/Feature/Contract` because they generate Laravel
HTTP calls: they need the
`uses(TestCase::class, ValidatesOpenApiSchema::class)->in('Feature')` binding
from [the Pest guide](pest-plugin.md) to have a harness once `->todo()` comes
off. Point `--output` elsewhere only if your `uses(...)` reaches there.

## Re-running it

The command never overwrites a file. Once you have edited
`PostPetsTest.php`, a later run reports it as untouched and writes only the
operations that have appeared since. Output is deterministic — operations are
ordered by `METHOD path`, responses by status then content type — so two runs on
the same inputs produce byte-identical files.

That makes the loop straightforward: run your suite, regenerate, fill in the
next batch.

```bash
vendor/bin/phpunit                      # writes build/coverage.json
vendor/bin/gesso stubs --spec=openapi.json --coverage=build/coverage.json
```

Class names come from the method and path — `GET /pets/{petId}` becomes
`GetPetsPetIdTest` — not from `operationId`, so a document that reuses an
`operationId` cannot collide two operations into one file. Where two paths do
normalise to the same name (`/foo-bar` and `/foo/bar`), each gets a suffix
derived from its own endpoint, so neither is dropped and neither name shifts
when an unrelated operation joins the spec. Collisions are resolved against
every operation the spec declares, not just the uncovered ones, so a name stays
put once the other side of a collision goes green.

`--spec` is loaded through the runtime loader, which resolves a *name* and
searches `.json` before `.yaml` before `.yml`. Passing `--spec=openapi.yaml`
next to an `openapi.json` fails rather than silently stubbing the JSON —
the same shadowing check [`gesso doctor`](doctor.md) makes.

## Scope

Only the methods the coverage tracker records are stubbed: `GET`, `POST`, `PUT`,
`PATCH`, `DELETE`, `QUERY`, and OpenAPI 3.2 `additionalOperations`. `OPTIONS`,
`HEAD`, and `TRACE` never appear in a coverage document, so a stub for one could
never turn a tuple green.

Generated tests are yours to edit; they are ordinary test files with no runtime
dependency on the generator.
