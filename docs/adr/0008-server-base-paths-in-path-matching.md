# ADR 0008: Server base paths in request-path matching

- Status: Accepted
- Date: 2026-11-05
- Issue: none; owner decision 4 in [the v3 vision](../research/2026-11-01-v3-vision.md#owner-decisions-2026-11-01)
- Related: [ADR 0006](0006-server-base-paths-and-request-path-matching.md),
  [ADR 0007](0007-v3-scope-and-release-window.md)
- Supersedes: ADR 0006 sections 1 and 4

## Context

ADR 0006 kept `servers[].url` out of path matching for three reasons, and
foreclosed making the derivation the meaning of an unset `strip_prefixes`:

1. `servers[].url` and `strip_prefixes` answer different questions, and diverge
   when a gateway terminates the prefix, the harness boots the app at the root,
   or `servers` carries a host and no path.
2. Path Item and Operation `servers` replace the root array, and reading an
   override seemed to need the match it is an input to.
3. Several entries (`/v1`, `/v2`) have no non-arbitrary resolution.

It also counted the change as breaking a covered v2 surface. ADR 0007 lets v3
change verdicts toward spec agreement, and every competitor issue tracker has
the same request (Spectator #88, kirschbaum #13, league #240). The owner decided
on 2026-11-01 that v3 matches `servers[].url` base paths automatically, with
`strip_prefixes` as the override. This ADR states the rules that answer each
reason above.

## Decision

### 1. The path as written is tried first

A request path that matches a template without any removal matches it, as in
v2. Base paths are tried only when it does not. A harness that boots the app at
the root, a gateway that removed the prefix, and a host-only `servers` entry
all keep matching as written, which answers reason 1 without configuration.

### 2. A base path is verified against the template it produced

The candidates are the base paths of every Server Object in the document: the
root, every Path Item, every Operation. A URL's path component is the base
path; `{variable}`s take their required `default`; a path that is empty, `/`,
or still templated declares none. Each candidate that the raw request path
starts with, at a segment boundary (`/api` does not prefix `/api2/pets`), is
removed, and the rest is matched (an exact match leaves the root path `/`).

The match counts only when the template is served under that base path: one of
its operations has the base path among its effective servers — the operation's
`servers`, else the Path Item's, else the root's, each replacing the level
above. Removing first and verifying after is what makes overrides usable,
which answers reason 2. Path matching is method-independent, so a template is
served under every base path any of its operations declares.

### 3. Several base paths resolve, or fail loudly

Every candidate is tried. When the verified matches agree on one template, it
matches, so `/v1` and `/v2` both serve `/pets`. When they name different
templates, nothing matches, and the failure names each base path and the
template it reached, and points at `strip_prefixes`. That answers reason 3
without picking one.

### 4. `strip_prefixes` overrides

A non-empty `spec.strip_prefixes` turns base paths off and works as in v2. An
empty list cannot be told apart from an unset key (`[]` is the default), so it
is not an opt-out; with rule 1 there is no known need for one, and an explicit
key can be added if one appears.

### 5. One reader of `servers` for runtime and route parity

`OpenApiServerBasePaths` reads the document once; the request and response
validators, the explorer, the Laravel credential injection, and
`gesso:routes` route parity all match through it, so a route `gesso:routes`
reports as documented is one the validators match.

## Consequences

A request that failed with path-not-found in v2 because the spec declared its
base path in `servers` validates in v3. A test that asserted that failure must
set `strip_prefixes`, or call the path the spec documents.

The ADR 0006 failure hint stays for the override case: while `strip_prefixes`
is set, a failed match names the server base path that would have matched and
says to add it. Without it, a failed match names a base path whose template's
servers do not declare it, and the ambiguity of rule 3.

Coverage records the template, so `/api/pets` counts as `GET /pets`.
