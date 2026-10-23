<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\OperationIndex;

use function file_get_contents;
use function json_decode;

final class OperationIndexTest extends TestCase
{
    #[Test]
    public function operations_are_found_by_id_and_by_pointer(): void
    {
        $index = OperationIndex::build('whole-spec-exploration', $this->spec('whole-spec-exploration'));

        $create = $index->find('createPet');
        $this->assertNotNull($create);
        $this->assertSame('POST', $create['method']);
        $this->assertSame('/pets', $create['path']);
        $this->assertSame('createPet', $create['operation']['operationId']);

        $copy = $index->find('copyPets');
        $this->assertNotNull($copy);
        $this->assertSame('COPY', $copy['method']);

        $this->assertSame($create, $index->findByPointer(['paths', '/pets', 'post']));
        $this->assertSame($copy, $index->findByPointer(['paths', '/pets', 'additionalOperations', 'COPY']));
        $this->assertNull($index->findByPointer(['paths', '/pets', 'additionalOperations', 'copy']));
        $this->assertNull($index->findByPointer(['paths', '/pets', 'POST']));
        $this->assertNull($index->findByPointer(['paths', '/missing', 'get']));
        $this->assertNull($index->findByPointer(['components', 'schemas']));
        $this->assertNull($index->find('nope'));
    }

    #[Test]
    public function duplicate_operation_ids_fail_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate operationId 'dup'");

        OperationIndex::build('duplicate-operation-ids', $this->spec('duplicate-operation-ids'));
    }

    #[Test]
    public function malformed_paths_fail_through_the_shared_preflight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OperationIndex::build('broken', ['openapi' => '3.1.0', 'paths' => ['/a' => 'nope']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(string $name): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/specs/' . $name . '.json'), true);
    }
}
