<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Studio\Gesso\Fuzz\SchemaValueValidator;
use WeakReference;

use function array_keys;
use function array_map;
use function array_sum;
use function array_values;
use function gc_collect_cycles;
use function gc_disable;
use function gc_enable;
use function intdiv;
use function json_decode;
use function json_encode;
use function str_repeat;
use function strlen;

class SchemaValueValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaValueValidator::clearCache();
        // A schema is charged the memory a check leaves behind. A collector
        // run in the middle of one would free what earlier tests left and
        // hide that, so it runs now and then stays off.
        gc_collect_cycles();
        gc_disable();
    }

    protected function tearDown(): void
    {
        gc_enable();
        self::setBudget(SchemaValueValidator::DEFAULT_MAX_CACHED_BYTES);
        SchemaValueValidator::clearCache();
        parent::tearDown();
    }

    #[Test]
    public function two_checks_against_the_same_schema_parse_it_once(): void
    {
        $schema = static fn(): array => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]];

        $this->assertTrue(SchemaValueValidator::isValid(['id' => 1], $schema()));
        $parsedByTheFirst = self::cache();
        $this->assertCount(1, $parsedByTheFirst);

        $this->assertFalse(SchemaValueValidator::isValid(['id' => 'one'], $schema()));
        $this->assertCount(1, self::cache());
        [$validator, $parsed] = array_values(self::cache())[0];
        $this->assertSame(array_values($parsedByTheFirst)[0][0], $validator, 'The second check must reuse the validator of the first.');
        $this->assertSame(array_values($parsedByTheFirst)[0][1], $parsed, 'The second check must reuse the schema object of the first.');
    }

    #[Test]
    public function variants_of_a_schema_that_keep_its_id_are_checked_separately(): void
    {
        // What the mutation generator does: the same value against a schema
        // and against that schema without one keyword.
        $root = ['$id' => 'https://example.com/amount', 'type' => 'integer', 'multipleOf' => 5];
        $nested = ['type' => 'object', 'properties' => ['amount' => $root]];

        $this->assertFalse(SchemaValueValidator::isValid(7, $root));
        unset($root['multipleOf']);
        $this->assertTrue(SchemaValueValidator::isValid(7, $root));

        $this->assertFalse(SchemaValueValidator::isValid(['amount' => 7], $nested));
        unset($nested['properties']['amount']['multipleOf']);
        $this->assertTrue(SchemaValueValidator::isValid(['amount' => 7], $nested));
    }

    #[Test]
    public function a_schema_is_charged_the_memory_its_checks_leave_behind(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40]]],
        ];
        $charged = static fn(): int => array_values(self::cache())[0][2];

        // Opis parses a subschema when a value first reaches it: an object
        // without `tags` stops at the root, `tags` goes down to `items`.
        $this->assertTrue(SchemaValueValidator::isValid(['other' => 1], $schema));
        $atTheRoot = $charged();
        $this->assertGreaterThan(4 * strlen((string) json_encode($schema)), $atTheRoot, 'A new schema is charged what parsing it held on to, not its length.');

        $this->assertTrue(SchemaValueValidator::isValid(['tags' => ['a']], $schema));
        $parsed = $charged();
        $this->assertGreaterThan($atTheRoot, $parsed, 'A check that parses more of a cached schema must add to what it is charged.');

        $this->assertTrue(SchemaValueValidator::isValid(['tags' => ['b']], $schema));
        $this->assertLessThan(intdiv($parsed - $atTheRoot, 2), $charged() - $parsed, 'A check that parses nothing new must not be charged for parsing.');
        $this->assertSame($charged(), self::cachedBytes());
    }

    #[Test]
    public function the_least_recently_used_schema_is_dropped_once_the_budget_is_exceeded(): void
    {
        $check = function (string $name): void {
            $this->assertTrue(SchemaValueValidator::isValid($name, ['const' => $name, 'description' => str_repeat('x', 4096)]));
        };
        $cached = static fn(): array => array_map(
            static fn(string $json): string => json_decode($json)->const,
            array_keys(self::cache()),
        );

        // The first checks of a process are also charged the opis classes
        // they load, so the size of one entry is read from a later one.
        $check('warm-up');
        SchemaValueValidator::clearCache();
        $check('a');
        $budget = (int) (3.5 * self::cachedBytes());
        self::setBudget($budget);

        $check('b');
        $check('c');
        $check('a');
        $this->assertSame(['b', 'c', 'a'], $cached());

        $dropped = WeakReference::create(array_values(self::cache())[0][0]);
        $check('d');
        $this->assertSame(['c', 'a', 'd'], $cached(), 'Only the least recently used schema is dropped.');
        gc_collect_cycles();
        $this->assertNull($dropped->get(), 'Dropping an entry must release its opis validator.');

        // A dropped schema is parsed again and checks as before.
        $check('b');
        $this->assertSame(['a', 'd', 'b'], $cached());
        $this->assertLessThanOrEqual($budget, self::cachedBytes());
        $this->assertSame(array_sum(array_map(static fn(array $entry): int => $entry[2], self::cache())), self::cachedBytes());
    }

    #[Test]
    public function a_schema_larger_than_the_budget_is_kept_until_another_one_is_checked(): void
    {
        self::setBudget(1);

        $this->assertTrue(SchemaValueValidator::isValid('ok', ['type' => 'string']));
        $this->assertTrue(SchemaValueValidator::isValid('ok', ['type' => 'string']));
        $this->assertCount(1, self::cache());

        $this->assertTrue(SchemaValueValidator::isValid(1, ['type' => 'integer']));
        $this->assertSame(['{"type":"integer"}'], array_keys(self::cache()));
    }

    #[Test]
    public function a_schema_opis_refuses_is_refused_on_every_check(): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                SchemaValueValidator::isValid('x', ['type' => 'string', 'pattern' => '[']);
                $this->fail("Check {$attempt} must throw.");
            } catch (SchemaException) {
                $this->assertSame([], self::cache());
            }
        }
    }

    #[Test]
    public function a_schema_without_a_dialect_is_still_read_as_2020_12(): void
    {
        // The generators hand over subschemas bare, with no `$schema`. Draft
        // 07 would apply `items` to every element and ignore
        // `dependentSchemas`, and both verdicts would flip.
        foreach ([1, 2] as $_) {
            $this->assertTrue(SchemaValueValidator::isValid([1, 'a'], ['prefixItems' => [['type' => 'integer']], 'items' => ['type' => 'string']]));
            $this->assertFalse(SchemaValueValidator::isValid(['a' => 1], ['dependentSchemas' => ['a' => ['required' => ['b']]]]));
        }
    }

    /**
     * @return array<string, array{Validator, mixed, int}>
     */
    private static function cache(): array
    {
        /** @var array<string, array{Validator, mixed, int}> */
        return (new ReflectionProperty(SchemaValueValidator::class, 'cache'))->getValue();
    }

    private static function cachedBytes(): int
    {
        /** @var int */
        return (new ReflectionProperty(SchemaValueValidator::class, 'cachedBytes'))->getValue();
    }

    private static function setBudget(int $bytes): void
    {
        (new ReflectionProperty(SchemaValueValidator::class, 'maxCachedBytes'))->setValue(null, $bytes);
    }
}
