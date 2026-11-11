# Shared parsed-schema cache benchmark

Measured on 2026-11-11 with PHP 8.5.5 (ARM64, `pcre.jit` off, no opcache) and
opis/json-schema 2.6.0. "Before" is `main` at 0b82e8e, "after" is the change
for issue #723; both ran the same script.

The benchmark generates an OpenAPI 3.0.3 spec with five operations per
resource and validates through the public validators. The framework adapters
build their validators per test case, so the first two scenarios build a new
validator for every call. A batch is 1,000 validations that alternate a
10-item list with a single item and move to the next resource every two
calls. Times are the median of five batches.

## Time

Milliseconds per 1,000 validations.

| Resources | Scenario | Before | After |
|---:|---|---:|---:|
| 100 | Response, new validator each call | 383 | 174 |
| 100 | Request, new validator each call | 176 | 56 |
| 100 | Response, one validator reused | 174 | 170 |
| 400 | Response, new validator each call | 403 | 351 |
| 400 | Request, new validator each call | 197 | 82 |
| 400 | Response, one validator reused | 175 | 321 |

With 100 resources every schema fits in the cache, and a new validator per
call costs the same as one reused validator.

With 400 resources the 800 response schemas need about twice the cache's
budget, and the batch cycles over all of them, which is the worst order for a
cache that drops the least recently used entry: most calls parse again. Two
things follow:

- A new validator per call still gains a little, and never loses: before the
  change it parsed on every call anyway.
- One validator reused for the whole run gets slower. Before, it kept up to
  1,024 schemas of its own for as long as it lived; now it shares the bounded
  cache. A run that keeps a single validator and cycles over more schemas
  than fit pays up to what a new validator per call paid before.

## Retained memory

Memory still held after one pass over every response schema, each through a
validator that is dropped straight after the call, measured after
`gc_collect_cycles()`.

| Resources | Distinct response schemas | Before | After |
|---:|---:|---:|---:|
| 100 | 200 | 2.1 MB | 9.2 MB |
| 400 | 800 | 2.4 MB | 14.7 MB |
| 1,600 | 3,200 | 3.4 MB | 15.5 MB |

Before the change nothing parsed outlives the validator, so the "before"
column is the baseline of the run (the spec and its path matcher). After it,
memory grows by about 35 KB per schema until the budget is reached and then
stays flat: 3,200 schemas retain what 800 do, apart from the larger spec.

## The bound

What a parsed schema retains follows the `serialize()` length of the
converted schema, not the number of schemas. A one-off measurement outside
the script, on generated object schemas from 0.6 KB to 28 KB serialized (flat
and nested up to three levels, OpenAPI 3.0 and 3.1), one opis loader per
schema:

| Validated data | Retained memory ÷ serialized length |
|---|---:|
| Fails at the root, so only the root schema is parsed | 14 to 19 |
| Reaches every property, so every subschema is parsed | 20 to 28 |

A count of entries would therefore bound nothing: 256 schemas of 28 KB would
retain about 195 MB. The cache is bounded by the summed serialized length
instead, at 512 KiB, which puts a full cache at about 14 MB (512 KiB × 28)
however large the individual schemas are. In the benchmark a full cache holds
352 schemas and 12.3 MB (14.7 MB less the 2.4 MB baseline).

The length alone undercounts very small schemas: `{type: integer, const: N}`
serializes to 64 bytes and retains about 3.5 KB. Each schema is therefore
charged 128 bytes on top of its length. With that charge, 20,000 distinct
schemas of this kind leave 2,730 cached and 9.4 MB retained; without it,
8,192 and 28.7 MB.

A budget twice as large does not help the 400-resource rows. With 1 MiB, 704
of the 800 schemas stay cached and 27.4 MB is retained, yet the times are the
same (361 and 324 ms), because a batch that cycles over more schemas than fit
always asks for the one dropped longest ago.

Keeping one opis loader per schema, rather than one for all, costs 0.5 to
1.4 KB per schema (measured on a 30-property schema that retains about 49 KB)
and no measurable time.

Reproduce locally:

```bash
php benchmarks/shared-schema-cache.php 100 1000
php benchmarks/shared-schema-cache.php 400 1000
```

The arguments are the number of resources and the batch size. This is a
focused benchmark of the validators, not an HTTP adapter benchmark.
