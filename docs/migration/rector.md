# Rewrite code with Rector

Gesso ships [Rector](https://getrector.com/) sets for the PHP side of a
migration. Config keys and CLI flags are not PHP code: rewrite those with
[`gesso config:migrate`](../cli.md#migrate-a-configuration-configmigrate).

| Set | Constant | Rewrites |
| --- | --- | --- |
| Gesso v2 → v3 | `GessoSetList::V3` | the PHP renames and removals in [Prepare for Gesso 3.0](v3.md) |
| kirschbaum → Gesso | `GessoSetList::KIRSCHBAUM_TO_GESSO` | `kirschbaum-development/laravel-openapi-validator`'s trait |
| Spectator → Gesso | `GessoSetList::SPECTATOR_TO_GESSO` | `hotmeteor/spectator`'s assertions |

The constants live in `Studio\Gesso\Rector\GessoSetList`. They and the files
they name are stable for 3.x. What each set rewrites is best-effort: review
the diff, then run your suite.

## Install and run

Gesso does not install Rector for you. The sets need `rector/rector` ^2.7:

```bash
composer require --dev "rector/rector:^2.7"
```

Run a set once, without a `rector.php`:

```bash
vendor/bin/rector process tests --dry-run --config vendor/studio-design/gesso/src/Rector/config/gesso-v3.php
vendor/bin/rector process tests --config vendor/studio-design/gesso/src/Rector/config/gesso-v3.php
```

For the kirschbaum set, pass
`vendor/studio-design/gesso/src/Rector/config/kirschbaum-to-gesso.php`; for
the Spectator set, `vendor/studio-design/gesso/src/Rector/config/spectator-to-gesso.php`.

Run with `--config` and Rector writes fully qualified names and leaves the
old `use` import in place; the code runs, but the import is stale. To get
short names and drop the stale imports, add the set to a `rector.php`
instead:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Studio\Gesso\Rector\GessoSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/tests'])
    ->withSets([GessoSetList::V3])
    ->withImportNames(removeUnusedImports: true);
```

`withImportNames()` imports every name in the processed paths, not only the
renamed ones. Leave it out if that would change code you do not want touched,
and let PHP-CS-Fixer's `no_unused_imports` drop the stale imports.

## Gesso v2 → v3

Run it on v2.9 or v3; a second run changes nothing.

| v2 | v3 |
| --- | --- |
| `Studio\Gesso\Fuzz\{ContractCheck, ContractCheckFailure, ContractCheckPlan, ContractCheckProbe, ContractCheckSkip, ContractCheckSummary, OpenApiContractChecks}` | the same class under `Studio\Gesso\Contract\` |
| `EnumBindingException::forBinding(...)` | `new EnumBindingException(...)`, same arguments |
| `OpenApiCoverageTracker::hasAnyCoverage($spec)` | `OpenApiCoverageTracker::current()->hasAnyCoverageOn($spec)` |
| `OpenApiSpecLoader::configure(..., enumBasePath: ...)` | the argument removed, named or positional |
| `$this->failOpenApi()`, `failPsr7()`, `failExplore()` | `$this->fail()` |
| `$this->assertOpenApi()`, `assertPsr7()` | `$this->assertTrue()` |
| `$this->shouldSkipOpenApi()` | `$this->findSkipOpenApiAttribute() !== null` |

The private-helper rows apply only in a class that uses the Gesso trait that
composed the helper, and not when the class declares a method of that name.
A positional sixth argument to `configure()` is removed only when it cannot
be an array, because v3 has `allowedRemoteRefHosts` at that position. Remove
an untyped variable there by hand.

Not rewritten, because the replacement needs a decision:

- `#[BoundToOpenApiEnum('path/to/file.json')]`: replace the path with the
  component name, or delete the attribute when the names match. See
  [Enum binding](v3.md#enum-binding).
- `OpenApiSpecLoader::getEnumBasePath()` and the removed `EnumBindingReason`
  cases: there is no replacement. Delete the code that uses them.

## kirschbaum-development/laravel-openapi-validator

The set renames the trait `Kirschbaum\OpenApiValidator\ValidatesOpenApiSpec`
to `Studio\Gesso\Laravel\ValidatesOpenApiSchema`. These calls keep their
names and need no rewrite: `withoutValidation()`,
`withoutRequestValidation()`, `withoutResponseValidation()`, and
`skipResponseCode()`.

kirschbaum validates every request and response. Gesso does that only with
`laravel.auto_assert` and `laravel.auto_validate_request` on, which
`gesso config:migrate` proposes when it finds the package. The set does not
rewrite these, because Gesso has no 1:1 equivalent:

| kirschbaum | Gesso | Why it is manual |
| --- | --- | --- |
| `protected $responseCodesToSkip = [...]` | `validation.skip_response_codes` in `gesso.php` | kirschbaum's property is per class; Gesso's setting is per suite, and the Laravel trait has no per-class hook. |
| `skipResponseCode(...)` | same name | Same call, other semantics: kirschbaum keeps the codes for the rest of the test and matches them unanchored; Gesso applies them to the next request only and anchors them. Run `vendor/bin/gesso config:migrate` before removing kirschbaum: it lists every call with its file and line, and `--check` fails CI until the package is gone. |
| overriding `getAuthenticatedRequest()` | `laravel.auto_inject_dummy_credentials` | The override is code; Gesso's setting is config. |
| overriding `getOpenApiSpecPath()`, `getSpecFileType()`, or `getOpenApiValidatorBuilder()` | `spec.base_path` and `spec.default` | Gesso reads the spec from config. Delete the override. |

See [kirschbaum-development/laravel-openapi-validator](from-other-validators.md#kirschbaum-developmentlaravel-openapi-validator)
for the rest of the migration.

## hotmeteor/spectator

Run [`gesso config:migrate`](../cli.md#migrate-a-configuration-configmigrate)
first, while the `Spectator::using()` calls are still there: it turns a single
spec name into `spec.default` and proposes `laravel.auto_assert` and
`laravel.auto_validate_request`. Those two settings replace the assertions the
set deletes; without them the migrated tests validate nothing.

| Spectator | Gesso |
| --- | --- |
| `->assertValidRequest()` | deleted; `laravel.auto_validate_request` validates every request |
| `->assertValidResponse()` | deleted; `laravel.auto_assert` validates every response |
| `->assertValidResponse($status)` | `->assertStatus($status)` |
| `Spectator::using(...);` | deleted; `spec.default`, or `#[OpenApiSpec('name')]` when the tests use several specs |
| a class that used any of these | adds `use ValidatesOpenApiSchema;` |

A statement left with nothing but a variable, such as
`$response->assertValidResponse();`, is deleted. The assertions are
`TestResponse` macros, so the set matches them by name; it leaves a call alone
when the receiver's type declares a method of that name itself.

Not rewritten, because the replacement needs a decision:

- `assertInvalidRequest()`, `assertInvalidResponse()`, `assertErrorsContain()`,
  `assertValidationMessage()`, and `dumpSpecErrors()`: call
  `withoutRequestValidation()` or `withoutResponseValidation()` and assert on
  the validator result instead.
- `assertPathExists()`: compare routes and spec with `php artisan gesso:routes`.
- `Spectator::reset()`: use `#[SkipOpenApi]` or `$this->withoutValidation()`.
- Pest test files: add the trait with `uses(ValidatesOpenApiSchema::class)`.

`gesso config:migrate` lists every remaining call with its file and line.
See [Spectator](from-other-validators.md#spectator) for the rest of the
migration.
