# Response `content: null` consistency design

- Date: 2026-08-03
- Status: Approved for implementation

## Goal

Make response validation and `gesso doctor` agree when an OpenAPI Response
Object explicitly declares `content: null`, while preserving the existing
no-content behavior when the `content` field is omitted.

OpenAPI 3.0 and 3.1 define `content` as a map of media types to Media Type
Objects, and OpenAPI 3.2 defines it as a map of media types to Media Type or
Reference Objects. None permits `null` as the value of the fixed field:

- [OpenAPI 3.0.4 Response Object](https://spec.openapis.org/oas/v3.0.4.html#response-object)
- [OpenAPI 3.1.1 Response Object](https://spec.openapis.org/oas/v3.1.1.html#response-object)
- [OpenAPI 3.2.0 Response Object](https://spec.openapis.org/oas/v3.2.0.html#response-object)

## Current inconsistency

`ResponseSchemaResolver` uses `isset()` to test for `content`. PHP therefore
treats an explicitly null value as absent, and runtime response validation
returns the normal no-content resolution.

`DoctorCommand` uses an explicit key-presence check before validating the node.
It therefore reports `content: null` as malformed. The same document currently
passes one path and fails the other.

## Decision

Use field presence, rather than non-nullness, to distinguish an omitted
`content` field from an invalid declared value at the runtime resolution
boundary.

| Response Object shape | Runtime resolution |
| --- | --- |
| `content` omitted | `NoContent` |
| `content: null` | `MalformedContent` |
| scalar or list-shaped `content` | `MalformedContent` (unchanged) |
| map-shaped `content` | Existing media-type resolution behavior |

The doctor remains unchanged because its strict behavior already matches the
OpenAPI field type. Schema loading must not normalize explicit null to absence;
doing so would erase the distinction needed for useful malformed-spec
diagnostics.

## Implementation

Change only the response resolver's initial `content` presence check. Once an
explicit null value reaches the existing malformed-node guard, the resolver
will return `MalformedContent` through its current diagnostic path. Omitted
`content` continues to return `NoContent` before that guard.

No new public symbol, exception type, CLI option, wire-format field, or
production dependency is introduced. The observable change applies only to an
invalid OpenAPI document shape.

## Tests

Follow test-driven development:

1. Add a focused resolver regression test proving that explicit `content: null`
   resolves to `MalformedContent`.
2. Add or extend a public response-validator test proving that the malformed
   node becomes a validation failure rather than a no-content success.
3. Retain the existing doctor test for `content: null` as the other side of the
   consistency contract.
4. Run the focused resolver, response-validator, and doctor tests, followed by
   the repository CI command.

Assertions should verify the result kind and useful location context without
coupling to incidental third-party validator prose.

## Documentation and compatibility

No user guide change is required: `content: null` is not valid OpenAPI, and
valid no-content responses remain represented by omitting `content`. This
design document records the intentional malformed-spec behavior change.

## Non-goals

- Do not change request-body handling or unrelated malformed-node boundaries.
- Do not change empty-map or media-type selection behavior.
- Do not address the separately identified nullable-enum exploration issue.
- Do not change doctor diagnostics beyond preserving their current strictness.
