<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel\Commands;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use JsonException;
use Studio\Gesso\Laravel\RouteParity\LaravelRouteParityAnalyzer;
use Studio\Gesso\Laravel\RouteParity\RouteParityResult;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function preg_match;
use function str_starts_with;
use function trim;

final class OpenApiRoutesCommand extends Command
{
    protected $signature = 'openapi:routes
        {--spec=* : Spec name to compare; repeat for multiple specs}
        {--prefix= : Only include Laravel routes under this URI prefix}
        {--middleware=* : Only include routes using all of these middleware names}
        {--domain=* : Only include routes registered for these exact domains}
        {--exclude-route=* : Exclude route names; * wildcards are supported}
        {--format=text : Output format: text or json}
        {--fail-on-undocumented : Fail when a Laravel route is absent from OpenAPI}
        {--fail-on-unimplemented : Fail when an OpenAPI operation has no Laravel route}';
    protected $description = 'Compare registered Laravel routes with OpenAPI operations';

    public function handle(
        Application $application,
        Router $router,
        LaravelRouteParityAnalyzer $analyzer,
    ): int {
        $format = trim((string) $this->option('format'));
        if (!in_array($format, ['text', 'json'], true)) {
            $this->components->error("Unsupported format '{$format}'. Use text or json.");

            return self::INVALID;
        }

        try {
            $specNames = $this->specNames();
            [$basePath, $stripPrefixes] = $this->loaderConfiguration($application);
            OpenApiSpecLoader::configure($basePath, $stripPrefixes);

            $specs = [];
            foreach ($specNames as $specName) {
                $specs[$specName] = OpenApiSpecLoader::load($specName);
            }

            $result = $analyzer->analyze(
                $specs,
                $router->getRoutes(),
                $stripPrefixes,
                [
                    'prefix' => $this->nullableStringOption('prefix'),
                    'middleware' => $this->stringListOption('middleware'),
                    'domains' => $this->stringListOption('domain'),
                    'excluded_route_names' => $this->stringListOption('exclude-route'),
                ],
            );
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($format === 'json') {
            try {
                $this->line((string) json_encode(
                    $result,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ));
            } catch (JsonException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->renderText($result);
        }

        if ($this->option('fail-on-undocumented') &&
            ($result->registeredButUndocumented !== [] || $result->unsupported !== [])) {
            return self::FAILURE;
        }

        if ($this->option('fail-on-unimplemented') && $result->documentedButNotRegistered !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function specNames(): array
    {
        $specs = $this->stringListOption('spec');
        if ($specs !== []) {
            return $specs;
        }

        $defaultSpec = config('gesso.default_spec');
        if (!is_string($defaultSpec) || trim($defaultSpec) === '') {
            throw new InvalidArgumentException(
                'No OpenAPI spec selected. Pass --spec or configure gesso.default_spec.',
            );
        }

        return [trim($defaultSpec)];
    }

    /** @return array{string, list<string>} */
    private function loaderConfiguration(Application $application): array
    {
        $basePath = config('gesso.spec_base_path');
        if (!is_string($basePath) || trim($basePath) === '') {
            throw new InvalidArgumentException(
                'gesso.spec_base_path must be a non-empty directory path.',
            );
        }

        $stripPrefixes = config('gesso.strip_prefixes', []);
        if (!is_array($stripPrefixes)) {
            throw new InvalidArgumentException('gesso.strip_prefixes must be an array of strings.');
        }

        $normalizedPrefixes = [];
        foreach ($stripPrefixes as $prefix) {
            if (!is_string($prefix) || trim($prefix) === '') {
                throw new InvalidArgumentException(
                    'gesso.strip_prefixes must contain only non-empty strings.',
                );
            }
            $normalizedPrefixes[] = $prefix;
        }

        $basePath = trim($basePath);
        if (!$this->isAbsolutePath($basePath)) {
            $basePath = $application->basePath($basePath);
        }

        return [$basePath, $normalizedPrefixes];
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') ||
            str_starts_with($path, '\\\\') ||
            preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function nullableStringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @return list<string> */
    private function stringListOption(string $name): array
    {
        $value = $this->option($name);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $entry): string => is_string($entry) ? trim($entry) : '',
            $value,
        ), static fn(string $entry): bool => $entry !== ''));
    }

    private function renderText(RouteParityResult $result): void
    {
        $this->components->info('OpenAPI route parity');
        $this->line('Specs: ' . implode(', ', $result->specs));
        $this->newLine();
        $this->table(
            ['Matched', 'OpenAPI only', 'Laravel only', 'Ambiguous', 'Unsupported'],
            [[
                count($result->matched),
                count($result->documentedButNotRegistered),
                count($result->registeredButUndocumented),
                count($result->ambiguous),
                count($result->unsupported),
            ]],
        );

        if ($result->documentedButNotRegistered !== []) {
            $this->components->warn('Documented but not registered');
            $this->table(['Spec', 'Method', 'OpenAPI path', 'operationId'], array_map(
                static fn(array $entry): array => [
                    $entry['spec'],
                    $entry['method'],
                    $entry['openapi_path'],
                    $entry['operation_id'] ?? '',
                ],
                $result->documentedButNotRegistered,
            ));
        }

        if ($result->registeredButUndocumented !== []) {
            $this->components->warn('Registered but undocumented');
            $this->table(['Method', 'Laravel URI', 'Name', 'Domain'], array_map(
                static fn(array $entry): array => [
                    $entry['method'],
                    $entry['route_uri'],
                    $entry['route_name'] ?? '',
                    $entry['domain'] ?? '',
                ],
                $result->registeredButUndocumented,
            ));
        }

        if ($result->ambiguous !== []) {
            $this->components->warn('Ambiguous routes');
            $this->table(['Kind', 'Method', 'Laravel URI', 'Name', 'Domain'], array_map(
                static fn(array $entry): array => [
                    $entry['kind'],
                    $entry['method'] ?? '',
                    $entry['route_uri'],
                    $entry['route_name'] ?? '',
                    $entry['domain'] ?? '',
                ],
                $result->ambiguous,
            ));
        }

        if ($result->unsupported !== []) {
            $this->components->warn('Unsupported route methods');
            $this->table(['Method', 'Laravel URI', 'Name', 'Reason'], array_map(
                static fn(array $entry): array => [
                    $entry['method'],
                    $entry['route_uri'],
                    $entry['route_name'] ?? '',
                    $entry['reason'],
                ],
                $result->unsupported,
            ));
        }
    }
}
