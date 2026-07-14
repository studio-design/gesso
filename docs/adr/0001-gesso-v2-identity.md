# ADR 0001: Align the v2 technical identity with Gesso

- Status: Accepted
- Date: 2026-07-13
- Owners: Gesso maintainers

## Context

The project is already presented publicly as **Gesso**. The GitHub repository,
documentation site, visual identity, package description, and support URLs use
the Gesso name. Version 1.x deliberately retains the original technical
identifiers for backward compatibility:

- Composer package: `studio-design/openapi-contract-testing`
- PHP namespace: `Studio\OpenApiContractTesting\`
- executables: `openapi-contract` and `openapi-coverage-merge`
- Laravel configuration file and key: `openapi-contract-testing`
- PHPUnit extension and framework adapter FQCNs under the original namespace
- versioned and machine-readable output containing the original package name

These identifiers are part of the documented v1 public compatibility surface.
Replacing them in a minor release would break source code, Composer manifests,
PHPUnit XML, Laravel configuration, CI commands, or downstream output parsers.

Packagist package names are immutable. Publishing `studio-design/gesso`
therefore creates a new package identity rather than renaming the existing
package in place. Existing consumers will need an explicit migration.

## Decision

Gesso 2.0 will make the Gesso identity canonical across the complete supported
surface:

| Surface | v1 identifier | v2 canonical identifier |
| --- | --- | --- |
| Composer package | `studio-design/openapi-contract-testing` | `studio-design/gesso` |
| PHP namespace | `Studio\OpenApiContractTesting\` | `Studio\Gesso\` |
| Primary CLI | `openapi-contract` | `gesso` |
| Coverage merge CLI | `openapi-coverage-merge` | `gesso coverage:merge` |
| Laravel config file/key | `openapi-contract-testing` | `gesso` |
| Laravel service provider | `OpenApiContractTestingServiceProvider` | `GessoServiceProvider` |
| PHPUnit extension | original namespace FQCN | `Studio\Gesso\PHPUnit\...` |
| Machine-readable tool identity | `studio-design/openapi-contract-testing` | `studio-design/gesso` |

Class names that describe the OpenAPI domain, such as
`OpenApiResponseValidator`, remain unchanged. They are domain terminology, not
legacy branding.

The migration will follow these rules:

1. Publish a final v1 minor release that introduces every safe migration aid,
   documents deprecations, and allows consumers to prepare their application
   before installing v2.
2. Treat Composer package migration separately from source migration. Do not
   present `composer update` as sufficient when the root requirement must
   change.
3. Do not use an unconstrained Composer `replace` entry for the old package.
   It could incorrectly satisfy dependencies that require an incompatible v1
   release. The exact `conflict` or constrained `replace` policy must be proven
   with dependency-resolution fixtures before publication.
4. Register `studio-design/gesso` as a new Packagist package. Mark the old
   package abandoned with `studio-design/gesso` as its replacement only after
   the v2 stable package is installable and verified.
5. Keep readers compatible with supported older wire formats where practical.
   A branding field change does not by itself justify making parallel coverage
   data unreadable. Writers may move to a new schema version when the documented
   format requires it.
6. Provide a documented, reviewable migration procedure for Composer
   requirements, PHP imports, PHPUnit XML, Laravel configuration, CLI commands,
   CI scripts, and machine-readable output consumers.
7. Release v2 through pre-release stages and dogfood it in the repository's
   framework examples before general availability.

### Namespace transition policy

V1.10.0 exposes lazy `Studio\Gesso\` aliases for every non-`@internal` public
v1 type. The aliases are an explicit allowlist checked against the public API
inventory; they do not make internal types part of the new public surface. This
lets consumers migrate PHP imports while the v1 declarations and Composer
package remain canonical.

Gesso v2 declares `Studio\Gesso\` as canonical and does not ship a reverse
`Studio\OpenApiContractTesting\` namespace shim. The major boundary is where
the legacy PHP identity is removed. Reflection, serialization, configuration,
commands, and package requirements are not made portable by the v1 aliases and
must follow their documented migration steps.

### CLI transition policy

V1.10.0 adds the `gesso` Composer binary with `doctor` and
`coverage:merge` subcommands. The existing `openapi-contract` and
`openapi-coverage-merge` binaries remain unchanged throughout v1 maintenance.
Consumers can therefore migrate CI and local scripts separately from the v2
Composer package switch.

Gesso v2 ships only the unified `gesso` binary. It does not retain deprecated
standalone executable shims. Keeping the old binaries in v1.10 provides the
overlap period; carrying them into v2 would preserve the legacy technical
identity that the major release exists to remove. Command options, exit codes,
and command-specific output remain compatible unless a separately documented
v2 change requires otherwise.

### Laravel configuration transition policy

V1.10 continues to own only the `openapi-contract-testing` configuration key,
publish tag, and `config/openapi-contract-testing.php` destination. It does not
also register `gesso` as an alias. Claiming a second top-level configuration key
in a minor release could collide with an application's existing configuration,
and keeping two mutable copies synchronized would make runtime precedence
ambiguous.

Before installing v2, Laravel consumers rename the published file to
`config/gesso.php` and update direct `config('openapi-contract-testing...')`
lookups to `config('gesso...')`. They must clear any Laravel configuration cache
before changing the Composer package, then rebuild it after the v2 application
boots successfully.

The v2 `GessoServiceProvider` merges defaults only under `gesso` and publishes
only the `gesso` tag to `config/gesso.php`. If the legacy
`openapi-contract-testing` top-level key is present when the provider registers,
v2 throws an actionable configuration exception. This applies whether or not a
`gesso` key is also present; v2 does not compare values, choose a winner, or
silently fall back to the legacy key. A stale configuration cache therefore
fails visibly instead of running validation with unintended settings.

V2 provider tests must cover defaults with no published file, overrides from a
`gesso` file, the new publish tag and destination, and rejection of both
legacy-only and dual-key configurations. This follows Laravel's package
configuration model: package defaults are merged under one application key and
published to one application configuration file. Laravel documents that
`mergeConfigFrom` merges only the first level, so the migration preserves the
existing file values rather than reconstructing or recursively combining two
configurations.

### Machine-readable format transition policy

Gesso v2 changes the coverage JSON report from `schema_version: 1` to
`schema_version: 2`. The report's documented `tool.name` value changes from
`studio-design/openapi-contract-testing` to `studio-design/gesso`; although the
JSON object shape is unchanged, that fixed value is part of the contract and a
consumer may validate or route reports by it. Schema version 2 otherwise keeps
the version 1 fields, types, and meanings. The v2 writer emits only the Gesso
identity rather than a second legacy identity field.

The identity migration does not change the other versioned formats:

| Format | Gesso v2 writer | Gesso v2 reader responsibility | Reason |
| --- | ---: | --- | --- |
| Coverage JSON report | `schema_version: 2` | N/A (output contract) | The documented fixed `tool.name` value changes |
| Doctor JSON | `schemaVersion: 1` | N/A (output contract) | It contains no package or namespace identity and its shape is unchanged |
| Laravel route parity JSON | `schema_version: 1` | N/A (output contract) | It contains no package or namespace identity and its shape is unchanged |
| Sidecar envelope | `envelopeVersion: 2` | Continue accepting the documented v1.9 envelope and legacy bare coverage state | No persisted field changes for the rename |
| Coverage tracker state | `version: 1` | Continue accepting version 1 | No persisted field changes for the rename |
| Strict-required tracker state | `version: 2` | Continue accepting version 2 inside supported envelopes | No persisted field changes for the rename |

Format versions describe their own contracts, not the Composer package major.
They are therefore not synchronized to `2` merely because Gesso itself reaches
2.0. A later removal, rename, type change, changed required value, or other
incompatible semantic change requires the owning format version to advance;
an unchanged format retains its existing version. Unknown versions continue to
fail loudly rather than being interpreted as the nearest known shape.

The implementation must pin the coverage JSON v2 document and Gesso tool
identity with a golden fixture. It must also retain regression tests proving
that Doctor and route parity still emit version 1 and that the v2 sidecar reader
accepts every older payload promised by the versioning policy. JSON Schema
defines a fixed value as the equivalent of a single-value enum, so changing the
documented tool name is an incompatible value constraint even though no member
is added or removed.

### Runtime and test-runner support policy

Gesso v2 requires PHP `^8.3` and supports PHPUnit `^12.0 || ^13.0`. The v2 CI
matrix covers these compatible pairs:

| PHP | PHPUnit 12 | PHPUnit 13 |
| --- | --- | --- |
| 8.3 | highest and lowest dependency lanes | Not compatible upstream |
| 8.4 | highest dependency lane | highest dependency lane |
| 8.5 | highest dependency lane | highest dependency lane |

PHP 8.2 reaches upstream security EOL on 2026-12-31. The v1 lifecycle requires
v2 stable to be available before that date unless the schedule is explicitly
revised, so starting a new major on PHP 8.2 would leave no useful upstream
support runway. PHP 8.3 remains under security support through 2027-12-31 and
preserves a wider installation base than an unnecessary PHP 8.4 floor.

PHPUnit 11 stopped receiving bug fixes on 2026-02-06. PHPUnit 12 supports PHP
8.3 and receives bug fixes through 2027-02-05; PHPUnit 13 supports PHP 8.4 and
later and receives bug fixes through 2028-02-04. Keeping both supported majors
lets PHP 8.3 consumers use a maintained runner while PHP 8.4 and 8.5 consumers
can adopt the current major. This is an intentional bounded constraint, not an
open-ended major-version range.

The optional Pest smoke test moves from Pest 3 / PHPUnit 11 to Pest 4 / PHPUnit
12. Pest 4 requires PHP 8.3 or later and is built on PHPUnit 12. It remains a
separate on-demand lane because Pest is not a production dependency and its
current constraint must not prevent the regular matrix from exercising PHPUnit
13. The Composer constraints, examples, CI jobs, and formatting target move to
this policy only after `main` becomes the v2 development line; v1.10 retains its
declared PHP and PHPUnit support.

## Scope boundaries

The v2 identity migration does not by itself authorize unrelated redesigns.
The following are separate decisions and should not be bundled into the rename:

- splitting the repository into multiple Composer packages;
- a major upgrade of `opis/json-schema` or another production dependency;
- implementing OpenAPI diff as a new product area;
- rewriting the validator, coverage engine, or framework adapters wholesale;
- changing OpenAPI validation semantics solely because a major version is
  available.

Small breaking cleanups that already have evidence and cannot be completed in
v1 may be included as separately reviewable changes. Each must have its own
migration note and regression coverage.

## Required preparation

Before the first breaking rename is merged:

1. Record the complete v1 public compatibility surface, including PHP symbols,
   configuration, commands, exit codes, warning categories, and versioned
   output formats.
2. Add golden or consumer-level fixtures for each non-PHP compatibility surface.
3. Resolve the current documentation ambiguity around whether the coverage
   sidecar shape is a SemVer compatibility surface.
4. Verify any proposed namespace compatibility mechanism across classes,
   interfaces, traits, enums, attributes, reflection, serialization, and
   Composer optimized autoloading. Do not assume `class_alias()` covers every
   supported use case without tests. The completed
   [namespace compatibility spike](../migration/v2-namespace-compatibility-spike.md)
   proves lazy aliases and records their identity limits. The namespace
   transition policy above fixes their v1 scope and excludes a reverse v2 shim.
5. Define the v1 maintenance branch and end-of-support date before v2
   pre-releases begin. The completed
   [v1 maintenance lifecycle](../versioning.md#v1-maintenance-lifecycle) fixes
   the `1.x` branch workflow, accepted changes, and 2027-07-01 EOL date.

## Consequences

### Positive

- Installation, namespaces, commands, documentation, and output consistently
  identify the project as Gesso.
- Future APIs no longer extend a legacy product namespace.
- The explicit migration boundary makes compatibility costs visible and
  testable.

### Negative

- Every existing consumer must update at least its Composer requirement and PHP
  imports.
- Two Packagist package identities must be managed during the transition.
- Migration fixtures and temporary compatibility code add release overhead.

### Risks

- An overly broad Composer replacement rule can silently resolve incompatible
  dependency constraints.
- Permanent aliases can leave Gesso carrying two public identities indefinitely.
- Combining the rename with unrelated architecture work can make regressions
  difficult to isolate.
- Machine-readable branding changes can break downstream tooling even when the
  surrounding JSON shape is unchanged.

## Verification gates

Gesso 2.0 is not ready for stable release until all of the following pass:

- strict Composer validation and a clean install of `studio-design/gesso`;
- supported PHP, PHPUnit, lowest-dependency, Pest, Laravel, Symfony, and PSR-7
  matrices;
- optimized Composer autoload checks for the canonical namespace;
- a clean v1-to-v2 consumer migration fixture;
- PHPUnit XML and Laravel published-config migration fixtures;
- CLI help, flags, exit-code, stdout, and stderr golden tests;
- backward-reader tests for supported coverage sidecars and other wire formats;
- documentation builds, links, examples, and migration commands;
- Packagist metadata, abandonment replacement, GitHub tag, and release manifest
  consistency.

## References

- [Semantic Versioning 2.0.0](https://semver.org/)
- [PHP supported versions](https://www.php.net/supported-versions.php)
- [PHPUnit supported versions](https://phpunit.de/supported-versions.html)
- [Pest support policy](https://pestphp.com/docs/support-policy)
- [Pest 4 upgrade guide](https://pestphp.com/docs/upgrade-guide)
- [JSON Schema 2020-12 validation keyword: `const`](https://json-schema.org/draft/2020-12/json-schema-validation#section-6.1.3)
- [Composer package links and `replace`](https://getcomposer.org/doc/04-schema.md#package-links)
- [Composer repositories and package renaming](https://getcomposer.org/doc/05-repositories.md)
- [Packagist package naming](https://packagist.org/about)
- [PSR-4 autoloading](https://www.php-fig.org/psr/psr-4/)
- [PHP `class_alias()`](https://www.php.net/class-alias)
- [Composer autoloader optimization](https://getcomposer.org/doc/articles/autoloader-optimization.md)
- [Laravel package configuration](https://laravel.com/docs/packages#configuration)
- [Symfony backward compatibility promise](https://symfony.com/doc/current/contributing/code/bc.html)
- [Laminas migration tooling](https://docs.laminas.dev/migration/)
