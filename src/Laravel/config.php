<?php

declare(strict_types=1);

// Gesso configuration, published by `php artisan vendor:publish --tag=gesso`
// to the application root as gesso.php. The PHPUnit extension, the Laravel
// service provider, the Artisan commands and the CLI all read this one file.
// Every key is optional: an omitted key keeps its documented default. Paths
// resolve against this file's directory. Full key list: docs/configuration.md.
return [
    'spec' => [
        // Directory containing the named spec files (front.json, front.yaml, …).
        'base_path' => 'openapi',
        // Spec used when no #[OpenApiSpec] attribute or openApiSpec() override picks one.
        // 'default' => 'front',
        // Specs PHPUnit loads and reports coverage for; also what gesso:routes compares.
        // 'names' => ['front'],
        // Request-path prefixes stripped before matching OpenAPI paths.
        // 'strip_prefixes' => ['/api'],
    ],

    // 'validation' => [
    //     'max_errors' => 20,
    //     'enforce_discriminator' => true,
    //     'skip_response_codes' => ['5\d\d'],
    //     'skip_request_validation_response_codes' => ['422', '400'],
    // ],

    // 'laravel' => [
    //     'auto_assert' => false,
    //     'auto_validate_request' => false,
    //     // false, true (Bearer and API keys), or 'bearer' (Bearer only).
    //     'auto_inject_dummy_credentials' => false,
    //     'route_parity' => [
    //         'external_operation_ids' => [],
    //         'external_openapi_paths' => [],
    //         'excluded_route_names' => [],
    //     ],
    // ],
];
