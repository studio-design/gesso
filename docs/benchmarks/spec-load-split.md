# Split spec load benchmark

Measured on 2026-11-13 with PHP 8.5.5 (ARM64, `pcre.jit` off, no opcache).
"Before" is `main` at bac424c, "after" is the change for issue #728; both ran
the same script.

The benchmark generates an OpenAPI 3.1 spec with five operations per resource
and loads it cold, once as one bundled file and once with every component
schema in a file of its own, referenced as `$ref: ./schemas/Name.json`. Each
resource schema is referenced from six places, a shared error schema from
every operation, and a nested schema from every resource schema. Times are the
median of five loads; the run was repeated three times and the middle run is
shown.

| Operations | Layout | Time before (ms) | after | Peak before (MB) | after | Retained before (MB) | after |
|---:|---|---:|---:|---:|---:|---:|---:|
| 500 | bundled, 224 KB | 14.7 | 15.5 | 8.6 | 8.6 | 3.6 | 3.6 |
| 500 | split, 102 schema files | 23.7 | 20.3 | 12.2 | 7.6 | 7.7 | 3.1 |
| 2,000 | bundled, 898 KB | 71.5 | 71.6 | 34.4 | 34.4 | 14.4 | 14.4 |
| 2,000 | split, 402 schema files | 114.7 | 89.1 | 48.8 | 30.3 | 30.8 | 12.3 |

"Retained" is what the loaded spec holds for the rest of the process. Before
the change a split spec retained about twice what the same spec retained
bundled, because every reference to a schema file walked the file again and
kept its own copy. A file is now walked once and shared by every reference to
it, as a `#/components/...` target already was.

The split spec now retains less than the bundled one: the bundled document
also keeps its `components` section, which accounts for the 0.5 MB and 2.1 MB
between the two. It stays slower to load, since it still reads and decodes one
file per schema.

The script prints a digest of the resolved `paths`. It was the same for both
layouts and on both revisions.

Reproduce locally:

```bash
php benchmarks/spec-load-split.php 100
php benchmarks/spec-load-split.php 400
```

The argument is the number of resources. This is a focused benchmark of the
loader, not of a test run.
