# Arazzo workflows

Gesso can run an [Arazzo 1.1](https://spec.openapis.org/arazzo/v1.1.0.html)
workflow as a **synchronous OpenAPI workflow runner**: every step calls one
OpenAPI operation, and every exchange is checked by the same request and
response validators the rest of Gesso uses. A step is only green when its
request and response match the contract *and* its `successCriteria` hold.

This is not full Arazzo 1.1 conformance. The [supported subset](#supported-subset)
is the contract; anything outside it fails before the first request is sent,
never as a silent no-op.

## Setup

Sources resolve through the loader you already configure — `spec_base_path`
(or `OpenApiSpecLoader::configure()`) and its `remoteSpecs`:

- A local `sourceDescriptions[].url` is resolved against the Arazzo document
  (or its `$self`) and must point at a `.json`, `.yaml` or `.yml` file inside
  the spec base path. The source then loads under the matching spec name, so
  `../openapi/front.yaml` becomes spec `front`.
- A remote `url` must equal a configured `remoteSpecs` entry. Gesso never
  fetches a source on its own, and every remote-loading protection (opt-in,
  host allowlist, byte limit) applies.
- Only `type: openapi` sources run. `asyncapi` and nested `arazzo` sources are
  rejected.

YAML descriptions need the optional `symfony/yaml` package, like YAML specs.

## Example

```yaml
# tests/arazzo/pet-purchase.arazzo.yaml
arazzo: 1.1.0
info: { title: Pet purchase, version: 1.0.0 }
sourceDescriptions:
  - name: petstore
    url: ../../openapi/petstore.yaml
workflows:
  - workflowId: buyPet
    inputs:
      type: object
      required: [name]
      properties: { name: { type: string } }
    steps:
      - stepId: create
        operationId: createPet
        requestBody:
          payload: { name: $inputs.name }
        successCriteria:
          - condition: $statusCode == 201
        outputs:
          petId: $response.body#/id
      - stepId: read
        operationId: getPet
        parameters:
          - { name: petId, in: path, value: $steps.create.outputs.petId }
          - { name: X-Request-Id, in: header, value: 'req-{$steps.create.outputs.petId}' }
        successCriteria:
          - condition: $statusCode == 200
          - condition: $response.body#/name == $inputs.name
        outputs:
          name: $response.body#/name
    outputs:
      name: $steps.read.outputs.name
```

The dispatch callback receives an `ArazzoStepRequest` and returns either a
PSR-7 `ResponseInterface` or `['status' => int, 'headers' => array, 'body' => string]`:

```php
use Studio\Gesso\Arazzo\ArazzoStepRequest;
use Studio\Gesso\Arazzo\ArazzoWorkflowRunner;

public function test_buy_pet_workflow(): void
{
    $result = ArazzoWorkflowRunner::workflow(__DIR__ . '/../arazzo/pet-purchase.arazzo.yaml', 'buyPet')
        ->inputs(['name' => 'Rex'])
        ->dispatchUsing(fn (ArazzoStepRequest $request) => $this->client->send(
            new Request(
                $request->method,
                $request->uri('https://api.test'),
                $request->headers + ($request->contentType !== null ? ['Content-Type' => $request->contentType] : []),
                $request->bodyAsJson(),
            ),
        ))
        ->run();

    self::assertTrue($result->isSuccessful(), $result->failureMessage());
    self::assertSame('Rex', $result->outputs['name']);
}
```

In Laravel, dispatch through the test client and return the array shape:

```php
->dispatchUsing(function (ArazzoStepRequest $request): array {
    $response = $this->call(
        $request->method,
        $request->uri(),
        cookies: $request->cookies,
        server: $this->transformHeadersToServerVars(
            $request->headers + ($request->contentType !== null ? ['Content-Type' => $request->contentType] : []),
        ),
        content: $request->bodyAsJson(),
    );

    return ['status' => $response->getStatusCode(), 'headers' => $response->headers->all(), 'body' => (string) $response->getContent()];
})
```

`$request->contentType` carries the body's media type (taken from the step or,
when omitted, from the operation); send it as the `Content-Type` header.
`$request->uri()` is the path template with encoded path parameters plus the
query string; pass a prefix (`uri('/api')`) when your routes live under one.
Gesso has no server-URL semantics, so `$url` in an expression is this same
request target.

## How a run works

For each step, in order:

1. Parameters and the request body are evaluated into an immutable
   `ArazzoStepRequest`.
2. The request is validated against the source's contract. An authored request
   that violates the contract stops the workflow and is **never dispatched**.
3. Your callback dispatches it.
4. The response is validated against the same source.
5. `successCriteria` are evaluated, then the step's `outputs`.

Without actions, the first failure stops the run (Arazzo's default "break
and return"). After the last step the workflow `outputs` are evaluated.
Workflow `inputs` are validated against the `inputs` schema (JSON Schema
2020-12) before any step runs.

### Success and failure actions

After a step succeeds, the first `onSuccess` action whose `criteria` all hold
(evaluated against that step's request and response) decides what happens:
`end` stops the run and evaluates the workflow `outputs`; `goto` continues at
the named step of the same workflow. No matching action means the next step.

After a step fails with a request-contract, dispatch, response-contract or
criterion failure, the first matching `onFailure` action decides: `end` stops
the run, `goto` continues at the named step. No matching action stops the run.
A `Workflow` failure (an expression that cannot be evaluated) is never routed.

Routing around a failure never turns the run green: the failed step stays in
`steps` with its `failure`, `ArazzoWorkflowResult::$failure` is the first
failed step's failure, `isSuccessful()` is false, workflow `outputs` are not
evaluated, and `failureMessage()` lists every failed step.

Workflow `successActions` / `failureActions` apply to every step; a step
action with the same `name` overrides the inherited one. Actions and
parameters may be Reusable Objects (`reference: $components.failureActions.abort`);
a Reusable parameter's `value` overrides the component's. A run stops with a
`Workflow` failure after 1,000 step executions, so a `goto` loop cannot hang a
test suite.

Both validations record coverage under the source's spec name, exactly like
an adapter assertion, and the process-wide validation policy
(`max_errors`, `skip_response_codes`, …) applies. A response skipped by policy
is recorded as skipped and stays visible as `isSkipped()` on the step's
`responseValidation`.

## Reading a result

`ArazzoWorkflowResult` carries the executed `steps` (including the failing
one), the workflow `outputs` (empty on failure) and an `ArazzoFailure` naming
the step and the boundary that stopped the run:

| `ArazzoFailureKind` | Meaning |
|---|---|
| `RequestContract` | The authored request violates the contract; it was not sent. |
| `Dispatch` | The callback threw, or returned something that is not a response (including a JSON-declared body that does not parse). |
| `ResponseContract` | The response violates the contract. |
| `Criterion` | A success criterion was false or could not be evaluated. |
| `Workflow` | Inputs, a parameter, the payload or an output could not be evaluated. |

`failureMessage()` renders the failure, its errors, the completed steps with
their statuses and a replayable curl command. Its layout is for humans; branch
on `$result->failure` instead of parsing it.

A description, source or workflow problem throws
`InvalidArazzoDescriptionException` from `run()` before anything is
dispatched. Its `reason` is an `ArazzoDescriptionReason` case and `problems`
lists every problem found, each prefixed with its location.

## Runtime expressions

| Expression | Value |
|---|---|
| `$url`, `$method`, `$statusCode` | The step's request target, method and response status. |
| `$request.header.<name>`, `$response.header.<name>` | Header value, case-insensitive; repeated headers join with `, `. |
| `$request.query.<name>`, `$request.path.<name>` | Parameter value; names are case-sensitive. |
| `$request.body#<pointer>`, `$response.body#<pointer>` | A JSON Pointer into the body, keeping its JSON type. |
| `$inputs.<name>#<pointer>` | A workflow input. |
| `$steps.<stepId>.outputs.<name>#<pointer>` | An output of an earlier step. |

A whole-value expression keeps its type (`$response.body#/id` stays an
integer). An expression embedded in a string, such as `'req-{$steps.create.outputs.petId}'`,
must produce a scalar: booleans and `null` render as `true`/`false`/`null`.
Embedding an array or object is rejected — Arazzo would serialize it as JSON;
see the [ADR 0002 amendment](adr/0002-arazzo-workflow-execution.md#amendments).

## Supported subset

Anything in the "Rejected" column fails preflight with `UnsupportedFeature`.
The subset may widen in a minor release; it will not narrow within a major.

| Area | Supported | Rejected |
|---|---|---|
| Sources | `openapi` | `asyncapi`, `arazzo` |
| Step target | `operationId` (bare only with exactly one source; `$sourceDescriptions.<name>.<operationId>` always), `operationPath` written `{$sourceDescriptions.<name>.url}#<json-pointer>` | `workflowId`, `channelPath` |
| Step fields | `description`, `parameters`, `requestBody`, `successCriteria`, `outputs`, `onSuccess`, `onFailure` | `dependsOn`, `timeout` |
| Workflow fields | `summary`, `description`, `inputs`, `steps`, `outputs`, `parameters`, `successActions`, `failureActions` | `dependsOn`, `$ref` inside `inputs` |
| Parameters | `in: path`, `query`, `header`, `cookie`; constant, expression or embedded-expression values; Reusable Objects from `components.parameters`; workflow parameters merged with step parameters (the step wins) | `in: querystring`, Selector Objects |
| Actions | `end`, `goto` a step in the same workflow, `simple` criteria, Reusable Objects from `components.successActions` / `failureActions` | `retry`, `goto` a `workflowId` |
| Request body | JSON (`application/json` or `+json`; an omitted `contentType` is taken from the operation), expressions anywhere in the payload | other content types, `replacements`, Selector Objects |
| Criteria | `simple` | `regex`, `jsonpath`, `xpath`, Expression Type Objects |
| Outputs | one runtime expression per output | Selector Objects, embedded strings, `$outputs`, `$workflows`, `$sourceDescriptions`, `$components`, `$message`, `$self` |

A `$steps` reference must name an earlier step and one of its declared
outputs; a reference to the same or a later step is a preflight error.

## Simple criteria

```text
condition  := comparison ( '&&' comparison )*
comparison := operand [ ( == | != | < | <= | > | >= ) operand ]
operand    := runtime-expression | true | false | null | number | 'string'
```

- String comparison is case-insensitive; `''` escapes a quote inside a string.
- A numeric string compares as a number against a number.
- `null` equals only `null`.
- A lone operand must be `true` (pass) or `false`/`null` (fail).
- `<`, `<=`, `>`, `>=` need two numbers; anything else is an evaluation
  error, which fails the criterion and is reported.

`||`, `!`, parentheses, `[]` indexing and `.` property access are rejected by
name. Use a JSON Pointer (`$response.body#/items/0/name`) instead of
`.`/`[]`.

## Limitations and roadmap

Not supported yet, tracked under
[#430](https://github.com/studio-design/gesso/issues/430): `retry` actions
(`retryAfter` / `retryLimit`), `timeout`, workflow calls (`workflowId` steps
and `goto`), `$workflows.*` / `$outputs.*`, `dependsOn`, Selector Objects,
`regex`/`jsonpath`/`xpath` criteria, `$ref` to `components.inputs`,
`querystring` parameters, non-JSON request bodies, AsyncAPI and nested Arazzo
sources, and JSON serialization of embedded arrays and objects.

There are no framework adapters or per-step hooks: authentication material
travels as workflow `inputs` into header or cookie parameters.
