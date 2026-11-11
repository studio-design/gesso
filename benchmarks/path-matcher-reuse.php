<?php

declare(strict_types=1);

use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiServerBasePaths;
use Studio\Gesso\Spec\OpenApiSpecLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

// The per-request operation lookup of the Laravel trait's credential
// injection: what it cost when every request compiled the matcher and walked
// the spec for its server base paths, and what it costs with both reused
// (issue #720).
$pathCount = isset($argv[1]) ? max(1, (int) $argv[1]) : 276;
$iterations = isset($argv[2]) ? max(1, (int) $argv[2]) : 2_000;
$rounds = 5;

$operation = ['responses' => ['200' => ['description' => 'OK']]];
$paths = [];
for ($i = 0; count($paths) < $pathCount; $i++) {
    $paths["/v1/resource{$i}"] = ['get' => $operation, 'post' => $operation];
    $paths["/v1/resource{$i}/{id}"] = ['get' => $operation, 'delete' => $operation];
    $paths["/v1/resource{$i}/{id}/items/{itemId}"] = ['get' => $operation];
}
$paths = array_slice($paths, 0, $pathCount, true);

$dir = sys_get_temp_dir() . '/gesso-bench-' . bin2hex(random_bytes(6));
mkdir($dir);
$specs = [
    'no-servers' => ['/v1/resource0', []],
    'root-server' => ['/api/v1/resource0', ['servers' => [['url' => 'https://example.test/api']]]],
];
foreach ($specs as $name => [, $extra]) {
    file_put_contents("{$dir}/{$name}.json", json_encode(
        ['openapi' => '3.1.0', 'info' => ['title' => 'bench', 'version' => '1']] + $extra + ['paths' => $paths],
        JSON_THROW_ON_ERROR,
    ));
}
OpenApiSpecLoader::configure($dir);

$median = static function (callable $step) use ($iterations, $rounds): float {
    $samples = [];
    for ($round = 0; $round < $rounds; $round++) {
        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $step();
        }
        $samples[] = (hrtime(true) - $start) / $iterations / 1_000;
    }
    sort($samples);

    return $samples[intdiv($rounds, 2)];
};

printf("%d paths, %d iterations, median of %d rounds, PHP %s\n\n", $pathCount, $iterations, $rounds, PHP_VERSION);
echo "| Spec | Step | µs per request |\n|---|---|---:|\n";
foreach ($specs as $name => [$requestPath]) {
    $steps = [
        'before: compile per request' => static function () use ($name, $requestPath): void {
            $spec = OpenApiSpecLoader::load($name);
            (new OpenApiPathMatcher(array_keys($spec['paths']), [], OpenApiServerBasePaths::fromSpec($spec)))->match($requestPath);
        },
        'after: reuse' => static function () use ($name, $requestPath): void {
            $spec = OpenApiSpecLoader::load($name);
            OpenApiPathMatcher::shared(array_keys($spec['paths']), [], OpenApiSpecLoader::serverBasePaths($name))->match($requestPath);
        },
        '(of "before": server base paths)' => static function () use ($name): void {
            OpenApiServerBasePaths::fromSpec(OpenApiSpecLoader::load($name));
        },
    ];
    foreach ($steps as $label => $step) {
        printf("| %s | %s | %.1f |\n", $name, $label, $median($step));
    }
    unlink("{$dir}/{$name}.json");
}
rmdir($dir);
