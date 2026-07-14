# ADR 0001: Align the v2 technical identity with Gesso

- Status: Proposed
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

## Open decisions

The following require evidence or maintainer approval before this ADR becomes
Accepted:

- whether `gesso coverage:merge` replaces the standalone binary immediately or
  keeps a deprecated executable shim;
- how conflicts between old and new Laravel configuration are reported;
- which machine-readable formats require a schema-version increment;
- the PHP minimum version and supported PHPUnit matrix at the planned v2 GA
  date;

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
- [Composer package links and `replace`](https://getcomposer.org/doc/04-schema.md#package-links)
- [Composer repositories and package renaming](https://getcomposer.org/doc/05-repositories.md)
- [Packagist package naming](https://packagist.org/about)
- [PSR-4 autoloading](https://www.php-fig.org/psr/psr-4/)
- [PHP `class_alias()`](https://www.php.net/class-alias)
- [Composer autoloader optimization](https://getcomposer.org/doc/articles/autoloader-optimization.md)
- [Symfony backward compatibility promise](https://symfony.com/doc/current/contributing/code/bc.html)
- [Laminas migration tooling](https://docs.laminas.dev/migration/)
