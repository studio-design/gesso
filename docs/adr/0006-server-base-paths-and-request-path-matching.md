# ADR 0006: Server base paths and request-path matching

- Status: Accepted
- Date: 2026-08-07
- Issue: [#514](https://github.com/studio-design/gesso/issues/514)
- Related: [#501](https://github.com/studio-design/gesso/issues/501),
  [#511](https://github.com/studio-design/gesso/issues/511)
- Supersedes: none

## Context

A spec written the normal way declares its base path once:

```json
{
  "servers": [{ "url": "/api" }],
  "paths": { "/pets": { "get": {} } }
}
```

Served by an application mounted at `/api`, a contract test against `/api/pets`
fails out of the box, and the fix is to write `/api` into `strip_prefixes` — a
value the spec has already stated. `servers` is read nowhere in the validation
path; the only `src/` reader is `CoverageGateCommand`, which fingerprints it
per operation so a server change invalidates that operation's coverage.

Nothing in the documentation says either way, and a reader who knows OpenAPI
assumes the opposite. Both [#511](https://github.com/studio-design/gesso/issues/511)
observation runs that touched path matching hit this. The one with
`strip_prefixes` deliberately omitted spent nineteen consecutive source-reading
calls inside `vendor/` before it could state the root cause, then named the gap
unprompted and declined to work around it, on the grounds that deriving the
prefix is the library's job.

Issue #514 put three options on the table: derive the prefix from
`servers[].url` when `strip_prefixes` is unset; keep `strip_prefixes`
authoritative and emit a diagnostic; or collapse the two `strip_prefixes`
surfaces into one.

## Decision

### 1. `servers[].url` does not participate in request-path matching

`strip_prefixes` stays the only input to the matcher's prefix policy. Three
reasons, in the order that decided it:

**The two values answer different questions.** `servers[].url` says where the
published API is served. `strip_prefixes` says where the application under test
mounts its routes. They coincide often enough to look like the same value and
diverge in ordinary deployments: a gateway that terminates the prefix before
the application sees it, a test harness that boots the app at the root, a spec
whose `servers` carries a hostname and no path at all. Deriving one from the
other makes Gesso assert a deployment topology it cannot observe.

**Operation-level `servers` cannot participate, by construction.** `servers` is
overridable on the Path Item and on the Operation Object, and the override
replaces the root array rather than extending it — the rule
`CoverageGateCommand::effectiveServers()` already implements. Reading an
override requires having matched the path; matching the path is what the base
path is needed for. Any derivation could therefore honour root `servers` only,
so it would silently disagree with the document exactly where the document is
most explicit.

**Multiple entries and server variables have no non-arbitrary resolution.**
`[{"url": "/v1"}, {"url": "/v2"}]` is legal; taking the first is a guess, and
taking both makes a request match two operations. `https://{env}.example.com/{basePath}`
needs variable values Gesso does not have. Degrading loudly on each of these
means a warning on specs that are entirely correct.

Compatibility settles what the reasons leave: deriving the prefix changes the
meaning of an unset `strip_prefixes` for every consumer whose spec declares
`servers`, so a suite that fails at path matching today would begin validating
bodies, and a request that matched nothing could start matching an operation.
That is a behaviour change on a covered surface, and it would buy a guess.

### 2. The failure says so (issue #514, option 2)

When a path fails to match and removing a root-declared `servers[].url` base
path would have matched it, the failure names the prefix and points at
`strip_prefixes`:

```text
No matching path found in 'petstore' spec for GET /api/pets
  servers[0].url declares base path '/api'; '/pets' matches after removing it.
  Gesso does not strip server base paths automatically — add '/api' to strip_prefixes.
  closest spec paths:
    - GET /pets
```

Root `servers` only, for the reason in (1): at the point the diagnostic renders,
no operation has been selected. That is a limit on how often the hint fires, not
on whether it is correct — it never fires unless the stripped path matches.

The hint is additive on an already-failing path, so it ships in a v2 minor. It
does not touch `gesso doctor`, which does not match request paths.

### 3. Collapsing the two `strip_prefixes` surfaces is already decided

[ADR 0005](0005-v3-configuration-and-cli-naming.md) fixes `spec.strip_prefixes`
as the single v3 key replacing the PHPUnit parameter and the Laravel config key,
owned by #501. Option 3 needs no decision here.

### 4. Derivation, if it ever ships, is an explicit value

The breaking version is foreclosed: reading `servers[].url` may never become the
meaning of an unset key. If demand appears, it arrives as an explicit
`strip_prefixes` value naming the spec as the source, and it must fail loudly on
each ambiguity in (1) rather than picking one.

## Consequences

`docs/setup.md` states the behaviour and why `strip_prefixes` exists, so the
question is answered before it is asked rather than after nineteen tool calls.

The duplicated value — the same prefix written into `phpunit.xml` and
`config/gesso.php`, with a comment in each telling the reader to keep them
aligned — survives this ADR. #501 removes it; nothing here shortens that wait.

Consumers who expected the spec to configure the prefix get a worse answer than
they hoped for and a faster one than they had: the diagnostic tells them the
exact value to paste, on the first failing run.
