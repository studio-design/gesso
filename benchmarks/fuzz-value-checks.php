<?php

declare(strict_types=1);

use Studio\Gesso\Fuzz\OpenApiEndpointExplorer;
use Studio\Gesso\Fuzz\SchemaValueValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;

require \dirname(__DIR__) . '/vendor/autoload.php';

// What exploring an operation costs: the first call on it, and a call on an
// operation explored before, then what a sweep over the whole spec retains
// (issue #726). The digest covers every case of the seeded sweep, so two
// revisions that print the same digest generated the same cases. The spec has
// no `date`, `time` or `date-time` property: faker draws those up to the
// current time, so the digest would differ from one run to the next.
$resources = isset($argv[1]) ? \max(1, (int) $argv[1]) : 100;
$cases = isset($argv[2]) ? \max(1, (int) $argv[2]) : 30;
$rounds = 5;
$repeats = 20;

$schemas = [];
$paths = [];
for ($i = 0; $i < $resources; $i++) {
    $schemas["Resource{$i}"] = [
        'type' => 'object',
        'required' => ['id', "name{$i}", 'status', 'reference'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            "name{$i}" => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archived']],
            'reference' => ['type' => 'string', 'format' => 'uuid'],
            'email' => ['type' => 'string', 'format' => 'email', 'nullable' => true],
            'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
            'owner' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'integer'], 'displayName' => ['type' => 'string']],
            ],
        ],
    ];
    $json = static fn(array $schema): array => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
    $ok = ['200' => ['description' => 'OK']];
    $paths["/v1/resource{$i}"] = [
        'get' => [
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                ['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
                ['name' => "sort{$i}", 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['id', 'name', 'reference']]],
                ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 40]],
            ],
            'responses' => $ok,
        ],
        'post' => [
            'requestBody' => $json([
                'type' => 'object',
                'required' => ["name{$i}", 'status'],
                'properties' => [
                    "name{$i}" => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archived']],
                    'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
                ],
            ]),
            'responses' => $ok,
        ],
        'put' => ['requestBody' => $json(['$ref' => "#/components/schemas/Resource{$i}"]), 'responses' => $ok],
    ];
}

$dir = \sys_get_temp_dir() . '/gesso-bench-' . \bin2hex(\random_bytes(6));
\mkdir($dir);
$specBytes = \file_put_contents("{$dir}/bench.json", \json_encode([
    'openapi' => '3.0.3',
    'info' => ['title' => 'bench', 'version' => '1'],
    'paths' => $paths,
    'components' => ['schemas' => $schemas],
], \JSON_THROW_ON_ERROR));
OpenApiSpecLoader::configure($dir);
OpenApiSpecLoader::load('bench');
\unlink("{$dir}/bench.json");
\rmdir($dir);

$scenarios = [
    'explore(), POST with a 4-property body' => static fn(int $i) => OpenApiEndpointExplorer::explore('bench', 'POST', "/v1/resource{$i}", $cases, 1_000 + $i),
    'exploreInvalid(), POST with a 4-property body' => static fn(int $i) => OpenApiEndpointExplorer::exploreInvalid('bench', 'POST', "/v1/resource{$i}", [4], $cases, 1_000 + $i),
    'exploreInvalid(), GET with 4 parameters' => static fn(int $i) => OpenApiEndpointExplorer::exploreInvalid('bench', 'GET', "/v1/resource{$i}", [4], $cases, 1_000 + $i),
    'explore(), PUT with an 8-property nested body' => static fn(int $i) => OpenApiEndpointExplorer::explore('bench', 'PUT', "/v1/resource{$i}", $cases, 1_000 + $i),
    'exploreInvalid(), PUT with an 8-property nested body' => static fn(int $i) => OpenApiEndpointExplorer::exploreInvalid('bench', 'PUT', "/v1/resource{$i}", [4], $cases, 1_000 + $i),
];

// A revision without the cache has nothing to forget, and every call on it is
// a first call.
$forget = static function (): void {
    if (\method_exists(SchemaValueValidator::class, 'clearCache')) {
        SchemaValueValidator::clearCache();
    }
};
$median = static function (array $samples): float {
    \sort($samples);

    return $samples[\intdiv(\count($samples), 2)];
};

\printf(
    "%d resources (%d operations, %d KB spec), %d cases per call, %d rounds, PHP %s\n\n",
    $resources,
    $resources * 3,
    \intdiv((int) $specBytes, 1024),
    $cases,
    $rounds,
    \PHP_VERSION,
);

// Memory first, while nothing has been parsed yet: every scenario once on
// every resource, which is also the seeded sweep the digest is taken from.
$digest = \hash_init('sha256');
\gc_collect_cycles();
$before = \memory_get_usage();
foreach ($scenarios as $explore) {
    for ($i = 0; $i < $resources; $i++) {
        \hash_update($digest, \serialize($explore($i)));
    }
}
\gc_collect_cycles();
\printf("Retained after exploring every operation once: %.1f MB\n", (\memory_get_usage() - $before) / 1024 / 1024);
\printf("Digest of the generated cases: %s\n\n", \hash_final($digest));

echo "| Call | first call on an operation, ms | repeated call, ms |\n|---|---:|---:|\n";
foreach ($scenarios as $label => $explore) {
    $first = [];
    $repeated = [];
    for ($round = 0; $round < $rounds; $round++) {
        $forget();
        $start = \hrtime(true);
        for ($i = 0; $i < $resources; $i++) {
            $explore($i);
        }
        $first[] = (\hrtime(true) - $start) / 1_000_000 / $resources;

        $explore(0);
        $start = \hrtime(true);
        for ($k = 0; $k < $repeats; $k++) {
            $explore(0);
        }
        $repeated[] = (\hrtime(true) - $start) / 1_000_000 / $repeats;
    }
    \printf("| %s | %.2f | %.2f |\n", $label, $median($first), $median($repeated));
}
