<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel\Commands;

use const PATHINFO_FILENAME;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\Cli\StubsCommand;
use Studio\Gesso\Stubs\StubRenderer;

use function config;
use function implode;
use function in_array;
use function is_file;
use function is_string;
use function pathinfo;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * Artisan front end for {@see StubsCommand}: identical behaviour, but the spec
 * path comes from `gesso.spec_base_path` + `gesso.default_spec` so a Laravel
 * user does not have to repeat what the published config already states.
 *
 * @internal Registered by GessoServiceProvider. Use the `openapi:stubs` Artisan
 *           command rather than constructing this class directly.
 */
final class OpenApiStubsCommand extends Command
{
    protected $signature = 'openapi:stubs
        {--spec= : Spec name resolved under gesso.spec_base_path, or a path to a spec file}
        {--coverage= : Coverage JSON (schema_version 3); only uncovered responses are stubbed}
        {--adapter=laravel : Generated test idiom: phpunit, laravel, symfony or pest}
        {--output= : Directory to write into (default: tests/Feature/Contract)}
        {--namespace= : Namespace for the generated classes}
        {--base-class= : Test class the generated classes extend}
        {--dry-run : Report what would be written without writing it}';
    protected $description = 'Write test stubs for the OpenAPI responses no test covers';

    public function handle(Application $application): int
    {
        $adapter = trim((string) $this->option('adapter'));
        if (!in_array($adapter, StubRenderer::ADAPTERS, true)) {
            $this->components->error(sprintf(
                "Unsupported adapter '%s'. Use one of: %s.",
                $adapter,
                implode(', ', StubRenderer::ADAPTERS),
            ));

            return self::INVALID;
        }

        try {
            [$specPath, $specName] = $this->resolveSpec($application);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::INVALID;
        }

        $options = [
            'invalid_options' => [],
            'spec' => $specPath,
            'spec_name' => $specName,
            'adapter' => $adapter,
            'dry_run' => (bool) $this->option('dry-run'),
        ];
        foreach (['coverage', 'output', 'namespace', 'base-class'] as $option) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                $options[str_replace('-', '_', $option)] = trim($value);
            }
        }
        $options['output'] ??= 'tests/Feature/Contract';

        $write = function (string $message): void {
            $this->line(rtrim($message, "\n"));
        };
        $command = new StubsCommand(
            stdoutWriter: $write,
            stderrWriter: $write,
            invocation: 'php artisan openapi:stubs',
        );

        return $command->run($options) === StubsCommand::EXIT_OK ? self::SUCCESS : self::INVALID;
    }

    /**
     * A `--spec` that looks like a path is used as-is; otherwise it is a spec
     * name resolved against `gesso.spec_base_path`, the way every other
     * Laravel entry point resolves one.
     *
     * @return array{string, string}
     */
    private function resolveSpec(Application $application): array
    {
        $spec = trim((string) $this->option('spec'));
        if ($spec === '') {
            $default = config('gesso.default_spec');
            if (!is_string($default) || trim($default) === '') {
                throw new InvalidArgumentException(
                    'No OpenAPI spec selected. Pass --spec or configure gesso.default_spec.',
                );
            }
            $spec = trim($default);
        }

        $basePath = config('gesso.spec_base_path');
        if (!is_string($basePath) || trim($basePath) === '') {
            throw new InvalidArgumentException('gesso.spec_base_path must be a non-empty directory path.');
        }
        $basePath = trim($basePath);
        if (!str_starts_with($basePath, '/')) {
            $basePath = $application->basePath($basePath);
        }

        // A spec name is resolved under spec_base_path first — names carrying a
        // dot (`petstore-3.0`) are common enough that guessing "looks like a
        // filename" from punctuation would misread them as paths.
        if (!str_contains($spec, '/')) {
            foreach (['json', 'yaml', 'yml'] as $extension) {
                $candidate = $basePath . '/' . $spec . '.' . $extension;
                if (is_file($candidate)) {
                    return [$candidate, $spec];
                }
            }
        }

        if (is_file($spec)) {
            return [$spec, pathinfo($spec, PATHINFO_FILENAME)];
        }

        throw new RuntimeException(
            "No spec found for '{$spec}': looked for {$spec}.json / .yaml / .yml under {$basePath}, "
            . 'and for a readable file at that path.',
        );
    }
}
