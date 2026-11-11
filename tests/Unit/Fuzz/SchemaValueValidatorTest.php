<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use ReflectionProperty;
use Studio\Gesso\Fuzz\SchemaValueValidator;
use WeakReference;

use function array_keys;
use function array_map;
use function array_values;
use function gc_collect_cycles;
use function intdiv;
use function json_decode;
use function str_repeat;

class SchemaValueValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SchemaValueValidator::clearCache();
    }

    protected function tearDown(): void
    {
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
        $this->assertSame($parsedByTheFirst, self::cache(), 'The second check must reuse the validator and schema object of the first.');
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
    public function the_least_recently_used_schema_is_dropped_once_the_budget_is_exceeded(): void
    {
        $budget = (new ReflectionClassConstant(SchemaValueValidator::class, 'MAX_CACHED_BYTES'))->getValue();
        $this->assertIsInt($budget);
        // Three of these fit in the budget, four do not.
        $check = function (string $name) use ($budget): void {
            $this->assertTrue(SchemaValueValidator::isValid($name, ['const' => $name, 'description' => str_repeat('x', intdiv($budget, 4))]));
        };
        $cached = static fn(): array => array_map(
            static fn(string $json): string => json_decode($json)->const,
            array_keys(self::cache()),
        );

        $check('a');
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
        $this->assertLessThanOrEqual($budget, (new ReflectionProperty(SchemaValueValidator::class, 'cachedBytes'))->getValue());
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
     * @return array<string, array{Validator, mixed}>
     */
    private static function cache(): array
    {
        /** @var array<string, array{Validator, mixed}> */
        return (new ReflectionProperty(SchemaValueValidator::class, 'cache'))->getValue();
    }
}
