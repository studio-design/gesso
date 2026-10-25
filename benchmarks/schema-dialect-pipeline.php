<?php

declare(strict_types=1);

use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

require dirname(__DIR__) . '/vendor/autoload.php';

$iterations = isset($argv[1]) ? max(1, (int) $argv[1]) : 10_000;
$rounds = 5;
$data = ObjectConverter::convert([
    'id' => 42,
    'name' => 'Ada',
    'creditCard' => '4111111111111111',
    'cvv' => '123',
]);

$schemas = [
    'oas30-draft07' => [
        OpenApiVersion::V3_0,
        [
            'type' => 'object',
            'required' => ['id', 'name'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'creditCard' => ['type' => 'string'],
                'cvv' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
            'if' => ['required' => ['creditCard']],
            'then' => ['required' => ['cvv']],
        ],
    ],
    'oas31-native-2020-12' => [
        OpenApiVersion::V3_1,
        [
            'type' => 'object',
            'required' => ['id', 'name'],
            'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'creditCard' => ['type' => 'string'],
                'cvv' => ['type' => 'string'],
            ],
            'unevaluatedProperties' => false,
            'dependentRequired' => ['creditCard' => ['cvv']],
        ],
    ],
];

$results = [];
foreach ($schemas as $name => [$version, $schema]) {
    $conversionSamples = [];
    $validationSamples = [];

    for ($round = 0; $round < $rounds; $round++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            OpenApiSchemaConverter::convert($schema, $version);
        }
        $conversionSamples[] = (hrtime(true) - $start) / 1_000_000;

        $converted = ObjectConverter::convert(OpenApiSchemaConverter::convert($schema, $version));
        $runner = new SchemaValidatorRunner(20);
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            if ($runner->validateStructured($converted, $data) !== []) {
                throw new RuntimeException("Benchmark schema '{$name}' rejected the valid fixture.");
            }
        }
        $validationSamples[] = (hrtime(true) - $start) / 1_000_000;
    }

    sort($conversionSamples);
    sort($validationSamples);
    $middle = intdiv($rounds, 2);
    $results[$name] = [
        'conversion_ms' => round($conversionSamples[$middle], 2),
        'validation_ms' => round($validationSamples[$middle], 2),
        'validation_ops_per_second' => round($iterations / ($validationSamples[$middle] / 1_000), 0),
    ];
}

echo json_encode([
    'php' => PHP_VERSION,
    'iterations' => $iterations,
    'rounds' => $rounds,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
