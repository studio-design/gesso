# Laravel route parity

`gesso:routes` compares Laravel's registered routes with the operations in
one or more OpenAPI specs. It catches drift before runtime coverage can: a
documented operation may have no application route, or an application route
may never have been documented.

Route parity complements contract coverage. Parity checks that a route and an
operation exist; runtime coverage still proves that tests exercised the
operation and validated its responses.

## Configuration

Configure the spec directory, default spec, and the same prefixes used by the
PHPUnit extension in the root [`gesso.php`](configuration.md), which both
adapters read:

```php
return [
    'spec' => [
        'base_path' => 'openapi/bundled',
        'default' => 'front',
        'names' => ['front', 'admin'],
        'strip_prefixes' => ['/api'],
    ],
    'laravel' => [
        'route_parity' => [
            'external_operation_ids' => ['forms.*'],
            'external_openapi_paths' => ['/v2/proxy/*'],
            'excluded_route_names' => ['internal.*'],
        ],
    ],
];
```

`spec.base_path` is the directory searched for `front.json`, `front.yaml`, or
`front.yml`, relative to `gesso.php`. Because PHPUnit reads the same
`spec.strip_prefixes`, static parity and runtime validation agree. The
command applies the runtime matcher's first-match prefix and trailing-slash
rules, so the static result matches request/response validation. The flat
keys of a published `config/gesso.php` (`default_spec`, `specs`,
`spec_base_path`, `strip_prefixes`, `route_parity`) still work but are
deprecated since 2.9.0 and removed in 3.0.

## Usage

Use the configured `spec.names`, or `spec.default` when the list is empty:

```bash
php artisan gesso:routes
```

`--spec` replaces the configured `spec.names` list rather than adding to it.

Select multiple specs and narrow the Laravel route collection:

```bash
php artisan gesso:routes \
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
  supported. Values are merged with `laravel.route_parity.excluded_route_names`
  from `gesso.php`. Unnamed routes are not excluded.

Documented-side exclusions classify operations implemented by another service
in a gateway topology. Repeat `--exclude-operation` to match `operationId`, or
`--exclude-openapi-path` to match the OpenAPI path template. Both support `*`
wildcards and are merged with `laravel.route_parity.external_operation_ids` and
`laravel.route_parity.external_openapi_paths` from `gesso.php`. An exclusion is
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
With the specs and exclusions in config, a CI step needs no wrapper script:

```bash
php artisan gesso:routes --format=json --fail-on-undocumented
```

Enable either gate independently:

```bash
php artisan gesso:routes --fail-on-undocumented
php artisan gesso:routes --fail-on-unimplemented
```

- `--fail-on-undocumented` exits `1` for registered-but-undocumented routes or
  unsupported route methods.
- `--fail-on-unimplemented` exits `1` for documented-but-not-registered
  operations, excluding those classified under `external_operations`.
- Invalid command options exit `2`; load/configuration failures exit `1`.

## JSON output

Use stable machine-readable output in CI:

```bash
php artisan gesso:routes --format=json
```

For large applications, capture the formatted JSON as a CI artifact instead
of relying only on the job log:

```yaml
- name: Compare Laravel routes with OpenAPI
  run: php artisan gesso:routes --format=json > route-parity.json

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
