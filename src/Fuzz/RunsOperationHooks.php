<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * Per-operation lifecycle hooks shared by the whole-spec exploration plan and
 * the contract-check plan: `setUp` and `authenticate` run before an
 * operation's dispatches, `tearDown` always runs after them. Extracted so the
 * two plans cannot drift apart on hook ordering.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
trait RunsOperationHooks
{
    /** @var null|callable(ExploredOperation): void */
    private $authenticate;

    /** @var null|callable(ExploredOperation): void */
    private $setUp;

    /** @var null|callable(ExploredOperation): void */
    private $tearDown;

    /** @param callable(ExploredOperation): void $callback */
    public function authenticateUsing(callable $callback): self
    {
        $this->authenticate = $callback;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function setUpUsing(callable $callback): self
    {
        $this->setUp = $callback;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function tearDownUsing(callable $callback): self
    {
        $this->tearDown = $callback;

        return $this;
    }

    /**
     * Run `$body` after the setUp (and, when `$authenticate`, the
     * authenticate) hook; the tearDown hook runs even when a hook or the
     * body throws.
     *
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    private function runWithOperationHooks(ExploredOperation $operation, bool $authenticate, callable $body): mixed
    {
        try {
            if ($this->setUp !== null) {
                ($this->setUp)($operation);
            }
            if ($authenticate && $this->authenticate !== null) {
                ($this->authenticate)($operation);
            }

            return $body();
        } finally {
            if ($this->tearDown !== null) {
                ($this->tearDown)($operation);
            }
        }
    }
}
