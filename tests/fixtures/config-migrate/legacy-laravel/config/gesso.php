<?php

declare(strict_types=1);

return [
    'default_spec' => 'front',
    'specs' => [],
    'spec_base_path' => env('GESSO_FIXTURE_SPEC_PATH', 'openapi'),
    'strip_prefixes' => ['/api'],
    'route_parity' => [
        'external_operation_ids' => ['healthCheck'],
        'external_openapi_paths' => [],
        'excluded_route_names' => [],
    ],
    'max_errors' => 20,
    'enforce_discriminator' => true,
    'auto_assert' => true,
    'auto_validate_request' => false,
    'auto_inject_dummy_credentials' => false,
    'auto_inject_dummy_bearer' => true,
];
