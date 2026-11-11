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
| `explore()`, `POST` with a 4-property body | 4.23 | 1.35 | 4.26 | 1.24 |
| `exploreInvalid()`, `POST` with a 4-property body | 64.08 | 12.13 | 64.56 | 10.92 |
| `exploreInvalid()`, `GET` with 4 parameters | 36.02 | 3.54 | 36.12 | 3.48 |
| `explore()`, `PUT` with an 8-property nested body | 6.48 | 2.21 | 6.35 | 1.98 |
| `exploreInvalid()`, `PUT` with an 8-property nested body | 172.32 | 32.41 | 174.77 | 28.46 |

A first call gains almost as much as a repeated one: one call checks its
candidate values against the same few schemas many times over, so most of the
parsing it saves is its own.

With 400 resources the "after" columns are the same within 5% (for example
12.16 and 11.11 ms for `exploreInvalid()` on the `POST`). The sweep goes
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
| 100 | 3.6 MB | 20.2 MB |
| 400 | not run | 20.1 MB |

Before the change nothing parsed outlives a check, so the "before" column is
the baseline of the run. After it, memory grows until the budget is reached
and then stays flat.

## The bound

The cache is bounded by the memory its schemas hold, at 16 MB. Each check is
charged what `memory_get_usage()` says it left behind, on top of the schema's
JSON length and 128 bytes for the map key and the entry. Over budget, the
least recently used schemas are dropped.

A length would not do as the measure. Opis parses a subschema when a value
first reaches it, so what a schema holds depends on which values it has seen:
8 to 54 times its JSON length over the request bodies of the spec below, and
53 to 58 times for the flat generated bodies further down.

One-off measurements outside the script, on the JSON request bodies of
GitHub's public REST API description (`api.github.com.json`, 14 MB, 367
bodies, fetched on 2026-11-11). `exploreInvalid()` with 30 cases returned
cases for 321 of them and threw for 46, whose causes were not looked into.
With nothing dropped, for 320 bodies after the first of the process:

| | Min | Median | 90th percentile | Max |
|---|---:|---:|---:|---:|
| Bytes charged ÷ bytes freed by emptying the cache | 0.95 | 0.97 | 1.00 | 1.07 |
| Memory the schemas of one call hold | | 0.07 MB | 0.50 MB | 59.0 MB |

The first checks of a process are also charged the opis classes they load,
about 0.7 MB in all, until the schema they were charged to is dropped.

Two of the 321 calls need more than the budget (59.0 and 62.7 MB, the two
largest bodies, 38 KB of schema each); the next largest need 13.0 and
11.9 MB. Exploring all 321 in one process:

| | Time | Peak memory over the loaded spec |
|---|---:|---:|
| Before | 37.7 s | 7 MB |
| After, 16 MB | 17.0 s | 23 MB |
| With a 32 MB budget | 16.8 s | 39 MB |
| With a 64 MB budget | 9.9 s | 72 MB |

### What does not fit

`exploreInvalid()` checks each valid case against the body schema and against
one variant of it per mutation target, and goes through those variants again
for every case. The variants are the size of the schema, and their number
levels off at about a hundred, so past that point what one call needs grows in
step with the body. A pass over the variants always asks for the one dropped
longest ago, so a budget that holds only some of them saves next to nothing:
32 MB does no better than 16 MB above, and 64 MB, which holds the largest
body whole, does.

Milliseconds for one `exploreInvalid()` call with 30 cases on a `POST` whose
flat generated body has that many properties (one-off, a single run each):

| Body properties | Schemas checked | They hold | Before | After, 16 MB | With a 32 MB budget |
|---:|---:|---:|---:|---:|---:|
| 20 | 66 | 4.2 MB | 868 | 137 | not run |
| 40 | 104 | 13.4 MB | 2,630 | 421 | 425 |
| 80 | 107 | 28.4 MB | 4,907 | 3,917 | 865 |

A call that needs more than the budget still gains, and never loses, but does
not get the full saving. The budget is one value,
`SchemaValueValidator::DEFAULT_MAX_CACHED_BYTES`.

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
