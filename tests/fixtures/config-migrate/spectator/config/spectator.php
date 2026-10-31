<?php

declare(strict_types=1);

return [
    'default' => env('SPEC_SOURCE', 'local'),
    'sources' => [
        'local' => [
            'source' => 'local',
            'base_path' => env('SPEC_PATH', 'specs'),
        ],
    ],
    'path_prefix' => 'v1',
    'error_format' => env('SPECTATOR_ERROR_FORMAT', 'json'),
    'middleware_groups' => ['api'],
];
