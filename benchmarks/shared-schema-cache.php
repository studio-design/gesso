<?php

declare(strict_types=1);

use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

require \dirname(__DIR__) . '/vendor/autoload.php';

// What a suite pays when every test case builds its own validators, as the
// framework adapters do: retained memory after one pass over every response
// schema, then the time for a batch of validations (issue #723). It only uses
// the public validators, so the same script measures any revision.
$resources = isset($argv[1]) ? \max(1, (int) $argv[1]) : 100;
$validations = isset($argv[2]) ? \max(1, (int) $argv[2]) : 1_000;
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
            'parameters' => [
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                ['name' => 'offset', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
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

// Call k alternates a 10-item list with a single item and moves to the next
// resource every two calls, so a batch cycles over every schema of the spec.
$response = static function (OpenApiResponseValidator $validator, int $k) use ($resources, $item, $mustPass): void {
    $i = \intdiv($k, 2) % $resources;
    $result = $k % 2 === 0
        ? $validator->validateWithoutRecording('bench', 'GET', "/v1/resource{$i}", 200, ['data' => \array_map(static fn(int $id): array => $item($i, $id), \range(1, 10)), 'total' => 10], 'application/json')
        : $validator->validateWithoutRecording('bench', 'GET', "/v1/resource{$i}/5", 200, ['data' => $item($i, 5)], 'application/json');
    $mustPass($result->isValid(), 'response');
};
$request = static function (OpenApiRequestValidator $validator, int $k) use ($resources, $item, $mustPass): void {
    $i = \intdiv($k, 2) % $resources;
    $result = $k % 2 === 0
        ? $validator->validateWithoutRecording('bench', 'GET', "/v1/resource{$i}", ['limit' => '10', 'offset' => '0'], [], null)
        : $validator->validateWithoutRecording('bench', 'POST', "/v1/resource{$i}", [], [], $item($i, 5), 'application/json');
    $mustPass($result->isValid(), 'request');
};

\printf(
    "%d resources (%d operations, %d KB spec), %d validations per round, %d rounds, PHP %s\n\n",
    $resources,
    $resources * 5,
    \intdiv((int) $specBytes, 1024),
    $validations,
    $rounds,
    \PHP_VERSION,
);

// Memory first, while nothing has been parsed yet: every response schema of
// the spec once, each through a validator that is dropped straight after.
$distinct = $resources * 2;
\gc_collect_cycles();
$before = \memory_get_usage();
for ($k = 0; $k < $distinct; $k++) {
    $response(new OpenApiResponseValidator($tracker), $k);
}
\gc_collect_cycles();
\printf("Retained after %d distinct response schemas: %.1f MB\n\n", $distinct, (\memory_get_usage() - $before) / 1024 / 1024);

$reused = new OpenApiResponseValidator($tracker);
$scenarios = [
    'Response, new validator each call' => static fn(int $k) => $response(new OpenApiResponseValidator($tracker), $k),
    'Request, new validator each call' => static fn(int $k) => $request(new OpenApiRequestValidator(), $k),
    'Response, one validator reused' => static fn(int $k) => $response($reused, $k),
];

echo "| Scenario | ms per {$validations} validations (median) | slowest round |\n|---|---:|---:|\n";
foreach ($scenarios as $label => $step) {
    $samples = [];
    for ($round = 0; $round < $rounds; $round++) {
        $start = \hrtime(true);
        for ($k = 0; $k < $validations; $k++) {
            $step($k);
        }
        $samples[] = (\hrtime(true) - $start) / 1_000_000;
    }
    \sort($samples);
    \printf("| %s | %.0f | %.0f |\n", $label, $samples[\intdiv($rounds, 2)], $samples[$rounds - 1]);
}
