<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use InvalidArgumentException;
use stdClass;
use Studio\Gesso\Internal\RuntimeExpression;
use Studio\Gesso\Internal\RuntimeExpressionException;
use Studio\Gesso\Internal\RuntimeExpressionFamily;
use Studio\Gesso\Spec\OpenApiRefResolver;
use Studio\Gesso\Validation\Support\ContentTypeMatcher;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function explode;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function rawurldecode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * Checks a whole workflow before anything is dispatched and turns it into an
 * {@see ArazzoWorkflowPlan}. Every problem is collected and reported at once;
 * anything outside the supported subset is an `UnsupportedFeature`, never a
 * silent no-op.
 *
 * @internal Arazzo runner implementation detail; not public API.
 */
final class ArazzoWorkflowPreflight
{
    private const ACCEPTED_FAMILIES = [
        RuntimeExpressionFamily::Url,
        RuntimeExpressionFamily::Method,
        RuntimeExpressionFamily::StatusCode,
        RuntimeExpressionFamily::Request,
        RuntimeExpressionFamily::Response,
        RuntimeExpressionFamily::Inputs,
        RuntimeExpressionFamily::Steps,
    ];
    private const WORKFLOW_OUTPUT_FAMILIES = [RuntimeExpressionFamily::Inputs, RuntimeExpressionFamily::Steps];
    private const WORKFLOW_FIELDS = ['workflowId', 'summary', 'description', 'inputs', 'steps', 'outputs', 'parameters', 'successActions', 'failureActions'];
    private const UNSUPPORTED_WORKFLOW_FIELDS = ['dependsOn'];
    private const STEP_FIELDS = ['stepId', 'description', 'operationId', 'operationPath', 'parameters', 'requestBody', 'successCriteria', 'outputs', 'onSuccess', 'onFailure'];
    private const UNSUPPORTED_STEP_FIELDS = ['workflowId', 'channelPath', 'dependsOn', 'timeout', 'action', 'correlationId'];
    private const TARGET_FIELDS = ['operationId', 'operationPath', 'workflowId', 'channelPath'];
    private const PARAMETER_LOCATIONS = ['path', 'query', 'header', 'cookie'];

    /** @var list<array{0: ArazzoDescriptionReason, 1: string}> */
    private array $problems = [];

    /** @var array<string, int> stepId → position */
    private array $stepPositions = [];

    /** @var array<string, list<string>> stepId → declared output names */
    private array $stepOutputs = [];

    /**
     * @param array<string, ArazzoResolvedSource> $sources
     */
    private function __construct(
        private readonly ArazzoDescription $description,
        private readonly array $sources,
    ) {}

    /**
     * @param array<string, ArazzoResolvedSource> $sources from {@see ArazzoSourceResolver::resolve()}
     *
     * @throws InvalidArazzoDescriptionException listing every problem in the workflow
     */
    public static function check(ArazzoDescription $description, string $workflowId, array $sources): ArazzoWorkflowPlan
    {
        $preflight = new self($description, $sources);
        $plan = $preflight->workflow($workflowId, $description->workflow($workflowId));
        if ($preflight->problems !== []) {
            throw InvalidArazzoDescriptionException::fromProblems($preflight->problems, $description->path);
        }

        return $plan;
    }

    private static function isSelector(mixed $value): bool
    {
        $members = ArazzoDescription::isObject($value) ? ArazzoDescription::members($value) : [];

        return array_key_exists('context', $members) && array_key_exists('selector', $members);
    }

    private static function containsRef(mixed $node): bool
    {
        if (!is_array($node)) {
            return false;
        }

        foreach ($node as $key => $value) {
            if ($key === '$ref' || self::containsRef($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ArazzoAction>
     */
    private function actions(string $at, mixed $node, bool $success, ?int $position): array
    {
        if ($node === null) {
            return [];
        }

        if (!is_array($node) || !array_is_list($node)) {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected a list.', $at);

            return [];
        }

        $actions = [];
        foreach ($node as $index => $action) {
            $actionAt = sprintf('%s[%d]', $at, $index);
            if (!ArazzoDescription::isObject($action)) {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected object.', $actionAt);

                continue;
            }

            $action = ArazzoDescription::members($action);
            if (array_key_exists('reference', $action)) {
                if (array_key_exists('value', $action)) {
                    $this->problem(ArazzoDescriptionReason::InvalidField, '%s.value: only Reusable parameter objects take a value.', $actionAt);
                }

                $action = $this->component($actionAt, $action['reference'], $success ? 'successActions' : 'failureActions');
                if ($action === null) {
                    continue;
                }
            }

            $parsed = $this->action($actionAt, $action, $success, $position);
            if ($parsed === null) {
                continue;
            }

            if (isset($actions[$parsed->name])) {
                $this->problem(ArazzoDescriptionReason::DuplicateIdentifier, "%s.name: '%s' is declared more than once in this list.", $actionAt, $parsed->name);

                continue;
            }

            $actions[$parsed->name] = $parsed;
        }

        return array_values($actions);
    }

    /**
     * @param array<array-key, mixed> $action
     */
    private function action(string $at, array $action, bool $success, ?int $position): ?ArazzoAction
    {
        $this->fields($at, $action, ['name', 'type', 'stepId', 'criteria'], ['workflowId', 'retryAfter', 'retryLimit']);

        $name = $action['name'] ?? null;
        $type = $action['type'] ?? null;
        if (!is_string($name) || $name === '') {
            $this->problem(ArazzoDescriptionReason::MissingRequiredField, '%s.name: a string is required.', $at);

            return null;
        }

        if ($type === 'retry' && !$success) {
            $this->problem(ArazzoDescriptionReason::UnsupportedFeature, "%s.type: 'retry' actions are not supported yet.", $at);

            return null;
        }

        if ($type !== 'end' && $type !== 'goto') {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s.type: must be %s.', $at, $success ? 'end or goto' : 'end, goto or retry');

            return null;
        }

        $stepId = null;
        if ($type === 'goto') {
            if (array_key_exists('workflowId', $action)) {
                return null; // reported as an unsupported field above
            }

            $stepId = $action['stepId'] ?? null;
            if (!is_string($stepId) || $stepId === '') {
                $this->problem(ArazzoDescriptionReason::MissingRequiredField, '%s.stepId: a goto action needs a stepId.', $at);

                return null;
            }

            if (!isset($this->stepPositions[$stepId])) {
                $this->problem(ArazzoDescriptionReason::UnknownStep, "%s.stepId: '%s' names no step in this workflow.", $at, $stepId);

                return null;
            }
        }

        $criteria = array_key_exists('criteria', $action) ? $this->criteria($at . '.criteria', $action['criteria'], $position) : [];

        return new ArazzoAction($name, $type, $stepId, $criteria);
    }

    /**
     * Workflow-level actions apply to every step; a step action with the
     * same name overrides the inherited one in place.
     *
     * @param list<ArazzoAction> $inherited
     * @param list<ArazzoAction> $own
     *
     * @return list<ArazzoAction>
     */
    private function mergeActions(string $workflowAt, array $inherited, array $own, string $stepId, int $position): array
    {
        $merged = [];
        foreach ($inherited as $action) {
            $merged[$action->name] = $action;
        }

        foreach ($own as $action) {
            $merged[$action->name] = $action;
        }

        foreach ($inherited as $action) {
            if ($merged[$action->name] !== $action) {
                continue;
            }

            foreach ($action->criteria as $criterion) {
                foreach ($criterion->expressions() as $expression) {
                    $this->expression(sprintf("%s[%s] (inherited by step '%s')", $workflowAt, $action->name, $stepId), $expression, $position, workflowLevel: false);
                }
            }
        }

        return array_values($merged);
    }

    /**
     * Resolve a Reusable Object `reference` (`$components.<type>.<name>`).
     *
     * @return null|array<array-key, mixed> the referenced component
     */
    private function component(string $at, mixed $reference, string $type): ?array
    {
        try {
            $expression = is_string($reference) ? RuntimeExpression::parse($reference) : null;
        } catch (RuntimeExpressionException $e) {
            $this->problem(ArazzoDescriptionReason::InvalidExpression, '%s.reference: %s', $at, $e->getMessage());

            return null;
        }

        if ($expression === null || $expression->family !== RuntimeExpressionFamily::Components || $expression->name !== $type) {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s.reference: must be $components.%s.<name>.', $at, $type);

            return null;
        }

        $component = ArazzoDescription::members($this->description->components[$type] ?? [])[(string) $expression->member] ?? null;
        if (!ArazzoDescription::isObject($component)) {
            $this->problem(ArazzoDescriptionReason::UnknownComponent, "%s.reference: '%s' names no component.", $at, $expression->raw);

            return null;
        }

        return ArazzoDescription::members($component);
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private function workflow(string $workflowId, array $workflow): ArazzoWorkflowPlan
    {
        $at = sprintf('workflows[%s]', $workflowId);
        $this->fields($at, $workflow, self::WORKFLOW_FIELDS, self::UNSUPPORTED_WORKFLOW_FIELDS);

        $inputsSchema = null;
        if (array_key_exists('inputs', $workflow)) {
            if (self::containsRef($workflow['inputs'])) {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, '%s.inputs: $ref inside inputs is not supported yet.', $at);
            }

            /** @var array<string, mixed> $inputsSchema */
            $inputsSchema = ArazzoDescription::members($workflow['inputs']);
        }

        /** @var list<mixed> $steps validated by ArazzoDescription */
        $steps = $workflow['steps'];
        foreach ($steps as $position => $step) {
            $step = ArazzoDescription::members($step);
            $stepId = (string) $step['stepId'];
            $this->stepPositions[$stepId] = $position;
            $outputs = $step['outputs'] ?? null;
            $this->stepOutputs[$stepId] = ArazzoDescription::isObject($outputs)
                ? array_map('strval', array_keys(ArazzoDescription::members($outputs)))
                : [];
        }

        // Workflow parameters are expression-checked per step, after step
        // overrides, because their availability depends on the step position.
        $workflowParameters = $this->parameters($at . '.parameters', $workflow['parameters'] ?? null, null);
        $workflowActions = [
            $this->actions($at . '.successActions', $workflow['successActions'] ?? null, true, null),
            $this->actions($at . '.failureActions', $workflow['failureActions'] ?? null, false, null),
        ];

        $plans = [];
        foreach ($steps as $position => $step) {
            $plan = $this->step($at, $position, ArazzoDescription::members($step), $workflowParameters, $workflowActions);
            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        $outputs = $this->outputs($at . '.outputs', $workflow['outputs'] ?? null, count($steps), workflowLevel: true);

        return new ArazzoWorkflowPlan($workflowId, $inputsSchema, $plans, $outputs);
    }

    /**
     * @param array<array-key, mixed> $step
     * @param list<array{name: string, in: string, value: mixed}> $workflowParameters
     * @param array{0: list<ArazzoAction>, 1: list<ArazzoAction>} $workflowActions success, failure
     */
    private function step(string $workflowAt, int $position, array $step, array $workflowParameters, array $workflowActions): ?ArazzoStepPlan
    {
        $stepId = (string) $step['stepId'];
        $at = sprintf('%s.steps[%s]', $workflowAt, $stepId);
        $this->fields($at, $step, self::STEP_FIELDS, self::UNSUPPORTED_STEP_FIELDS);

        $target = $this->target($at, $step);
        $parameters = $this->parameters($at . '.parameters', $step['parameters'] ?? null, $position);
        [$contentType, $hasBody, $payload] = $this->requestBody($at . '.requestBody', $step['requestBody'] ?? null, $position, $target[1]['operation'] ?? []);
        $conditions = [];
        if (array_key_exists('successCriteria', $step)) {
            if (ArazzoDescription::isNonEmptyList($step['successCriteria'])) {
                $conditions = $this->criteria($at . '.successCriteria', $step['successCriteria'], $position);
            } else {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s.successCriteria: must be a non-empty list when present.', $at);
            }
        }

        $onSuccess = $this->mergeActions($workflowAt . '.successActions', $workflowActions[0], $this->actions($at . '.onSuccess', $step['onSuccess'] ?? null, true, $position), $stepId, $position);
        $onFailure = $this->mergeActions($workflowAt . '.failureActions', $workflowActions[1], $this->actions($at . '.onFailure', $step['onFailure'] ?? null, false, $position), $stepId, $position);
        $outputs = $this->outputs($at . '.outputs', $step['outputs'] ?? null, $position, workflowLevel: false);

        $merged = [];
        foreach ([...$workflowParameters, ...$parameters] as $parameter) {
            $merged[$parameter['in'] . "\0" . $parameter['name']] = $parameter;
        }

        foreach ($workflowParameters as $inherited) {
            if ($merged[$inherited['in'] . "\0" . $inherited['name']] === $inherited) {
                $this->expressions(sprintf("%s.parameters[%s] (inherited by step '%s')", $workflowAt, $inherited['name'], $stepId), $inherited['value'], $position, recursive: false);
            }
        }

        if ($target === null) {
            return null;
        }

        [$specName, $entry] = $target;

        $operationId = $entry['operation']['operationId'] ?? null;

        return new ArazzoStepPlan(
            $stepId,
            $specName,
            is_string($operationId) ? $operationId : null,
            $entry['method'],
            $entry['path'],
            $entry['operation'],
            array_values($merged),
            $contentType,
            $hasBody,
            $payload,
            $conditions,
            $outputs,
            $onSuccess,
            $onFailure,
        );
    }

    /**
     * @param array<array-key, mixed> $step
     *
     * @return null|array{0: string, 1: array{method: string, path: string, operation: array<string, mixed>}}
     */
    private function target(string $at, array $step): ?array
    {
        $targets = array_values(array_filter(self::TARGET_FIELDS, static fn(string $field): bool => array_key_exists($field, $step)));
        if ($targets === []) {
            $this->problem(ArazzoDescriptionReason::MissingTarget, '%s: exactly one of operationId or operationPath is required.', $at);

            return null;
        }

        if (count($targets) > 1) {
            $this->problem(ArazzoDescriptionReason::AmbiguousTarget, '%s: %s are mutually exclusive.', $at, implode(', ', $targets));

            return null;
        }

        $value = $step[$targets[0]];
        if ($targets[0] === 'operationId') {
            return $this->operationIdTarget($at, $value);
        }

        if ($targets[0] === 'operationPath') {
            return $this->operationPathTarget($at, $value);
        }

        return null; // workflowId / channelPath: already reported as unsupported
    }

    /**
     * @return null|array{0: string, 1: array{method: string, path: string, operation: array<string, mixed>}}
     */
    private function operationIdTarget(string $at, mixed $value): ?array
    {
        if (!is_string($value) || $value === '') {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s.operationId: a string is required.', $at);

            return null;
        }

        if (str_starts_with($value, '$sourceDescriptions.')) {
            try {
                $expression = RuntimeExpression::parse($value);
            } catch (RuntimeExpressionException $e) {
                $this->problem(ArazzoDescriptionReason::InvalidExpression, '%s.operationId: %s', $at, $e->getMessage());

                return null;
            }

            [$sourceName, $operationId] = [(string) $expression->name, (string) $expression->member];
        } elseif ($this->description->nonArazzoSourceCount() > 1) {
            $this->problem(
                ArazzoDescriptionReason::UnqualifiedOperationId,
                "%s.operationId: '%s' must be written \$sourceDescriptions.<name>.%s because the description has more than one source.",
                $at,
                $value,
                $value,
            );

            return null;
        } else {
            [$sourceName, $operationId] = [(string) array_key_first($this->sources), $value];
        }

        $source = $this->source($at . '.operationId', $sourceName);
        if ($source === null) {
            return null;
        }

        $entry = $source->operations->find($operationId);
        if ($entry === null) {
            $this->problem(ArazzoDescriptionReason::OperationNotFound, "%s.operationId: operation '%s' does not exist in source '%s'.", $at, $operationId, $sourceName);

            return null;
        }

        return [$source->specName, $entry];
    }

    /**
     * @return null|array{0: string, 1: array{method: string, path: string, operation: array<string, mixed>}}
     */
    private function operationPathTarget(string $at, mixed $value): ?array
    {
        $m = [];
        if (!is_string($value) || preg_match('/^\{\$sourceDescriptions\.([A-Za-z0-9_-]+)\.url\}#(\/.*)$/sD', $value, $m) !== 1) {
            $this->problem(ArazzoDescriptionReason::InvalidField, "%s.operationPath: must be '{\$sourceDescriptions.<name>.url}#<json-pointer>'.", $at);

            return null;
        }

        $source = $this->source($at . '.operationPath', $m[1]);
        if ($source === null) {
            return null;
        }

        // A URI fragment: percent-decode, then RFC 6901 unescape (same as $ref).
        $segments = array_map(
            static fn(string $segment): string => OpenApiRefResolver::unescapePointerSegment(rawurldecode($segment)),
            explode('/', substr($m[2], 1)),
        );
        $entry = $source->operations->findByPointer($segments);
        if ($entry === null) {
            $this->problem(ArazzoDescriptionReason::OperationNotFound, "%s.operationPath: '%s' does not point at an operation in source '%s'.", $at, $m[2], $m[1]);

            return null;
        }

        return [$source->specName, $entry];
    }

    private function source(string $at, string $name): ?ArazzoResolvedSource
    {
        if (!isset($this->sources[$name])) {
            $this->problem(ArazzoDescriptionReason::UnknownSource, "%s: unknown source description '%s'.", $at, $name);

            return null;
        }

        return $this->sources[$name];
    }

    /**
     * @param ?int $position step position for expression checks; null defers them to each step
     *
     * @return list<array{name: string, in: string, value: mixed}>
     */
    private function parameters(string $at, mixed $node, ?int $position): array
    {
        if ($node === null) {
            return [];
        }

        if (!is_array($node) || !array_is_list($node)) {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected a list.', $at);

            return [];
        }

        $parameters = [];
        foreach ($node as $index => $parameter) {
            $parameterAt = sprintf('%s[%d]', $at, $index);
            if (!ArazzoDescription::isObject($parameter)) {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected object.', $parameterAt);

                continue;
            }

            $parameter = ArazzoDescription::members($parameter);
            if (array_key_exists('reference', $parameter)) {
                $component = $this->component($parameterAt, $parameter['reference'], 'parameters');
                if ($component === null) {
                    continue;
                }

                // A Reusable Object's `value` overrides the component's value.
                if (array_key_exists('value', $parameter)) {
                    $component['value'] = $parameter['value'];
                }

                $parameter = $component;
            }

            $name = $parameter['name'] ?? null;
            $in = $parameter['in'] ?? null;
            if (!is_string($name) || $name === '') {
                $this->problem(ArazzoDescriptionReason::MissingRequiredField, '%s.name: a string is required.', $parameterAt);

                continue;
            }

            if ($in === 'querystring') {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, "%s.in: 'querystring' parameters are not supported yet.", $parameterAt);

                continue;
            }

            if ($in === null) {
                $this->problem(ArazzoDescriptionReason::MissingRequiredField, '%s.in: required for operation steps (path, query, header or cookie).', $parameterAt);

                continue;
            }

            if (!in_array($in, self::PARAMETER_LOCATIONS, true)) {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s.in: must be path, query, header or cookie.', $parameterAt);

                continue;
            }

            if (!array_key_exists('value', $parameter)) {
                $this->problem(ArazzoDescriptionReason::MissingRequiredField, '%s.value: required.', $parameterAt);

                continue;
            }

            if (self::isSelector($parameter['value'])) {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, '%s.value: Selector Object values are not supported yet.', $parameterAt);

                continue;
            }

            if ($position !== null) {
                $this->expressions($parameterAt . '.value', $parameter['value'], $position, recursive: false);
            }

            $key = $in . "\0" . $name;
            if (isset($parameters[$key])) {
                $this->problem(ArazzoDescriptionReason::DuplicateParameter, "%s: parameter '%s' in %s is declared more than once.", $parameterAt, $name, $in);

                continue;
            }

            $parameters[$key] = ['name' => $name, 'in' => $in, 'value' => $parameter['value']];
        }

        return array_values($parameters);
    }

    /**
     * @param array<string, mixed> $operation the target operation, consulted when contentType is omitted
     *
     * @return array{0: ?string, 1: bool, 2: mixed}
     */
    private function requestBody(string $at, mixed $node, int $position, array $operation): array
    {
        if ($node === null) {
            return [null, false, null];
        }

        if (!ArazzoDescription::isObject($node)) {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected object.', $at);

            return [null, false, null];
        }

        $body = ArazzoDescription::members($node);
        $this->fields($at, $body, ['contentType', 'payload'], ['replacements']);

        $contentType = $body['contentType'] ?? null;
        if ($contentType !== null && (!is_string($contentType) || !ContentTypeMatcher::isJsonContentType(ContentTypeMatcher::normalizeMediaType($contentType)))) {
            $this->problem(
                ArazzoDescriptionReason::UnsupportedFeature,
                "%s.contentType: '%s' is not supported yet; only JSON request bodies are.",
                $at,
                is_string($contentType) ? $contentType : get_debug_type($contentType),
            );
        }

        if (!array_key_exists('payload', $body)) {
            return [null, false, null];
        }

        $this->expressions($at . '.payload', $body['payload'], $position, recursive: true);

        return [is_string($contentType) ? $contentType : $this->operationContentType($at, $operation), true, $body['payload']];
    }

    /**
     * Arazzo: an omitted contentType is taken from the target operation.
     *
     * @param array<string, mixed> $operation
     */
    private function operationContentType(string $at, array $operation): string
    {
        $content = $operation['requestBody']['content'] ?? null;
        if (!is_array($content) || $content === []) {
            return 'application/json';
        }

        $declared = ContentTypeMatcher::findJsonContentType($content);
        if ($declared === null) {
            $this->problem(
                ArazzoDescriptionReason::UnsupportedFeature,
                '%s.contentType: omitted, and the target operation accepts no JSON request body (%s); only JSON request bodies are supported.',
                $at,
                implode(', ', array_map('strval', array_keys($content))),
            );

            return 'application/json';
        }

        return str_contains($declared, '*') ? 'application/json' : $declared;
    }

    /**
     * @param ?int $position step position for expression checks; null defers them to each step
     *
     * @return list<ArazzoSimpleCondition>
     */
    private function criteria(string $at, mixed $node, ?int $position): array
    {
        if (!is_array($node) || !array_is_list($node)) {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected a list.', $at);

            return [];
        }

        $conditions = [];
        foreach ($node as $index => $criterion) {
            $criterionAt = sprintf('%s[%d]', $at, $index);
            if (!ArazzoDescription::isObject($criterion)) {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected object.', $criterionAt);

                continue;
            }

            $criterion = ArazzoDescription::members($criterion);
            $type = $criterion['type'] ?? 'simple';
            if (ArazzoDescription::isObject($type)) {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, '%s.type: Expression Type Object criteria are not supported yet.', $criterionAt);
            } elseif (in_array($type, ['regex', 'jsonpath', 'xpath'], true)) {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, "%s.type: '%s' criteria are not supported yet; only simple criteria are.", $criterionAt, (string) $type);
            } elseif ($type !== 'simple') {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s.type: must be simple, regex, jsonpath or xpath.', $criterionAt);
            }

            if (array_key_exists('context', $criterion)) {
                if (!RuntimeExpression::isExpression($criterion['context'])) {
                    $this->problem(ArazzoDescriptionReason::InvalidField, '%s.context: must be a runtime expression.', $criterionAt);
                } elseif ($position !== null) {
                    $this->expressions($criterionAt . '.context', $criterion['context'], $position, recursive: false);
                }
            }

            $condition = $criterion['condition'] ?? null;
            if (!is_string($condition) || $condition === '') {
                $this->problem(ArazzoDescriptionReason::MissingRequiredField, '%s.condition: a non-empty string is required.', $criterionAt);

                continue;
            }

            if ($type !== 'simple') {
                continue; // already reported above
            }

            try {
                $parsed = ArazzoSimpleCondition::parse($condition);
            } catch (InvalidArgumentException $e) {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, '%s.condition: %s', $criterionAt, $e->getMessage());

                continue;
            } catch (RuntimeExpressionException $e) {
                $this->problem(ArazzoDescriptionReason::InvalidExpression, '%s.condition: %s', $criterionAt, $e->getMessage());

                continue;
            }

            $valid = true;
            foreach ($position === null ? [] : $parsed->expressions() as $expression) {
                $valid = $this->expression($criterionAt . '.condition', $expression, $position, workflowLevel: false) && $valid;
            }

            if ($valid) {
                $conditions[] = $parsed;
            }
        }

        return $conditions;
    }

    /**
     * @return array<string, RuntimeExpression>
     */
    private function outputs(string $at, mixed $node, int $position, bool $workflowLevel): array
    {
        if ($node === null) {
            return [];
        }

        if (!ArazzoDescription::isObject($node)) {
            $this->problem(ArazzoDescriptionReason::InvalidField, '%s: expected object.', $at);

            return [];
        }

        $outputs = [];
        foreach (ArazzoDescription::members($node) as $name => $value) {
            $name = (string) $name;
            $outputAt = sprintf('%s.%s', $at, $name);
            if (preg_match('/^[a-zA-Z0-9.\-_]+$/D', $name) !== 1) {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s: output names must match ^[a-zA-Z0-9.\-_]+$.', $outputAt);

                continue;
            }

            if (self::isSelector($value)) {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, '%s: Selector Object outputs are not supported yet.', $outputAt);

                continue;
            }

            if (!RuntimeExpression::isExpression($value) || str_contains((string) $value, '{')) {
                $this->problem(
                    is_string($value) && str_contains($value, '{') ? ArazzoDescriptionReason::UnsupportedFeature : ArazzoDescriptionReason::InvalidField,
                    '%s: outputs must be a single runtime expression (embedded expression strings are not supported).',
                    $outputAt,
                );

                continue;
            }

            try {
                $expression = RuntimeExpression::parse((string) $value);
            } catch (RuntimeExpressionException $e) {
                $this->problem(ArazzoDescriptionReason::InvalidExpression, '%s: %s', $outputAt, $e->getMessage());

                continue;
            }

            if ($this->expression($outputAt, $expression, $position, $workflowLevel)) {
                $outputs[$name] = $expression;
            }
        }

        return $outputs;
    }

    /**
     * Check every runtime expression an authored value would evaluate.
     */
    private function expressions(string $at, mixed $value, int $position, bool $recursive): void
    {
        if (is_string($value)) {
            try {
                $expressions = str_contains($value, '{')
                    ? array_filter(RuntimeExpression::parseEmbedded($value), static fn(RuntimeExpression|string $chunk): bool => $chunk instanceof RuntimeExpression)
                    : (RuntimeExpression::isExpression($value) ? [RuntimeExpression::parse($value)] : []);
            } catch (RuntimeExpressionException $e) {
                $this->problem(ArazzoDescriptionReason::InvalidExpression, '%s: %s', $at, $e->getMessage());

                return;
            }

            foreach ($expressions as $expression) {
                $this->expression($at, $expression, $position, workflowLevel: false);
            }

            return;
        }

        if (!$recursive || (!is_array($value) && !$value instanceof stdClass)) {
            return;
        }

        if (self::isSelector($value)) {
            $this->problem(ArazzoDescriptionReason::UnsupportedFeature, '%s: Selector Objects are not supported yet.', $at);

            return;
        }

        foreach (ArazzoDescription::members($value) as $key => $member) {
            $this->expressions(sprintf('%s/%s', $at, (string) $key), $member, $position, recursive: true);
        }
    }

    private function expression(string $at, RuntimeExpression $expression, int $position, bool $workflowLevel): bool
    {
        $accepted = $workflowLevel ? self::WORKFLOW_OUTPUT_FAMILIES : self::ACCEPTED_FAMILIES;
        if (!in_array($expression->family, $accepted, true)) {
            $this->problem(
                ArazzoDescriptionReason::UnsupportedFeature,
                "%s: '%s' expressions are not supported here (%s).",
                $at,
                $expression->family->value,
                $expression->raw,
            );

            return false;
        }

        if ($expression->family !== RuntimeExpressionFamily::Steps) {
            return true;
        }

        $stepId = (string) $expression->name;
        $target = $this->stepPositions[$stepId] ?? null;
        if ($target === null) {
            $this->problem(ArazzoDescriptionReason::UnknownStep, "%s: '%s' names no step in this workflow.", $at, $expression->raw);

            return false;
        }

        if (!$workflowLevel && $target >= $position) {
            $this->problem(ArazzoDescriptionReason::ForwardStepReference, "%s: '%s' refers to step '%s', which has not run yet.", $at, $expression->raw, $stepId);

            return false;
        }

        if (!in_array((string) $expression->member, $this->stepOutputs[$stepId], true)) {
            $this->problem(ArazzoDescriptionReason::UnknownOutput, "%s: step '%s' declares no output '%s'.", $at, $stepId, (string) $expression->member);

            return false;
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string> $allowed
     * @param list<string> $unsupported
     */
    private function fields(string $at, array $node, array $allowed, array $unsupported): void
    {
        foreach (array_keys($node) as $field) {
            $field = (string) $field;
            if (str_starts_with($field, 'x-') || in_array($field, $allowed, true)) {
                continue;
            }

            if (in_array($field, $unsupported, true)) {
                $this->problem(ArazzoDescriptionReason::UnsupportedFeature, "%s.%s: '%s' is not supported yet.", $at, $field, $field);
            } else {
                $this->problem(ArazzoDescriptionReason::InvalidField, '%s.%s: unknown field.', $at, $field);
            }
        }
    }

    private function problem(ArazzoDescriptionReason $reason, string $format, string ...$values): void
    {
        $this->problems[] = [$reason, sprintf($format, ...$values)];
    }
}
