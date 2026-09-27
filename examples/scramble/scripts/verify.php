<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require \dirname(__DIR__) . '/vendor/autoload.php';

$root = \dirname(__DIR__);
$files = new Filesystem();
$workspace = $root . '/build/scenarios';
$files->deleteDirectory($workspace);
$files->makeDirectory($workspace, 0o755, true);

// Keep application code, the generated spec, and normal coverage untouched.
foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests', 'scripts', 'openapi'] as $directory) {
    $files->copyDirectory($root . '/' . $directory, $workspace . '/' . $directory);
}
foreach (['composer.json', 'phpunit.xml.dist', '.env'] as $file) {
    $files->copy($root . '/' . $file, $workspace . '/' . $file);
}
$files->link($root . '/vendor', $workspace . '/vendor');
$files->makeDirectory($workspace . '/build', 0o755, true);
$files->makeDirectory($workspace . '/storage/framework/views', 0o755, true);
$files->makeDirectory($workspace . '/storage/logs', 0o755, true);

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readJson(string $path): array
{
    return \json_decode(\file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
}

function runScenario(string $name, array $command, int $expectedExit = 0): string
{
    global $workspace;

    $process = new Process([\PHP_BINARY, ...$command], $workspace, ['GESSO_VALIDATION_FORMAT' => 'json']);
    $process->setTimeout(120);
    $exit = $process->run();
    $output = $process->getOutput() . $process->getErrorOutput();
    \file_put_contents($workspace . '/build/' . $name . '.log', $output);
    ensure($exit === $expectedExit, "{$name}: expected exit {$expectedExit}, got {$exit}.\n{$output}");
    echo "{$name}: expected exit {$expectedExit} confirmed\n";

    return $output;
}

function checkSuite(string $name, int $tests, int $failures = 0): SimpleXMLElement
{
    global $workspace;

    $report = \simplexml_load_file($workspace . '/build/' . $name . '.xml');
    ensure($report !== false, "{$name}: missing JUnit report");
    ensure(\count($report->xpath('//testcase')) === $tests, "{$name}: unexpected test count");
    ensure(\count($report->xpath('//failure')) === $failures, "{$name}: unexpected failures");
    ensure(\count($report->xpath('//error | //skipped')) === 0, "{$name}: unexpected errors or skipped tests");

    return $report;
}

function checkCoverage(int $covered): void
{
    global $workspace;

    $coverage = readJson($workspace . '/build/coverage.json');
    ensure($coverage['aggregate']['response_total'] === 6, 'Expected six documented responses');
    ensure($coverage['aggregate']['response_covered'] === $covered, 'Unexpected response coverage');
    ensure($coverage['aggregate']['response_skipped'] === 0, 'Responses must be validated, not skipped');
    $missing = [];
    foreach ($coverage['specs']['api']['endpoints'] as $endpoint) {
        foreach ($endpoint['responses'] as $response) {
            if ($response['response_state'] === 'uncovered') {
                $missing[] = [$endpoint['method'], $endpoint['path'], $response['status_key']];
            }
        }
    }
    ensure($missing === ($covered === 6 ? [] : [['POST', '/users', '422']]), 'Unexpected uncovered responses');
}

function replaceOnce(string $path, string $from, string $to): void
{
    $content = \str_replace($from, $to, \file_get_contents($path), $count);
    ensure($count === 1, "Expected exactly one mutation in {$path}");
    \file_put_contents($path, $content);
}

$phpunit = ['vendor/bin/phpunit', '--bootstrap=scripts/scenario-bootstrap.php', '--colors=never'];
runScenario('doctor', ['vendor/bin/gesso', 'doctor', '--spec=openapi/api.json']);
runScenario('full', [...$phpunit, '--log-junit=build/full.xml']);
checkSuite('full', 6);
checkCoverage(6);
\copy($workspace . '/build/coverage.json', $workspace . '/build/coverage-full.json');

// Model a PR adding 422: the controlled base differs by exactly that response.
$base = readJson($workspace . '/openapi/api.json');
ensure(isset($base['paths']['/users']['post']['responses']['422']), 'Expected Scramble to generate 422');
unset($base['paths']['/users']['post']['responses']['422']);
\file_put_contents($workspace . '/openapi/base.json', \json_encode($base, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
$gate = ['vendor/bin/gesso', 'coverage:gate', '--base-spec=openapi/base.json', '--spec=openapi/api.json', '--coverage=build/coverage.json'];
runScenario('gate-covered', $gate);

$resource = $workspace . '/app/Http/Resources/UserResource.php';
replaceOnce($resource, "'id' => (int) \$this->id", "'id' => 'invalid-id'");
runScenario('drift', [...$phpunit, '--log-junit=build/drift.xml'], 1);
$drift = checkSuite('drift', 6, 3);
foreach ($drift->xpath('//failure') as $failure) {
    $text = (string) $failure;
    $start = \strpos($text, "\n{");
    $end = \strrpos($text, "\n}");
    ensure($start !== false && $end !== false, 'Expected a structured Gesso validation failure');
    $result = \json_decode(\substr($text, $start + 1, $end - $start + 1), true, flags: \JSON_THROW_ON_ERROR);
    $issue = $result['issues'][0] ?? [];
    ensure(($issue['category'] ?? null) === 'response.body' &&
        ($issue['instance_path'] ?? null) === '/data/id' &&
        ($issue['keyword'] ?? null) === 'type', 'Expected an id type mismatch');
}
\copy($root . '/app/Http/Resources/UserResource.php', $resource);

// Remove the 422 test from this copy rather than use --filter: Gesso correctly
// refuses to persist coverage reports from partial PHPUnit selections.
replaceOnce(
    $workspace . '/tests/Feature/ContractProbeTest.php',
    'function test_validation_error_matches_generated_contract',
    'function omitted_validation_error_matches_generated_contract',
);
\unlink($workspace . '/build/coverage.json');
runScenario('missing-422', [...$phpunit, '--log-junit=build/missing-422.xml']);
checkSuite('missing-422', 5);
checkCoverage(5);
runScenario('gate-uncovered', $gate, 1);
echo "Verified: six covered responses, response type drift, and an uncovered new 422.\n";
