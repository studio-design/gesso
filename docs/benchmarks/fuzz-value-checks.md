# Generated-value check benchmark

Measured on 2026-11-11 with PHP 8.5.5 (ARM64, `pcre.jit` off, no opcache),
opis/json-schema 2.6.0 and fakerphp/faker 1.24.1. "Before" is `main` at
55716ca, "after" is the change for issue #726; both ran the same script.

The benchmark generates an OpenAPI 3.0.3 spec with three operations per
resource and explores them through `OpenApiEndpointExplorer` with 30 cases
per call and a fixed seed per resource. "First call" is a sweep over every
resource of the spec, starting with nothing parsed, divided by the number of
resources. "Repeated call" is a call on an operation explored just before.
Times are the median of five rounds.

## Time

Milliseconds per call, 100 resources.

| Call | Before, first | After, first | Before, repeated | After, repeated |
|---|---:|---:|---:|---:|
| `explore()`, `POST` with a 4-property body | 4.23 | 1.40 | 4.26 | 1.30 |
| `exploreInvalid()`, `POST` with a 4-property body | 64.08 | 12.49 | 64.56 | 11.06 |
| `exploreInvalid()`, `GET` with 4 parameters | 36.02 | 3.62 | 36.12 | 3.65 |
| `explore()`, `PUT` with an 8-property nested body | 6.48 | 2.29 | 6.35 | 2.02 |
| `exploreInvalid()`, `PUT` with an 8-property nested body | 172.32 | 33.44 | 174.77 | 29.41 |

A first call gains almost as much as a repeated one: one call checks its
candidate values against the same few schemas many times over, so most of the
parsing it saves is its own.

With 400 resources the "after" columns are the same within 4% (for example
12.61 and 11.12 ms for `exploreInvalid()` on the `POST`). The sweep goes
through far more schemas than the cache holds, but it only ever needs the
ones of the operation it is on. "Before" was not run with 400 resources.

## Same cases

The script prints a SHA-256 digest over the serialized cases of the whole
seeded sweep. Before and after printed the same digest
(`f2bfb47d…62ab11` with 100 resources), and a separate run that hashed each
of the 500 results on its own matched 500 of 500.

The spec deliberately has no `date`, `time` or `date-time` property: faker
draws those between 0 and the current time, so their values differ from one
run to the next on the same revision (issue #732).

## Retained memory

Memory still held after exploring every operation of the spec once, measured
after `gc_collect_cycles()`.

| Resources | Before | After |
|---:|---:|---:|
| 100 | 3.6 MB | 14.5 MB |
| 400 | not run | 14.3 MB |

Before the change nothing parsed outlives a check, so the "before" column is
the baseline of the run. After it, memory grows until the budget is reached
and then stays flat.

## The bound

The cache is bounded by the summed JSON length of the schemas it holds, at
256 KiB, plus 128 bytes per schema. One-off measurements outside the script,
through reflection on the cache:

| Cached schemas | Held by the cache | Held ÷ JSON length |
|---|---:|---:|
| 29, from one operation with an 8-property body (15 KiB) | 0.8 MB | 54 |
| 66, from one operation with a 20-property body (80 KiB) | 4.2 MB | 53 |
| 98, a full cache of 2.5 KB schemas (242 KiB) | 13.0 MB | 55 |
| 51, a full cache of 5 KB schemas (248 KiB) | 14.1 MB | 58 |

A full cache therefore holds about 14 MB however large the individual schemas
are. The length alone undercounts very small schemas: `{const: N}` retains
about 3.4 KB, which is what the 128 bytes are for. With that charge, 20,000
distinct schemas of this kind leave 1,833 cached and 6.0 MB held.

### What does not fit

`exploreInvalid()` checks each valid case against the body schema and against
one variant of it per mutation target, and goes through those variants again
for every case. The variants are the size of the schema, so what one call
needs grows with the square of the body: 66 schemas and 80 KiB of JSON for 20
properties, 104 and 249 KiB for 40, 107 and 507 KiB for 80. Once that exceeds
the budget, a pass over the variants always asks for the one dropped longest
ago, and most checks parse again.

Milliseconds for one `exploreInvalid()` call with 30 cases on a `POST` whose
body has that many properties (one-off, a single run each):

| Body properties | Before | After, 256 KiB | With a 1 MiB budget |
|---:|---:|---:|---:|
| 20 | 868 | 137 | 134 |
| 40 | 2,630 | 1,453 | 422 |
| 80 | 4,907 | 3,828 | 849 |

The 40-property call is just past the budget: its 104 schemas are charged
about 262 KiB. A body of that size or larger still gains, and never loses,
but does not get the full saving. A 1 MiB budget would give it: it holds
13.4 MB for the 40-property call and 28.4 MB for the 80-property one, and by
the ratios above a full cache would hold about four times what 256 KiB does. The budget is one constant,
`SchemaValueValidator::MAX_CACHED_BYTES`.

## One loader per schema

Each cached schema has an opis validator of its own; only the `SchemaParser`
is shared. An opis loader resolves a root schema by its `$id` before it looks
at the object, and the generators check a value against variants of a schema
that keep its `$id`. With one validator for all schemas, `7` against
`{$id, type: integer}` was answered `false` after the same validator had seen
`{$id, type: integer, multipleOf: 5}`, and a nested `$id` shared by two
schemas threw `DuplicateSchemaIdException`. A new validator answers `true` in
both cases.

Reproduce locally:

```bash
php benchmarks/fuzz-value-checks.php 100 30
php benchmarks/fuzz-value-checks.php 400 30
```

The arguments are the number of resources and the cases per call. The script
explores through the public `OpenApiEndpointExplorer` and empties the cache
between rounds only where the revision has one, so the same file measures any
revision.
