<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Studio\Gesso\Cli\ConfigMigrateCommand;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Artisan front end for {@see ConfigMigrateCommand}. It runs with the
 * application booted, so a v2 `config/gesso.php` that calls `env()`,
 * `base_path()` or another Laravel helper can be evaluated — which the plain
 * `gesso config:migrate` cannot do. {@see GessoServiceProvider}
 * skips its removed-key check for this one command so it can boot.
 *
 * @internal Registered by GessoServiceProvider. Use the `gesso:config-migrate` Artisan command.
 */
final class GessoConfigMigrateCommand extends Command
{
    public const NAME = 'gesso:config-migrate';
    protected $signature = 'gesso:config-migrate
        {--write : Write the proposal to gesso.php instead of printing it}
        {--force : Let --write replace a non-empty gesso.php}
        {--check : Print only the report; exit 1 while anything is left to migrate}
        {--format=text : Report format: text or json}';
    protected $description = 'Move v2 or competitor validator settings to gesso.php';

    public function handle(Application $application): int
    {
        $command = new ConfigMigrateCommand(
            stdoutWriter: function (string $message): void {
                $this->output->write($message, false, OutputInterface::OUTPUT_RAW);
            },
            stderrWriter: function (string $message): void {
                $this->output->getErrorStyle()->write($message, false, OutputInterface::OUTPUT_RAW);
            },
            invocation: 'php artisan ' . self::NAME,
            projectDir: $application->basePath(),
        );

        return $command->run([
            'write' => (bool) $this->option('write'),
            'force' => (bool) $this->option('force'),
            'check' => (bool) $this->option('check'),
            'format' => (string) $this->option('format'),
        ]);
    }
}
