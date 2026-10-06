<?php

declare(strict_types=1);

return [
    'spec' => [
        'base_path' => 'openapi',
        'default' => 'api',
        'names' => ['api'],
        'strip_prefixes' => ['/api'],
    ],
    'coverage' => ['report_output' => ['json' => 'build/coverage.json']],
];
