# Laravel route parity

`openapi:routes` compares Laravel's registered routes with the operations in
one or more OpenAPI specs. It catches drift before runtime coverage can: a
documented operation may have no application route, or an application route
may never have been documented.

Route parity complements contract coverage. Parity checks that a route and an
operation exist; runtime coverage still proves that tests exercised the
operation and validated its responses.

## Configuration

Publish the Laravel config if you have not already:

```bash
php artisan vendor:publish --tag=gesso
```

Configure the spec directory, default spec, and the same prefixes used by the
PHPUnit extension:

```php
return [
    'default_spec' => 'front',
    'spec_base_path' => base_path('openapi/bundled'),
    'strip_prefixes' => ['/api'],
    'route_parity' => [
        'external_operation_ids' => ['forms.*'],
        'external_openapi_paths' => ['/v2/proxy/*'],
    ],
];
```

`spec_base_path` is the directory searched for `front.json`, `front.yaml`, or
`front.yml`. Keep `strip_prefixes` aligned with the PHPUnit extension. The
command applies the runtime matcher's first-match prefix and trailing-slash
rules, so the static result matches request/response validation.

## Usage

Use the configured `default_spec`:

```bash
php artisan openapi:routes
```

Select multiple specs and narrow the Laravel route collection:

```bash
php artisan openapi:routes \
  --spec=front \
  --spec=admin \
  --prefix=api/v2 \
  --middleware=api \
  --domain=api.example.com \
  --exclude-route='internal.*' \
  --exclude-operation='forms.*' \
  --exclude-openapi-path='/v2/proxy/*'
```

Filters are combined with AND semantics:

- `--prefix` matches a complete Laravel URI prefix segment.
- Repeat `--middleware` to require every listed middleware name.
- Repeat `--domain` to allow any listed exact route domain.
- Repeat `--exclude-route` to exclude named routes; `*` wildcards are
  supported. Unnamed routes are not excluded by this option.

Documented-side exclusions classify operations implemented by another service
in a gateway topology. Repeat `--exclude-operation` to match `operationId`, or
`--exclude-openapi-path` to match the OpenAPI path template. Both support `*`
wildcards and are merged with `route_parity.external_operation_ids` and
`route_parity.external_openapi_paths` from the Laravel config. An exclusion is
applied only when the operation has no matching Laravel route: implemented
operations remain in `matched`. External operations are reported separately
instead of being silently removed.

Laravel parameter names do not need to equal OpenAPI parameter names:
`/pets/{pet}` matches `/pets/{petId}`. A trailing optional Laravel parameter
is compared in both forms, so `/users/{user?}` can implement both `/users` and
`/users/{userId}`. Laravel's implicit `HEAD` on a `GET` route is ignored when
the spec omits `head`, but it implements and matches an explicitly documented
OpenAPI `head` operation on the same path.

Fallback routes are reported as ambiguous because they cannot prove that one
specific OpenAPI path is implemented. A custom HTTP method is supported when
the selected OpenAPI 3.2 spec declares the same, case-sensitive key under
`additionalOperations`; otherwise it is reported as unsupported.

## CI exit codes

By default, discovered differences are reported but the command exits `0`.
Enable either gate independently:

```bash
php artisan openapi:routes --fail-on-undocumented
php artisan openapi:routes --fail-on-unimplemented
```

- `--fail-on-undocumented` exits `1` for registered-but-undocumented routes or
  unsupported route methods.
- `--fail-on-unimplemented` exits `1` for documented-but-not-registered
  operations, excluding those classified under `external_operations`.
- Invalid command options exit `2`; load/configuration failures exit `1`.

## JSON output

Use stable machine-readable output in CI:

```bash
php artisan openapi:routes --format=json
```

For large applications, capture the formatted JSON as a CI artifact instead
of relying only on the job log:

```yaml
- name: Compare Laravel routes with OpenAPI
  run: php artisan openapi:routes --format=json > route-parity.json

- name: Upload route parity report
  uses: actions/upload-artifact@v4
  with:
    name: openapi-route-parity
    path: route-parity.json
```

The top-level `schema_version` is currently `2`. Version 2 adds the
`external_operations` result and summary count. The payload contains the
selected `specs`, a `summary`, and these result arrays:

- `matched`
- `documented_but_not_registered`
- `external_operations`
- `registered_but_undocumented`
- `ambiguous`
- `unsupported`

Paths and route names are emitted, but absolute filesystem paths and spec
contents are not.
