# Scramble + Gesso example

Generate an OpenAPI document from a Laravel app, validate its real HTTP test
responses, and detect an untested response added to the contract.

Requires PHP 8.3+, Composer, and PDO SQLite. The scenario verifier uses symlinks
(tested on macOS and Linux). This example uses Laravel 12, PHPUnit 12, and pins
Scramble 0.13.45 so changes to schema inference are reviewed explicitly. Gesso
comes from this repository through a Composer path dependency.

From this directory:

```bash
composer install
composer setup
composer test
composer verify
```

`setup` migrates a local SQLite database for Scramble's model inspection, then
exports `openapi/api.json`. Tests use a separate in-memory database. `test`
validates six responses across three operations:

| HTTP route | Responses | Laravel feature |
| --- | --- | --- |
| `POST /api/users` | 201, 422 | FormRequest and API Resource |
| `GET /api/users/{user}` | 200, 404 | Factory and model binding |
| `GET /api/profile` | 200, 401 | `actingAs()` and auth middleware |

Expect **6 tests, 12 assertions**, with **6/6 responses covered**. The report is
written to `build/coverage.json`.

`verify` copies the app into `build/scenarios`, reuses the installed dependencies,
and checks all of the following:

- All six responses validate, and the changed-response coverage gate passes.
- Returning a string `id` against the frozen integer schema produces three
  contract failures at `/data/id`, with no unrelated test errors.
- Omitting the 422 test leaves 5/6 responses covered. A controlled base spec
  without that 422 models a PR adding it; `coverage:gate` then exits 1.
- CLI and Artisan generate the missing test with `--request-prefix=/api`.
  It starts incomplete. Removing only that marker makes all six responses
  covered again, and the gate passes without editing the generated URL.

Expected failures make `verify` succeed; an unexpected result makes it fail.
Logs, JUnit results, and coverage remain in `build/scenarios/build`. Source files
and the normal coverage report are untouched. Run only one `verify` process at a
time because it recreates that directory.

For your own missing responses, run
`php artisan gesso:stubs --coverage=build/coverage.json --request-prefix=/api`,
review and complete the generated tests, then rerun the full suite and gate.
Keep the PHPUnit extension's `strip_prefixes=/api` setting: generation and runtime
matching are separate. The verifier saves `coverage-missing-422.json` before
completing the generated test so you can compare the reports.

After an intentional contract change, run `composer export` and review the new
spec before testing. Automatically regenerating the contract after a breaking
change would remove the old expectation you meant to enforce.

See the [Scramble integration recipe](../../docs/recipes/scramble.md) for an
existing application's setup, `/api` prefix configuration, and CI gates.
