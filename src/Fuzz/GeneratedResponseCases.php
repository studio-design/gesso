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
     */
    public function __construct(public array $cases)
    {
        if ($cases === []) {
            throw new InvalidArgumentException(
                'GeneratedResponseCases must contain at least one GeneratedResponseCase; an empty SDK exercise would assert nothing.',
            );
        }
    }

    /**
     * @param list<GeneratedResponseCase> $cases
     *
     * @internal Response explorer construction seam; not public API.
     */
    public static function withBeforeEach(array $cases, Closure $beforeEach): self
    {
        $instance = new self($cases);
        GeneratedResponseCasesHookRegistry::set($instance, $beforeEach);

        return $instance;
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
            GeneratedResponseCasesHookRegistry::invoke($this);
            $callback($case);
        }

        return $this;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->cases);
    }
}
