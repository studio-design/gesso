<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

use InvalidArgumentException;
use JsonException;
use stdClass;
use Studio\Gesso\DecodedBody;
use Studio\Gesso\Internal\ObservedResponse;
use Studio\Gesso\Internal\RequestUriBuilder;
use Studio\Gesso\Internal\RuntimeExpressionContext;
use Studio\Gesso\Internal\RuntimeExpressionEvaluator;
use Studio\Gesso\Internal\RuntimeExpressionException;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationOutcome;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\HeaderNormalizer;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
use Throwable;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function get_object_vars;
use function implode;
use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;
use function preg_match_all;
use function rawurldecode;
use function sprintf;
use function str_contains;

/**
 * One configured run of an Arazzo workflow. For every step, in order: the
 * authored request is evaluated and validated against the source's OpenAPI
 * contract, dispatched through the caller's callback, the response is
 * validated against the same source, then `successCriteria` and `outputs`
 * are evaluated. The first failure stops the run.
 */
final class ArazzoWorkflowExecution
{
    // ponytail: fixed budget, make configurable if a real workflow needs more
    private const MAX_STEP_EXECUTIONS = 1000;

    /** @var array<string, mixed> */
    private array $inputs = [];

    /** @var null|callable(ArazzoStepRequest): mixed */
    private $dispatch;

    /**
     * @internal Use {@see ArazzoWorkflowRunner::workflow()}.
     */
    public function __construct(
        private readonly string $path,
        private readonly string $workflowId,
    ) {}

    /**
     * Workflow inputs, validated against the workflow's `inputs` schema
     * before any step runs.
     *
     * @param array<string, mixed> $inputs
     */
    public function inputs(array $inputs): self
    {
        $this->inputs = $inputs;

        return $this;
    }

    /**
     * Send one step's request and return the response: a PSR-7
     * `ResponseInterface` or `['status' => int, 'headers' => array, 'body' => string]`.
     *
     * @param callable(ArazzoStepRequest): mixed $dispatch
     */
    public function dispatchUsing(callable $dispatch): self
    {
        $this->dispatch = $dispatch;

        return $this;
    }

    /**
     * @throws InvalidArazzoDescriptionException when the description, a source or the workflow fails preflight; nothing is dispatched
     * @throws InvalidArgumentException when dispatchUsing() was not called
     */
    public function run(): ArazzoWorkflowResult
    {
        $dispatch = $this->dispatch ?? throw new InvalidArgumentException('Arazzo workflow runs require dispatchUsing() before run().');

        $description = ArazzoDescription::fromFile($this->path);
        $plan = ArazzoWorkflowPreflight::check($description, $this->workflowId, ArazzoSourceResolver::resolve($description));

        try {
            $inputErrors = $this->inputErrors($plan->inputsSchema);
        } catch (Throwable $e) {
            throw InvalidArazzoDescriptionException::single(
                ArazzoDescriptionReason::InvalidField,
                sprintf('workflows[%s].inputs: not a valid JSON Schema: %s', $this->workflowId, $e->getMessage()),
                $description->path,
                $e,
            );
        }

        if ($inputErrors !== []) {
            return $this->failed([], new ArazzoFailure(ArazzoFailureKind::Workflow, null, 'Workflow inputs do not match the inputs schema.', $inputErrors));
        }

        $requestValidator = new OpenApiRequestValidator();
        $responseValidator = new OpenApiResponseValidator(StrictRequiredTracker::current());
        $positions = [];
        foreach ($plan->steps as $position => $step) {
            $positions[$step->stepId] = $position;
        }

        $steps = [];
        $stepOutputs = [];
        $firstFailure = null;
        $executions = 0;
        $index = 0;
        while (isset($plan->steps[$index])) {
            if (++$executions > self::MAX_STEP_EXECUTIONS) {
                return $this->failed($steps, $firstFailure ?? new ArazzoFailure(ArazzoFailureKind::Workflow, null, sprintf(
                    'Control-flow budget exhausted: %d step executions without reaching the end of the workflow (check goto actions for a loop).',
                    self::MAX_STEP_EXECUTIONS,
                )));
            }

            $step = $plan->steps[$index];
            $result = $this->runStep($step, $stepOutputs, $dispatch, $requestValidator, $responseValidator);
            if ($result instanceof ArazzoFailure) {
                return $this->failed($steps, $firstFailure ?? $result);
            }

            $steps[] = $result;
            if ($result->failure === null) {
                $stepOutputs[$step->stepId] = $result->outputs;
            } else {
                $firstFailure ??= $result->failure;
            }

            // Workflow-kind failures (unevaluable expressions) are authoring errors, never routed.
            $action = $result->failure?->kind === ArazzoFailureKind::Workflow
                ? null
                : $this->matchingAction($result->failure === null ? $step->onSuccess : $step->onFailure, $this->resultContext($result, $stepOutputs));
            if ($action === null) {
                if ($result->failure !== null) {
                    return $this->failed($steps, $firstFailure);
                }

                $index++;

                continue;
            }

            if ($action->type === 'end') {
                break;
            }

            $index = $positions[(string) $action->stepId];
        }

        if ($firstFailure !== null) {
            return $this->failed($steps, $firstFailure);
        }

        $context = new RuntimeExpressionContext(inputs: $this->inputs, stepOutputs: $stepOutputs);
        $outputs = [];
        foreach ($plan->outputs as $name => $expression) {
            try {
                $outputs[$name] = RuntimeExpressionEvaluator::evaluate($expression, $context);
            } catch (RuntimeExpressionException $e) {
                return $this->failed($steps, new ArazzoFailure(ArazzoFailureKind::Workflow, null, sprintf("Workflow output '%s' could not be evaluated.", $name), [$e->getMessage()]));
            }
        }

        return new ArazzoWorkflowResult($this->workflowId, $steps, $outputs, null);
    }

    /**
     * Payload strings are evaluated as runtime expressions; objects and
     * lists are walked so nested expressions are evaluated too. The payload
     * is decoded verbatim, so objects (even `{"0": ...}`) stay objects.
     */
    private static function evaluatePayload(mixed $payload, RuntimeExpressionContext $context): mixed
    {
        if ($payload instanceof stdClass) {
            return (object) array_map(static fn(mixed $member): mixed => self::evaluatePayload($member, $context), get_object_vars($payload));
        }

        if (!is_array($payload)) {
            return RuntimeExpressionEvaluator::evaluateValue($payload, $context);
        }

        $evaluated = array_map(static fn(mixed $member): mixed => self::evaluatePayload($member, $context), $payload);

        return array_is_list($evaluated) ? $evaluated : (object) $evaluated;
    }

    /**
     * @return list<string>
     */
    private static function templateParameters(string $template): array
    {
        $m = [];
        preg_match_all('/\{([^{}]+)\}/', $template, $m);

        return $m[1];
    }

    private static function isParameterValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($item !== null && !is_scalar($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parameter values as a client would put them on the wire: strings,
     * booleans spelled `true`/`false`, lists kept as lists.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function wire(array $values): array
    {
        $wire = [];
        foreach ($values as $name => $value) {
            $value = RequestUriBuilder::serialise($value);
            $wire[$name] = is_array($value)
                ? array_map(static fn(mixed $item): string => (string) $item, $value)
                : (string) $value;
        }

        return $wire;
    }

    /**
     * @param array<string, array<string, mixed>> $stepOutputs
     * @param callable(ArazzoStepRequest): mixed $dispatch
     *
     * @return ArazzoFailure|ArazzoStepResult a bare failure when the request could not even be built
     */
    private function runStep(
        ArazzoStepPlan $step,
        array $stepOutputs,
        callable $dispatch,
        OpenApiRequestValidator $requestValidator,
        OpenApiResponseValidator $responseValidator,
    ): ArazzoFailure|ArazzoStepResult {
        $request = $this->buildRequest($step, new RuntimeExpressionContext(inputs: $this->inputs, stepOutputs: $stepOutputs));
        if ($request instanceof ArazzoFailure) {
            return $request;
        }

        $unbound = [];
        foreach (self::templateParameters($step->pathTemplate) as $name) {
            if (!array_key_exists($name, $request->pathParams)) {
                $unbound[] = $name;
            }
        }

        if ($unbound !== []) {
            return new ArazzoFailure(ArazzoFailureKind::RequestContract, $step->stepId, sprintf(
                "Path parameter%s %s of '%s' %s no value; the request was not dispatched.",
                count($unbound) === 1 ? '' : 's',
                implode(', ', $unbound),
                $step->pathTemplate,
                count($unbound) === 1 ? 'has' : 'have',
            ));
        }

        $requestPath = RequestUriBuilder::path($step->pathTemplate, $request->pathParams);
        $requestValidation = $requestValidator->validate(
            $step->specName,
            $step->method,
            $requestPath,
            self::wire($request->query),
            self::wire($request->headers),
            $request->body,
            $request->contentType,
            self::wire($request->cookies),
            null,
            RequestUriBuilder::openApiQuery($request->query, $step->queryDelimiters),
        );
        $matchedPath = $requestValidation->matchedPath();
        if ($matchedPath !== null && $matchedPath !== $step->pathTemplate) {
            return new ArazzoStepResult($step->stepId, $request, $requestValidation, failure: new ArazzoFailure(
                ArazzoFailureKind::RequestContract,
                $step->stepId,
                sprintf("The evaluated request path '%s' matches '%s' instead of the step's operation path '%s'; it was not dispatched.", $requestPath, $matchedPath, $step->pathTemplate),
            ));
        }

        if ($requestValidation->outcome() === OpenApiValidationOutcome::Failure) {
            return new ArazzoStepResult($step->stepId, $request, $requestValidation, failure: new ArazzoFailure(
                ArazzoFailureKind::RequestContract,
                $step->stepId,
                'The authored request violates the OpenAPI contract; it was not dispatched.',
                $requestValidation->errors(),
            ));
        }

        try {
            $response = ObservedResponse::from($dispatch($request));
        } catch (Throwable $e) {
            return new ArazzoStepResult($step->stepId, $request, $requestValidation, failure: new ArazzoFailure(
                ArazzoFailureKind::Dispatch,
                $step->stepId,
                'The dispatch callback did not produce a usable response.',
                [$e::class . ': ' . $e->getMessage()],
            ));
        }

        $responseValidation = $responseValidator->validate(
            $step->specName,
            $step->method,
            $requestPath,
            $response->statusCode,
            $response->body,
            $response->contentType,
            $response->headers,
        );
        $partial = static fn(array $criteria, array $outputs, ?ArazzoFailure $failure): ArazzoStepResult => new ArazzoStepResult(
            $step->stepId,
            $request,
            $requestValidation,
            $response->statusCode,
            $response->headers,
            $response->body,
            $responseValidation,
            $criteria,
            $outputs,
            $failure,
        );
        if ($responseValidation->outcome() === OpenApiValidationOutcome::Failure) {
            return $partial([], [], new ArazzoFailure(
                ArazzoFailureKind::ResponseContract,
                $step->stepId,
                'The response violates the OpenAPI contract.',
                $responseValidation->errors(),
            ));
        }

        $context = new RuntimeExpressionContext(
            url: $request->uri(),
            method: $step->method,
            // The body's Content-Type is sent as a header (see curlSnippet()), so expressions can read it.
            requestHeaders: HeaderNormalizer::normalize($request->headers + ($request->body->present && $request->contentType !== null ? ['Content-Type' => $request->contentType] : [])),
            requestQuery: $request->query,
            requestPath: $request->pathParams,
            requestBody: $request->body,
            response: $response,
            inputs: $this->inputs,
            stepOutputs: $stepOutputs,
        );

        $criteria = [];
        $failed = [];
        foreach ($step->conditions as $condition) {
            try {
                $passed = $condition->evaluate($context);
                $error = null;
            } catch (Throwable $e) {
                $passed = false;
                $error = $e->getMessage();
            }

            $criteria[] = ['condition' => $condition->condition, 'passed' => $passed, 'error' => $error];
            if (!$passed) {
                $failed[] = $error === null ? $condition->condition : sprintf('%s (%s)', $condition->condition, $error);
            }
        }

        if ($failed !== []) {
            return $partial($criteria, [], new ArazzoFailure(ArazzoFailureKind::Criterion, $step->stepId, 'A success criterion did not hold.', $failed));
        }

        $outputs = [];
        foreach ($step->outputs as $name => $expression) {
            try {
                $outputs[$name] = RuntimeExpressionEvaluator::evaluate($expression, $context);
            } catch (RuntimeExpressionException $e) {
                return $partial($criteria, $outputs, new ArazzoFailure(
                    ArazzoFailureKind::Workflow,
                    $step->stepId,
                    sprintf("Step output '%s' could not be evaluated.", $name),
                    [$e->getMessage()],
                ));
            }
        }

        return $partial($criteria, $outputs, null);
    }

    /**
     * The first action whose criteria all hold; a criterion that cannot be
     * evaluated does not hold.
     *
     * @param list<ArazzoAction> $actions
     */
    private function matchingAction(array $actions, RuntimeExpressionContext $context): ?ArazzoAction
    {
        foreach ($actions as $action) {
            try {
                $applies = true;
                foreach ($action->criteria as $criterion) {
                    $applies = $applies && $criterion->evaluate($context);
                }
            } catch (Throwable) {
                $applies = false;
            }

            if ($applies) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $stepOutputs
     */
    private function resultContext(ArazzoStepResult $result, array $stepOutputs): RuntimeExpressionContext
    {
        $request = $result->request;

        return new RuntimeExpressionContext(
            url: $request->uri(),
            method: $request->method,
            requestHeaders: HeaderNormalizer::normalize($request->headers),
            requestQuery: $request->query,
            requestPath: $request->pathParams,
            requestBody: $request->body,
            response: $result->statusCode === null ? null : new ObservedResponse(
                $result->statusCode,
                $result->responseHeaders,
                $result->responseHeaders['content-type'][0] ?? null,
                '',
                $result->responseBody ?? DecodedBody::absent(),
            ),
            inputs: $this->inputs,
            stepOutputs: $stepOutputs,
        );
    }

    private function buildRequest(ArazzoStepPlan $step, RuntimeExpressionContext $context): ArazzoFailure|ArazzoStepRequest
    {
        $values = ['path' => [], 'query' => [], 'header' => [], 'cookie' => []];

        try {
            foreach ($step->parameters as $parameter) {
                $value = RuntimeExpressionEvaluator::evaluateValue($parameter['value'], $context);
                if (!self::isParameterValue($value)) {
                    return new ArazzoFailure(ArazzoFailureKind::Workflow, $step->stepId, sprintf(
                        "Parameter '%s' in %s evaluated to a non-scalar value; parameters must be scalars or lists of scalars.",
                        $parameter['name'],
                        $parameter['in'],
                    ));
                }

                if ($parameter['in'] === 'path' && is_array($value)) {
                    return new ArazzoFailure(ArazzoFailureKind::Workflow, $step->stepId, sprintf(
                        "Path parameter '%s' evaluated to a list; path parameters must be scalars.",
                        $parameter['name'],
                    ));
                }

                // null (and null list items) mean "not sent", exactly as uri() and http_build_query() treat them.
                if (is_array($value)) {
                    $value = array_values(array_filter($value, static fn(mixed $item): bool => $item !== null));
                }

                if ($value !== null && $value !== []) {
                    $values[$parameter['in']][$parameter['name']] = $value;
                }
            }

            // A comma inside an item is sent as `%2C` and stays data, but the pipe and
            // space delimiters cannot be told apart from item content (OAS Appendix E).
            foreach (self::wire($values['query']) as $name => $value) {
                $delimiter = rawurldecode($step->queryDelimiters[$name] ?? ',');
                if ($delimiter === ',' || !is_array($value)) {
                    continue;
                }

                foreach ($value as $item) {
                    if (str_contains($item, $delimiter)) {
                        return new ArazzoFailure(ArazzoFailureKind::Workflow, $step->stepId, sprintf(
                            "Query parameter '%s' has the item '%s', which contains its '%s' delimiter and cannot be serialized unambiguously; it was not dispatched.",
                            $name,
                            $item,
                            $delimiter === ' ' ? 'space' : $delimiter,
                        ));
                    }
                }
            }

            $body = $step->hasBody
                ? DecodedBody::fromJsonValue(json_decode(
                    json_encode(self::evaluatePayload($step->payload, $context), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    false,
                    flags: JSON_THROW_ON_ERROR,
                ))
                : DecodedBody::absent();
        } catch (JsonException|RuntimeExpressionException $e) {
            return new ArazzoFailure(ArazzoFailureKind::Workflow, $step->stepId, 'The step request could not be evaluated.', [$e->getMessage()]);
        }

        return new ArazzoStepRequest(
            $step->stepId,
            $step->specName,
            $step->operationId,
            $step->method,
            $step->pathTemplate,
            $values['path'],
            // Parameters go to the dispatcher exactly as they were validated.
            self::wire($values['query']),
            self::wire($values['header']),
            self::wire($values['cookie']),
            $body,
            $step->hasBody ? $step->contentType : null,
            $step->queryDelimiters,
        );
    }

    /**
     * @param null|array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function inputErrors(?array $schema): array
    {
        if ($schema === null) {
            return [];
        }

        $schema += ['$schema' => OpenApiSchemaDialect::DRAFT_2020_12];
        $data = $this->inputs === [] ? new stdClass() : ObjectConverter::convert($this->inputs);
        $errors = [];
        foreach ((new SchemaValidatorRunner(ValidationPolicyDefaults::maxErrors()))->validateStructured(ObjectConverter::convert($schema), $data) as $violation) {
            $errors[] = sprintf('[%s] %s', $violation->displayPath(), $violation->message);
        }

        return $errors;
    }

    /**
     * @param list<ArazzoStepResult> $steps
     */
    private function failed(array $steps, ArazzoFailure $failure): ArazzoWorkflowResult
    {
        return new ArazzoWorkflowResult($this->workflowId, $steps, [], $failure);
    }
}
