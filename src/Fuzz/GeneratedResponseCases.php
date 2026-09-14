<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use ArrayIterator;
use Closure;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * Non-empty iterable collection returned by {@see OpenApiResponseExplorer}.
 *
 * @implements IteratorAggregate<int, GeneratedResponseCase>
 */
final readonly class GeneratedResponseCases implements Countable, IteratorAggregate
{
    /**
     * @param list<GeneratedResponseCase> $cases
     * @param null|Closure(): void $beforeEach Explorer hook run before each callback in {@see each()}.
     */
    public function __construct(public array $cases, private ?Closure $beforeEach = null)
    {
        if ($cases === []) {
            throw new InvalidArgumentException(
                'GeneratedResponseCases must contain at least one GeneratedResponseCase; an empty SDK exercise would assert nothing.',
            );
        }
    }

    /**
     * Serialization drops the explorer hook: it only records SDK exercise
     * coverage on the current process's tracker, and a copy handed to another
     * process (a PHPUnit data provider feeding a #[RunInSeparateProcess]
     * test) iterated without it before the hook lived on the object too.
     *
     * @return array{cases: list<GeneratedResponseCase>}
     */
    public function __serialize(): array
    {
        return ['cases' => $this->cases];
    }

    /**
     * @param array{cases: list<GeneratedResponseCase>} $data
     */
    public function __unserialize(array $data): void
    {
        $this->cases = $data['cases'];
        $this->beforeEach = null;
    }

    public function count(): int
    {
        return count($this->cases);
    }

    /**
     * @param callable(GeneratedResponseCase): mixed $callback
     */
    public function each(callable $callback): self
    {
        foreach ($this->cases as $case) {
            if ($this->beforeEach !== null) {
                ($this->beforeEach)();
            }
            $callback($case);
        }

        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->cases);
    }
}
