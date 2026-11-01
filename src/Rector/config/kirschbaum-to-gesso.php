<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\Name\RenameClassRector;

// kirschbaum-development/laravel-openapi-validator → Gesso. Only the trait
// changes: withoutValidation(), withoutRequestValidation(),
// withoutResponseValidation(), and skipResponseCode() keep their names.
// What has no 1:1 rewrite is listed in docs/migration/rector.md.
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->ruleWithConfiguration(RenameClassRector::class, [
        'Kirschbaum\OpenApiValidator\ValidatesOpenApiSpec' => 'Studio\Gesso\Laravel\ValidatesOpenApiSchema',
    ]);
};
