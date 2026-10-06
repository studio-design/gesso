<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use LogicException;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Config\InvalidGessoConfigurationException;

use function array_key_exists;
use function array_map;
use function explode;
use function getcwd;
use function is_array;
use function is_bool;
use function is_file;
use function preg_match;
use function rtrim;
use function trim;

/**
 * V2 parameters override declared shared settings. Lists from PHP stay lists:
 * encoding a regex such as `5[0-9]{2,2}` as CSV would change its meaning.
 *
 * @internal PHPUnit configuration boundary.
 */
final readonly class ExtensionParameters
{
    /** @param array<string, bool|float|int|list<string>|string> $shared */
    private function __construct(private ParameterCollection $legacy, private array $shared) {}

    public static function load(ParameterCollection $legacy, ?string $directory = null): self
    {
        $directory ??= getcwd() ?: '.';
        $explicit = $legacy->has('config');
        $path = $explicit ? trim($legacy->get('config')) : GessoConfig::FILENAME;

        try {
            if ($path === '') {
                throw new InvalidGessoConfigurationException('The PHPUnit config parameter must name a gesso.php file.');
            }
            if (preg_match('~^(?:/|[A-Za-z]:[/\\\\]|\\\\\\\\)~', $path) !== 1) {
                $path = rtrim($directory, '/\\') . '/' . $path;
            }
            $config = $explicit || is_file($path) ? GessoConfig::load($path) : GessoConfig::defaults();
        } catch (InvalidGessoConfigurationException $e) {
            OpenApiCoverageExtension::writeStderr('[Gesso] FATAL: ' . $e->getMessage() . "\n");

            throw $e;
        }

        return new self($legacy, ConfigurationBridge::phpunit($config));
    }

    public function has(string $name): bool
    {
        return $this->legacy->has($name) || array_key_exists($name, $this->shared);
    }

    public function get(string $name): string
    {
        if ($this->legacy->has($name)) {
            return $this->legacy->get($name);
        }
        $value = $this->shared[$name] ?? throw new LogicException('Unknown extension parameter: ' . $name);
        if (is_array($value)) {
            throw new LogicException('Read list parameter ' . $name . ' with strings().');
        }

        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @return list<string> */
    public function strings(string $name): array
    {
        if ($this->legacy->has($name)) {
            return array_map('trim', explode(',', $this->legacy->get($name)));
        }
        $value = $this->shared[$name] ?? null;
        if (!is_array($value)) {
            throw new LogicException('Not a list parameter: ' . $name);
        }

        return $value;
    }
}
