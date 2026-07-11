# Security Policy

## Supported Versions

Only the latest minor of v1.x receives security fixes. There is no LTS
branch for older minors — upgrade to the latest minor of v1.x to receive
fixes. v0.x is no longer supported now that v1.0 has shipped.

| Version  | Supported |
| -------- | --------- |
| 1.x (latest minor) | ✓ |
| 1.x (older minors) | ✗ — upgrade to the latest minor |
| 0.x      | ✗ — upgrade to v1.x |

## Reporting a Vulnerability

**Do not open a public issue for security-relevant reports.**

Use GitHub's [Private Vulnerability Reporting][pvr] to file the report
privately:

[pvr]: https://github.com/studio-design/gesso/security/advisories/new

What to include:
- Affected version(s)
- Reproduction steps or proof-of-concept
- Impact assessment (data exposure, RCE, DoS, etc.)
- Whether the issue is already public

## Response targets

- Acknowledgement: within 5 business days
- Severity triage: within 10 business days
- Fix for high-severity issues: within 30 days where feasible

We coordinate disclosure timing with the reporter and credit reporters in
release notes (with permission).

## Scope

This is a test-only library — it has no runtime production surface. The
relevant attack vectors are:
- Resolving HTTP(S) `$ref`s (opt-in, off by default) — verify any untrusted
  spec source before enabling `allowRemoteRefs`
- YAML spec loading (opt-in via `symfony/yaml`) — `symfony/yaml` is
  generally safe but spec inputs should still be trusted
- Coverage sidecar files written under `sys_get_temp_dir()` in paratest mode

Issues outside that scope (e.g. opis/json-schema validation behaviour) are
forwarded upstream.
