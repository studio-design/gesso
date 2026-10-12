<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use RuntimeException;
use Throwable;

use function count;
use function implode;
use function sprintf;

/**
 * Every problem found while loading or preflighting an Arazzo description,
 * reported at once. `reason` is the first problem's reason; `problems` lists
 * each problem prefixed with its location.
 */
final class InvalidArazzoDescriptionException extends RuntimeException
{
    /**
     * @internal Thrown by the Arazzo runner; construct it only in tests.
     *
     * @param list<string> $problems
     */
    public function __construct(
        public readonly ArazzoDescriptionReason $reason,
        public readonly array $problems,
        public readonly ?string $path = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Invalid Arazzo description%s (%d problem%s):' . "\n- %s",
                $path === null ? '' : ' ' . $path,
                count($problems),
                count($problems) === 1 ? '' : 's',
                implode("\n- ", $problems),
            ),
            previous: $previous,
        );
    }

    /**
     * @internal Used by the Arazzo loader and preflight.
     *
     * @param non-empty-list<array{0: ArazzoDescriptionReason, 1: string}> $problems
     */
    public static function fromProblems(array $problems, ?string $path = null): self
    {
        $messages = [];
        foreach ($problems as [, $message]) {
            $messages[] = $message;
        }

        return new self($problems[0][0], $messages, $path);
    }

    /**
     * @internal Used by the Arazzo loader and preflight.
     */
    public static function single(ArazzoDescriptionReason $reason, string $problem, ?string $path = null, ?Throwable $previous = null): self
    {
        return new self($reason, [$problem], $path, $previous);
    }
}
