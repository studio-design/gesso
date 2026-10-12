<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use InvalidArgumentException;

/**
 * Entry point of the synchronous OpenAPI workflow runner for Arazzo 1.1
 * descriptions. This is not full Arazzo conformance: the supported subset
 * is documented in docs/arazzo.md, and anything outside it fails before
 * any request is dispatched.
 *
 * ```php
 * $result = ArazzoWorkflowRunner::workflow(__DIR__ . '/pet-purchase.arazzo.yaml', 'buyPet')
 *     ->inputs(['name' => 'Rex'])
 *     ->dispatchUsing(fn (ArazzoStepRequest $request) => $this->send($request))
 *     ->run();
 * ```
 */
final class ArazzoWorkflowRunner
{
    private function __construct() {}

    /**
     * Select a workflow. Nothing is read until {@see ArazzoWorkflowExecution::run()}.
     *
     * @param string $path filesystem path of a `.json`, `.yaml` or `.yml` Arazzo description
     */
    public static function workflow(string $path, string $workflowId): ArazzoWorkflowExecution
    {
        if ($path === '' || $workflowId === '') {
            throw new InvalidArgumentException('ArazzoWorkflowRunner::workflow() requires a description path and a workflowId.');
        }

        return new ArazzoWorkflowExecution($path, $workflowId);
    }
}
