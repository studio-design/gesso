# Schema location cache benchmark

Measured on 2026-11-11 with PHP 8.5.5 (ARM64, `pcre.jit` off, no opcache) and
opis/json-schema 2.6.0. "Before" is `main` at bf69a99, "after" is the change
for issue #724; both ran the same script.

The benchmark generates an OpenAPI 3.0.3 spec with five operations per
resource and validates through the public validators, building a new
validator for every call as the framework adapters do per test case. Before
the timed batches it validates every schema of the spec once, so the batches
measure a repeat validation. Times are the median of five batches of 1,000
calls.

## Time

Microseconds per call.

| Resources | Scenario | Before | After |
|---:|---|---:|---:|
| 100 | Response, 10-item list body, one operation | 264 | 221 |
| 100 | Response, single-item body, one operation | 81 | 43 |
| 100 | Request, 4 query and 2 header parameters, one operation | 63 | 39 |
| 100 | Request, JSON body, one operation | 76 | 40 |
| 100 | Response, 10-item list body, cycling over every resource | 267 | 222 |
| 100 | Request, 4 query and 2 header parameters, cycling over every resource | 67 | 39 |
| 400 | Response, 10-item list body, one operation | 288 | 242 |
| 400 | Response, single-item body, one operation | 104 | 66 |
| 400 | Request, 4 query and 2 header parameters, one operation | 87 | 59 |
| 400 | Request, JSON body, one operation | 97 | 60 |
| 400 | Response, 10-item list body, cycling over every resource | 453 | 439 |
| 400 | Request, 4 query and 2 header parameters, cycling over every resource | 212 | 207 |

A repeat validation no longer converts the schema, turns it into objects and
fingerprints it. That is about 40 µs for a body schema and about 25 µs for six
parameters, whatever the size of the body being validated.

The saving needs the parsed schema to still be cached. With 400 resources the
schemas a batch cycles over do not fit in the budget of the parsed-schema
cache (see [the shared parsed-schema cache benchmark](shared-schema-cache.md)),
so most calls of the two cycling rows find their schema dropped, convert and
parse it again, and gain next to nothing. Parsing is what those rows pay for.

## Retained memory

Memory still held after one pass over every schema of the spec, measured
after `gc_collect_cycles()`.

| Resources | Before | After |
|---:|---:|---:|
| 100 | 13.4 MB | 13.5 MB |
| 400 | 13.4 MB | 14.0 MB |

The difference is the map from locations to cached schemas. A one-off
measurement outside the script, emptying only that map after the pass:

| Resources | Locations remembered | Retained by the map |
|---:|---:|---:|
| 100 | 900 | 0.10 MB |
| 400 | 3,600 | 0.63 MB |
| 1,600 | 4,096 | 0.87 MB |

The map is capped at 4,096 locations, dropping the oldest first, so 0.87 MB
is what a full one retains. Its keys are an xxh128 hash of the location:
keeping the locations themselves retained 1.5 MB for the 3,600 of the
400-resource run, with the same times.

Reproduce locally:

```bash
php benchmarks/schema-location-cache.php 100 1000
php benchmarks/schema-location-cache.php 400 1000
```

The arguments are the number of resources and the batch size. This is a
focused benchmark of the validators, not an HTTP adapter benchmark.
