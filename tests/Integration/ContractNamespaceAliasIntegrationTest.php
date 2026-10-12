<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill_keys;
use function array_map;
use function fclose;
use function file_put_contents;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Runs src/Contract/Autoload.php in a fresh process so the per-process
 * deprecation dedup and the "nothing aliased at bootstrap" state are observable.
 */
final class ContractNamespaceAliasIntegrationTest extends TestCase
{
    private const array SHORT_NAMES = [
        'ContractCheck',
        'ContractCheckPlan',
        'ContractCheckProbe',
        'ContractCheckFailure',
        'ContractCheckSkip',
        'ContractCheckSummary',
        'OpenApiContractChecks',
    ];
    private const string CONSUMER = <<<'PHP'
        <?php

        declare(strict_types=1);

        require $argv[1];

        $notices = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$notices): bool {
            if ($errno !== E_USER_DEPRECATED) {
                return false;
            }
            $notices[] = $errstr;

            return true;
        });

        $legacy = [];
        foreach (['ContractCheck', 'ContractCheckPlan', 'ContractCheckProbe', 'ContractCheckFailure', 'ContractCheckSkip', 'ContractCheckSummary', 'OpenApiContractChecks'] as $name) {
            $legacy[$name] = 'Studio\\Gesso\\Fuzz\\' . $name;
        }

        $out = ['lazy' => [], 'resolved' => [], 'reflection' => []];
        foreach ($legacy as $name => $fqcn) {
            $out['lazy'][$name] = class_exists($fqcn, false);
        }
        $out['deprecations_before'] = count($notices);

        foreach ($legacy as $name => $fqcn) {
            $out['resolved'][$name] = class_exists($fqcn);
            $out['reflection'][$name] = (new ReflectionClass($fqcn))->getName();
        }

        $out['identity'] = \Studio\Gesso\Fuzz\ContractCheck::IgnoredAuth === \Studio\Gesso\Contract\ContractCheck::IgnoredAuth;
        $out['value'] = \Studio\Gesso\Fuzz\ContractCheck::IgnoredAuth->value;

        foreach ($legacy as $fqcn) {
            class_exists($fqcn);
        }

        $out['untouched'] = [
            'explored_case' => class_exists('Studio\\Gesso\\Fuzz\\ExploredCase'),
            'unknown' => class_exists('Studio\\Gesso\\Fuzz\\ContractCheckNope'),
        ];
        $out['notices'] = $notices;
        $out['adapters_loaded'] = [
            'laravel' => trait_exists('Studio\\Gesso\\Laravel\\ValidatesOpenApiSchema', false),
            'symfony' => trait_exists('Studio\\Gesso\\Symfony\\OpenApiAssertions', false),
            'pest' => class_exists('Studio\\Gesso\\Pest\\Expectations', false),
        ];

        echo json_encode($out, JSON_THROW_ON_ERROR);
        PHP;
    private ?string $script = null;

    protected function tearDown(): void
    {
        if ($this->script !== null) {
            unlink($this->script);
            $this->script = null;
        }

        parent::tearDown();
    }

    #[Test]
    public function legacy_fuzz_names_resolve_lazily_through_one_notice_each(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'gesso-contract-alias-');
        $this->assertIsString($script);
        $this->script = $script;
        $this->assertNotFalse(file_put_contents($script, self::CONSUMER));

        $root = __DIR__ . '/../..';
        $process = proc_open(
            [PHP_BINARY, $script, $root . '/vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        $this->assertTrue(is_resource($process));

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $this->assertSame(0, proc_close($process), $stderr . $stdout);
        $this->assertSame('', $stderr);

        $out = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_fill_keys(self::SHORT_NAMES, false), $out['lazy']);
        $this->assertSame(0, $out['deprecations_before']);
        $this->assertSame(array_fill_keys(self::SHORT_NAMES, true), $out['resolved']);

        $canonical = [];
        foreach (self::SHORT_NAMES as $name) {
            $canonical[$name] = 'Studio\\Gesso\\Contract\\' . $name;
        }
        $this->assertSame($canonical, $out['reflection']);

        $this->assertTrue($out['identity']);
        $this->assertSame('ignored_auth', $out['value']);
        $this->assertSame(['explored_case' => true, 'unknown' => false], $out['untouched']);

        $this->assertSame(
            array_map(
                static fn(string $name): string => sprintf(
                    '[Gesso deprecation] Studio\\Gesso\\Fuzz\\%1$s is deprecated. Use Studio\\Gesso\\Contract\\%1$s instead. It is removed in Gesso 3.0. See UPGRADING.md#deprecations.',
                    $name,
                ),
                self::SHORT_NAMES,
            ),
            $out['notices'],
        );

        $this->assertSame(['laravel' => false, 'symfony' => false, 'pest' => false], $out['adapters_loaded']);
    }
}
