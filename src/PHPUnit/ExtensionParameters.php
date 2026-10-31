<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use LogicException;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Config\InvalidGessoConfigurationException;
use Studio\Gesso\Internal\Diagnostics;

use function array_key_exists;
use function getcwd;
use function implode;
use function is_array;
use function is_bool;
use function is_file;
use function preg_match;
use function rtrim;
use function sprintf;
use function trim;

/**
 * The extension's settings, read only from `gesso.php` (issue #501). The one
 * phpunit.xml parameter left is `config`; every v2 parameter is FATAL with
 * its `gesso.php` replacement named, so an unmigrated phpunit.xml cannot keep
 * running on defaults. Lists from PHP stay lists: encoding a regex such as
 * `5[0-9]{2,2}` as CSV would change its meaning.
 *
 * @internal PHPUnit configuration boundary.
 */
final readonly class ExtensionParameters
{
    /** v2 parameters with no `gesso.php` successor, and what to do instead. */
    public const REMOVED_WITHOUT_SUCCESSOR = [
        'enum_spec_base_path' => 'delete it; enum bindings resolve against components.schemas of the configured specs',
    ];

    /** @param array<string, bool|float|int|list<string>|string> $shared */
    private function __construct(private array $shared) {}

    public static function load(ParameterCollection $parameters, ?string $directory = null): self
    {
        $directory ??= getcwd() ?: '.';
        $explicit = $parameters->has('config');
        $path = $explicit ? trim($parameters->get('config')) : GessoConfig::FILENAME;

        try {
            self::rejectRemovedParameters($parameters);
            if ($path === '') {
                throw new InvalidGessoConfigurationException('The PHPUnit config parameter must name a gesso.php file.');
            }
            if (preg_match('~^(?:/|[A-Za-z]:[/\\\\]|\\\\\\\\)~', $path) !== 1) {
                $path = rtrim($directory, '/\\') . '/' . $path;
            }
            $config = $explicit || is_file($path) ? GessoConfig::load($path) : GessoConfig::defaults();
        } catch (InvalidGessoConfigurationException $e) {
            Diagnostics::stderr('[Gesso] FATAL: ' . $e->getMessage() . "\n");

            throw $e;
        }

        return new self(ConfigurationBridge::phpunit($config));
    }

    /** @internal Test seam: settings from an already-validated configuration. */
    public static function fromConfig(GessoConfig $config): self
    {
        return new self(ConfigurationBridge::phpunit($config));
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->shared);
    }

    public function get(string $name): string
    {
        $value = $this->shared[$name] ?? throw new LogicException('Unknown extension setting: ' . $name);
        if (is_array($value)) {
            throw new LogicException('Read list setting ' . $name . ' with strings().');
        }

        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @return list<string> */
    public function strings(string $name): array
    {
        $value = $this->shared[$name] ?? null;
        if (!is_array($value)) {
            throw new LogicException('Not a list setting: ' . $name);
        }

        return $value;
    }

    /** Every v2 parameter still in phpunit.xml, reported at once with its replacement. */
    private static function rejectRemovedParameters(ParameterCollection $parameters): void
    {
        $removed = [];
        foreach (ConfigurationBridge::PHPUNIT as $name => [$key]) {
            if ($parameters->has($name)) {
                $removed[] = sprintf("'%s' -> %s", $name, $key);
            }
        }
        foreach (self::REMOVED_WITHOUT_SUCCESSOR as $name => $action) {
            if ($parameters->has($name)) {
                $removed[] = sprintf("'%s' -> %s", $name, $action);
            }
        }
        if ($removed !== []) {
            throw new InvalidGessoConfigurationException(sprintf(
                'Gesso 3.0 reads its settings only from gesso.php; move these phpunit.xml extension parameters '
                . 'there (see UPGRADING.md#configuration): %s.',
                implode(', ', $removed),
            ));
        }
    }
}
