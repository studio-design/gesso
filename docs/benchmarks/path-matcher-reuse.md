# Path matcher reuse benchmark

Measured on 2026-11-11 with PHP 8.5.5 (ARM64, `pcre.jit` off, no opcache). Each
result is the median of five rounds with 2,000 iterations per round.

The benchmark times one operation lookup for one request, the way the Laravel
trait's credential injection performs it. "Before" compiles an
`OpenApiPathMatcher` and derives the spec's server base paths on every
request; "after" reuses both for as long as the spec stays cached.

| Paths | Spec | Before (µs) | After (µs) |
|---:|---|---:|---:|
| 276 | no `servers` | 587.9 | 10.3 |
| 276 | root `servers` base path | 915.4 | 21.7 |
| 86 | no `servers` | 173.5 | 3.5 |
| 86 | root `servers` base path | 237.3 | 7.3 |
| 20 | no `servers` | 38.5 | 1.2 |
| 20 | root `servers` base path | 48.9 | 2.7 |

Deriving the server base paths alone accounts for 144.9 µs (no `servers`) and
467.3 µs (root base path) of the 276-path "before" figures; the rest is
compiling the templates. What remains "after" is building the memo key from
the spec's path list and the match itself.

The request and response validators pay the same "before" cost once per test
case and spec rather than once per request, because they are rebuilt for every
test case; they now share the same compiled matcher.

Reproduce locally:

```bash
php benchmarks/path-matcher-reuse.php 276 2000
```

The generated spec mixes literal, one-parameter, and two-parameter templates
with one or two operations each. This is a focused microbenchmark of the
lookup, not an HTTP adapter benchmark.
