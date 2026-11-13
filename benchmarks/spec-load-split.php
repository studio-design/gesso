<?php

declare(strict_types=1);

use Studio\Gesso\Spec\OpenApiSpecLoader;

require \dirname(__DIR__) . '/vendor/autoload.php';

// What a cold load of one spec costs when it is one bundled file and when the
// same spec keeps every component schema in a file of its own, referenced as
// `$ref: ./schemas/Name.json` (issue #728). It only uses the public loader,
// so the same script measures any revision.
$resources = isset($argv[1]) ? \max(1, (int) $argv[1]) : 100;
$rounds = 5;

/**
 * @param callable(string): array{'$ref': string} $ref
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
$build = static function (int $resources, callable $ref): array {
    $schemas = [
        'Owner' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'displayName' => ['type' => 'string']]],
        'Error' => ['type' => 'object', 'required' => ['code', 'message'], 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string']]],
    ];
    $json = static fn(array $schema): array => ['content' => ['application/json' => ['schema' => $schema]]];
    $error = ['description' => 'Error'] + $json($ref('Error'));
    $paths = [];
    for ($i = 0; $i < $resources; $i++) {
        $schemas["Resource{$i}"] = [
            'type' => 'object',
            'required' => ['id', "name{$i}", 'status', 'createdAt'],
            'properties' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                "name{$i}" => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archived']],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'email' => ['type' => ['string', 'null'], 'format' => 'email'],
                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 20],
                'owner' => $ref('Owner'),
            ],
        ];
        $one = ['description' => 'OK'] + $json($ref("Resource{$i}"));
        $id = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]];
        $paths["/v1/resource{$i}"] = [
            'get' => ['responses' => [
                '200' => ['description' => 'OK'] + $json(['type' => 'array', 'items' => $ref("Resource{$i}")]),
                'default' => $error,
            ]],
            'post' => ['requestBody' => ['required' => true] + $json($ref("Resource{$i}")), 'responses' => ['201' => $one, 'default' => $error]],
        ];
        $paths["/v1/resource{$i}/{id}"] = [
            'get' => ['parameters' => $id, 'responses' => ['200' => $one, 'default' => $error]],
            'put' => ['parameters' => $id, 'requestBody' => ['required' => true] + $json($ref("Resource{$i}")), 'responses' => ['200' => $one, 'default' => $error]],
            'delete' => ['parameters' => $id, 'responses' => ['204' => ['description' => 'No content'], 'default' => $error]],
        ];
    }

    return [$paths, $schemas];
};
$document = static fn(array $paths): array => ['openapi' => '3.1.0', 'info' => ['title' => 'bench', 'version' => '1'], 'paths' => $paths];

$dir = \sys_get_temp_dir() . '/gesso-bench-' . \bin2hex(\random_bytes(6));
\mkdir("{$dir}/schemas", recursive: true);

[$paths, $schemas] = $build($resources, static fn(string $name): array => ['$ref' => "#/components/schemas/{$name}"]);
$bundledBytes = \file_put_contents("{$dir}/bundled.json", \json_encode($document($paths) + ['components' => ['schemas' => $schemas]], \JSON_THROW_ON_ERROR));

// From the entry document a schema file is `./schemas/Name.json`; from another
// schema file it is `./Name.json`. Both name the same file.
[$paths] = $build($resources, static fn(string $name): array => ['$ref' => "./schemas/{$name}.json"]);
[, $schemas] = $build($resources, static fn(string $name): array => ['$ref' => "./{$name}.json"]);
\file_put_contents("{$dir}/split.json", \json_encode($document($paths), \JSON_THROW_ON_ERROR));
foreach ($schemas as $name => $schema) {
    \file_put_contents("{$dir}/schemas/{$name}.json", \json_encode($schema, \JSON_THROW_ON_ERROR));
}

OpenApiSpecLoader::configure($dir);

\printf(
    "%d resources (%d operations), cold load, median of %d rounds, PHP %s\n\n",
    $resources,
    $resources * 5,
    $rounds,
    \PHP_VERSION,
);
echo "| Layout | Time (ms) | Peak (MB) | Retained (MB) | Resolved paths |\n|---|---:|---:|---:|---|\n";

$layouts = [
    'bundled' => \sprintf('one file, %d KB', \intdiv((int) $bundledBytes, 1024)),
    'split' => \sprintf('%d schema files', \count($schemas)),
];
foreach ($layouts as $name => $label) {
    $times = [];
    $peak = 0;
    $retained = 0;
    $digest = '';
    for ($round = 0; $round < $rounds; $round++) {
        OpenApiSpecLoader::clearCache();
        \gc_collect_cycles();
        $before = \memory_get_usage();
        \memory_reset_peak_usage();
        $start = \hrtime(true);
        $spec = OpenApiSpecLoader::load($name);
        $times[] = (\hrtime(true) - $start) / 1_000_000;
        $peak = \memory_get_peak_usage() - $before;
        $retained = \memory_get_usage() - $before;
        // Both layouts resolve to the same paths, so the digests must agree,
        // across the layouts and across the revisions being compared.
        $digest = \substr(\hash('sha256', \serialize($spec['paths'])), 0, 12);
        unset($spec);
    }
    \sort($times);
    \printf("| %s, %s | %.1f | %.1f | %.1f | `%s` |\n", $name, $label, $times[\intdiv($rounds, 2)], $peak / 1_048_576, $retained / 1_048_576, $digest);
}

\array_map(\unlink(...), \glob("{$dir}/schemas/*") ?: []);
\rmdir("{$dir}/schemas");
\unlink("{$dir}/bundled.json");
\unlink("{$dir}/split.json");
\rmdir($dir);
