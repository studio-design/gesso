<?php

declare(strict_types=1);

use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

require \dirname(__DIR__) . '/vendor/autoload.php';

// What one validation costs once its schemas have been seen: per call, for a
// response body, a request with several parameters and a request body, then
// for batches that cycle over every resource of the spec (issue #724). It only
// uses the public validators, so the same script measures any revision.
$resources = isset($argv[1]) ? \max(1, (int) $argv[1]) : 100;
$calls = isset($argv[2]) ? \max(1, (int) $argv[2]) : 1_000;
$rounds = 5;

$schemas = [];
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
    $ref = ['$ref' => "#/components/schemas/Resource{$i}"];
    $json = static fn(array $schema): array => ['content' => ['application/json' => ['schema' => $schema]]];
    $one = ['description' => 'OK'] + $json(['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $ref]]);
    $id = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]];
    $paths["/v1/resource{$i}"] = [
        'get' => [
            // Six parameters, each with a schema no other resource shares.
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100 + $i]],
                ['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10_000 + $i]],
                ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['draft', 'active', "archived{$i}"]]],
                ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['id', '-id', "name{$i}"]]],
                ['name' => 'X-Request-Id', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid', 'maxLength' => 36 + $i]],
                ['name' => 'X-Tenant', 'in' => 'header', 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64 + $i]],
            ],
            'responses' => ['200' => ['description' => 'OK'] + $json([
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => ['type' => 'array', 'items' => $ref], 'total' => ['type' => 'integer']],
            ])],
        ],
        'post' => ['requestBody' => ['required' => true] + $json($ref), 'responses' => ['201' => $one]],
    ];
    $paths["/v1/resource{$i}/{id}"] = [
        'get' => ['parameters' => $id, 'responses' => ['200' => $one]],
        'put' => ['parameters' => $id, 'requestBody' => ['required' => true] + $json($ref), 'responses' => ['200' => $one]],
        'delete' => ['parameters' => $id, 'responses' => ['204' => ['description' => 'No content']]],
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

$tracker = new StrictRequiredTracker();
$tracker->setRecording(false);
StrictAdditionalPropertiesTracker::current()->setRecording(false);

$item = static fn(int $i, int $id): array => [
    'id' => $id,
    "name{$i}" => "Resource {$id}",
    'status' => 'active',
    'createdAt' => '2026-11-11T00:00:00Z',
    'email' => null,
    'score' => 42.5,
    'tags' => ['a', 'b'],
    'owner' => ['id' => 7, 'displayName' => 'Ada'],
];
$mustPass = static function (bool $valid, string $what): void {
    if (!$valid) {
        throw new RuntimeException("The benchmark {$what} did not validate.");
    }
};

// The adapters build their validators per test case, so every call does too.
$listResponse = static function (int $i) use ($tracker, $item, $mustPass): void {
    $body = ['data' => \array_map(static fn(int $id): array => $item($i, $id), \range(1, 10)), 'total' => 10];
    $mustPass((new OpenApiResponseValidator($tracker))->validateWithoutRecording('bench', 'GET', "/v1/resource{$i}", 200, $body, 'application/json')->isValid(), 'list response');
};
$itemResponse = static function (int $i) use ($tracker, $item, $mustPass): void {
    $mustPass((new OpenApiResponseValidator($tracker))->validateWithoutRecording('bench', 'GET', "/v1/resource{$i}/5", 200, ['data' => $item($i, 5)], 'application/json')->isValid(), 'item response');
};
$parameterRequest = static function (int $i) use ($mustPass): void {
    $mustPass((new OpenApiRequestValidator())->validateWithoutRecording(
        'bench',
        'GET',
        "/v1/resource{$i}",
        ['limit' => '10', 'offset' => '0', 'status' => 'active', 'sort' => '-id'],
        ['X-Request-Id' => '0b0e6b1c-6f3a-4a52-9d5e-2a7d0c1f4e11', 'X-Tenant' => 'acme'],
        null,
    )->isValid(), 'parameter request');
};
$bodyRequest = static function (int $i) use ($item, $mustPass): void {
    $mustPass((new OpenApiRequestValidator())->validateWithoutRecording('bench', 'POST', "/v1/resource{$i}", [], [], $item($i, 5), 'application/json')->isValid(), 'body request');
};

\printf(
    "%d resources (%d operations, %d KB spec), %d calls per round, %d rounds, PHP %s\n\n",
    $resources,
    $resources * 5,
    \intdiv((int) $specBytes, 1024),
    $calls,
    $rounds,
    \PHP_VERSION,
);

// Memory first, while nothing has been validated yet: every schema of the
// spec once.
\gc_collect_cycles();
$before = \memory_get_usage();
for ($i = 0; $i < $resources; $i++) {
    $listResponse($i);
    $itemResponse($i);
    $parameterRequest($i);
    $bodyRequest($i);
}
\gc_collect_cycles();
\printf("Retained after one pass over every schema: %.1f MB\n\n", (\memory_get_usage() - $before) / 1024 / 1024);

$scenarios = [
    'Response, 10-item list body, one operation' => static fn(int $k) => $listResponse(0),
    'Response, single-item body, one operation' => static fn(int $k) => $itemResponse(0),
    'Request, 4 query and 2 header parameters, one operation' => static fn(int $k) => $parameterRequest(0),
    'Request, JSON body, one operation' => static fn(int $k) => $bodyRequest(0),
    'Response, 10-item list body, cycling over every resource' => static fn(int $k) => $listResponse($k % $resources),
    'Request, 4 query and 2 header parameters, cycling over every resource' => static fn(int $k) => $parameterRequest($k % $resources),
];

echo "| Scenario | µs per call (median) | slowest round |\n|---|---:|---:|\n";
foreach ($scenarios as $label => $step) {
    $samples = [];
    for ($round = 0; $round < $rounds; $round++) {
        $start = \hrtime(true);
        for ($k = 0; $k < $calls; $k++) {
            $step($k);
        }
        $samples[] = (\hrtime(true) - $start) / 1_000 / $calls;
    }
    \sort($samples);
    \printf("| %s | %.0f | %.0f |\n", $label, $samples[\intdiv($rounds, 2)], $samples[$rounds - 1]);
}
