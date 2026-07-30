# Undocumented response-property detection (`strict_additional_properties`)

JSON Schema treats an object as open when `additionalProperties` is omitted.
That default is useful for schema evolution, but it also means a response can
return fields that are absent from the OpenAPI contract and still pass
validation. Generated clients and API consumers cannot rely on those fields.

`strict_additional_properties` is an opt-in response gate that reports such
fields after ordinary conformance validation succeeds.

## Configuration

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="strict_additional_properties" value="warn"/>
    </bootstrap>
</extensions>
```

| Value | Behaviour |
|---|---|
| `off` | Default. No report and no exit-code change. |
| `warn` | Write one aggregated diagnostic to STDERR and GitHub Step Summary; exit zero. |
| `fail` | Write the same diagnostic and exit non-zero when findings exist. |

Only conformance-passing responses contribute. Skipped or invalid responses do
not create findings.

Each finding names the property, its JSON pointer, the operation/status/content
type that returned it, and the number of observations. Arrays use the same
`[*]` pointer notation as `strict_required`:

```text
[OpenAPI Strict Additional Properties] WARNING: 1 undocumented response property was observed.

  - /items[*]/internal_score (internal_score) — spec 'front', GET /catalog, 200, application/json; observed in 3 response(s)
```

## Declaration rules

At every observed object node, Gesso combines declarations from the effective
schema:

- exact names in `properties` are declared;
- names matching `patternProperties` are declared;
- `allOf` branches are merged into the same effective view;
- an explicit `additionalProperties` keyword means the object intentionally
  documents its open/closed policy, so the dynamic property itself is not
  reported;
- `unevaluatedProperties` has the same effect only when the selected JSON
  Schema dialect supports it. The document-level `jsonSchemaDialect` and local
  `$schema` overrides are honored. Draft 06/07 and OpenAPI 3.0 do not treat it
  as an open policy.

`additionalProperties: false` remains ordinary conformance enforcement: a
response with an extra property fails validation before this gate runs.
`additionalProperties: true` and schema forms document an open object. For a
schema form, Gesso continues walking the dynamic property's value, so an
undeclared nested field can still be reported:

```yaml
type: object
additionalProperties:
  type: object
  properties:
    id: {type: string}
```

Here `user-42` is a documented dynamic key, while
`/user-42/internal_score` is a finding if its value returns that undeclared
field.

When `additionalProperties` and `unevaluatedProperties` occur at the same
schema location, `additionalProperties` evaluates the dynamic property first.
The inspector therefore does not reapply `unevaluatedProperties` to that
property.

`anyOf`, `oneOf`, `if`/`then`/`else`, and `dependentSchemas` nodes are skipped
conservatively when those keywords are active in the selected dialect.
Disjunctions nested inside `allOf` branches trigger the same conservative
skip.
Selecting the effective runtime branch would require retaining branch-level
validator output; guessing could produce false positives.

For arrays, Draft 2020-12 and the OpenAPI base dialect apply each
`prefixItems` schema only to its matching index and apply `items` only after
that prefix. Draft 06/07 and 2019-09 use their dialect-specific `items` tuple
semantics.

## Per-call mode

`strict_additional_properties_per_call=warn` emits an `E_USER_WARNING`
immediately for each passing response with a finding:

```xml
<phpunit failOnWarning="true">
    <extensions>
        <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
            <parameter name="strict_additional_properties_per_call" value="warn"/>
        </bootstrap>
    </extensions>
</phpunit>
```

Per-call mode is warn-only. Use PHPUnit's `failOnWarning="true"` to turn it
into a per-test failure, or use `strict_additional_properties=fail` for the
aggregated run-level gate.

Unlike `strict_required`, this check does not infer an invariant from an
intersection. One observation is enough to prove that a returned field is
undocumented, and partial runs can report the findings they actually observe.

## Interaction with `strict_required`

The two gates catch different omissions and can run together:

- `strict_required` finds a declared property that appears in every response
  but is missing from `required`;
- `strict_additional_properties` finds a returned property absent from both
  `properties` and `patternProperties` when the object has no explicit open
  policy.

For a closed, exact object contract, declare its complete `properties`, add
invariant fields to `required`, and set `additionalProperties: false`.

## Parallel test runners

Workers always export strict additional-properties evaluations and findings in
the coverage sidecar. Evaluate the merged gate after paratest or Pest parallel
runs:

```bash
vendor/bin/gesso coverage:merge \
  --spec-base-path=openapi/bundled \
  --specs=front,admin \
  --strict-additional-properties=fail
```

Fail mode exits non-zero when no worker sidecars exist because the gate cannot
be evaluated. When sidecars do exist, the strict gate is evaluated independently
of whether the requested `--specs` produce any coverage-report rows.

The extension parameter controls sequential PHPUnit. The merge CLI flag
controls the merged parallel gate, so changing warn/fail does not require
rerunning workers.
