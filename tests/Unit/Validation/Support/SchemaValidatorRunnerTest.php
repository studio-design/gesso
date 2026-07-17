<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

use function count;
use function gc_collect_cycles;
use function implode;
use function memory_get_usage;
use function sprintf;
use function str_contains;

class SchemaValidatorRunnerTest extends TestCase
{
    #[Test]
    public function validate_returns_empty_array_for_valid_data(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert(['type' => 'integer']);

        $this->assertSame([], $runner->validate($schema, 42));
    }

    #[Test]
    public function validate_returns_formatted_errors_for_invalid_data(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert(['type' => 'integer']);

        $errors = $runner->validate($schema, 'not-an-int');

        $this->assertNotSame([], $errors);
        $this->assertArrayHasKey('/', $errors);
    }

    #[Test]
    public function validate_returns_nested_pointer_paths(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer'],
            ],
            'required' => ['count'],
        ]);
        $data = ObjectConverter::convert(['count' => 'not-an-int']);

        $errors = $runner->validate($schema, $data);

        $this->assertArrayHasKey('/count', $errors);
    }

    #[Test]
    public function equivalent_fresh_schema_objects_reuse_one_canonical_opis_schema(): void
    {
        $runner = new SchemaValidatorRunner(20);
        $schema = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'name'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
        $data = ObjectConverter::convert(['data' => [['id' => 1, 'name' => 'Ada']]]);

        gc_collect_cycles();
        $memoryBefore = memory_get_usage();
        for ($i = 0; $i < 1_000; $i++) {
            // Reproduce the real request/response-validator path: schema
            // conversion returns a fresh object graph on every assertion.
            $freshSchema = ObjectConverter::convert(
                OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1),
            );
            $this->assertSame([], $runner->validate($freshSchema, $data));
        }
        gc_collect_cycles();

        $retainedBytes = memory_get_usage() - $memoryBefore;
        $this->assertLessThan(
            4 * 1024 * 1024,
            $retainedBytes,
            sprintf(
                'Equivalent schema validations retained %.2f MiB; Opis schema identities are growing per assertion.',
                $retainedBytes / 1024 / 1024,
            ),
        );

        $cache = (new ReflectionProperty($runner, 'canonicalSchemas'))->getValue($runner);
        $this->assertCount(1, $cache);
    }

    #[Test]
    public function canonical_schema_cache_is_bounded_for_dynamic_schema_workloads(): void
    {
        $runner = new SchemaValidatorRunner(20);

        for ($i = 0; $i < 1_025; $i++) {
            $schema = ObjectConverter::convert([
                'type' => 'integer',
                'const' => $i,
            ]);
            $this->assertSame([], $runner->validate($schema, $i));
        }

        $cache = (new ReflectionProperty($runner, 'canonicalSchemas'))->getValue($runner);
        $this->assertCount(
            1,
            $cache,
            'Crossing the cache limit must release both Gesso and Opis schema caches before retaining new identities.',
        );
    }

    #[Test]
    public function constructor_rejects_negative_max_errors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maxErrors must be 0 (unlimited) or a positive integer, got -1.');

        new SchemaValidatorRunner(-1);
    }

    #[Test]
    public function constructor_accepts_zero_as_unlimited(): void
    {
        $runner = new SchemaValidatorRunner(0);

        $this->assertSame([], $runner->validate(ObjectConverter::convert(['type' => 'string']), 'ok'));
    }

    #[Test]
    public function max_errors_one_stops_at_first_error(): void
    {
        // Pin the `stop_at_first_error: true` branch that the constructor
        // enables when maxErrors === 1. With two independent violations, the
        // capped runner must return exactly one error; the uncapped runner
        // must surface both.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'integer'],
                'b' => ['type' => 'integer'],
            ],
            'required' => ['a', 'b'],
        ]);
        $data = ObjectConverter::convert(['a' => 'not-int', 'b' => 'also-not-int']);

        $cappedErrors = (new SchemaValidatorRunner(1))->validate($schema, $data);
        $uncappedErrors = (new SchemaValidatorRunner(20))->validate($schema, $data);

        $this->assertCount(1, $cappedErrors);
        $this->assertGreaterThan(1, count($uncappedErrors));
    }

    // ============================================================
    // Cascading additionalProperties dedup (issue #159)
    //
    // opis's PropertiesKeyword skips its addCheckedProperties() call
    // whenever any sub-property fails, leaving `$checked` empty in
    // the validation context. So when `additionalProperties: false`
    // runs afterward, every declared property looks "additional",
    // producing a paired pseudo-error:
    //
    //   [/code]  enum-failure (real)
    //   [/]      Additional object properties are not allowed:
    //              message, code   (← cascade artifact)
    //
    // The runner walks the ValidationError tree, reads the raw list
    // of "additional" property names from `args()['properties']`,
    // and filters out names that ARE declared in the schema's
    // `properties` keyword at that path. See issue #159 for the
    // upstream root cause.
    // ============================================================

    #[Test]
    public function additional_properties_cascade_is_dropped_when_all_listed_props_have_sub_errors(): void
    {
        // Issue #159 minimal repro. Both properties are declared, body
        // is well-shaped except for the `code` enum violation. The
        // additionalProperties cascade should NOT surface — only the
        // real `[/code]` error remains.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['message', 'code'],
            'properties' => [
                'message' => ['type' => 'string'],
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['message' => 'oops', 'code' => 'notInEnum']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/code', $errors, 'real enum sub-error must remain');
        $this->assertArrayNotHasKey(
            '/',
            $errors,
            sprintf(
                'cascade additionalProperties error must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_keeps_real_extras_when_no_cascade(): void
    {
        // Genuine additional property: `extra` is not declared in the
        // schema, so the additionalProperties: false error is the actual
        // contract violation and must surface unchanged.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['id' => 1, 'extra' => 'nope']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        $this->assertStringContainsString('extra', $errors['/'][0]);
    }

    #[Test]
    public function additional_properties_partial_dedup_strips_only_cascade_names(): void
    {
        // Mixed case: `code` fails its enum (cascade artifact) AND
        // `extra` is genuinely undeclared. The kept message should
        // mention `extra` only — `code` is a cascade and must be
        // stripped, but the surrounding additionalProperties signal is
        // still load-bearing for `extra`.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['code' => 'notInEnum', 'extra' => 'nope']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/code', $errors);
        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        $this->assertStringContainsString('extra', $errors['/'][0]);
        $this->assertStringNotContainsString(
            'code',
            $errors['/'][0],
            'cascading "code" name must be stripped from the additionalProperties message',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_nested_object_path(): void
    {
        // The cascade happens at a deeper path than `/`. The dedup
        // logic must use the path prefix correctly — `/wrapper/code`
        // sub-error neutralises the `wrapper.code` mention in the
        // `[/wrapper] additionalProperties` cascade message.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'wrapper' => [
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string', 'enum' => ['allowedCode']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        $data = ObjectConverter::convert(['wrapper' => ['code' => 'notInEnum']]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/wrapper/code', $errors);
        $this->assertArrayNotHasKey(
            '/wrapper',
            $errors,
            sprintf(
                'cascade at /wrapper must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_single_listed_property(): void
    {
        // Single-property cascade: opis emits the message without a
        // comma. The parser must handle that form, otherwise a one-off
        // cascade leaks through.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['code' => 'notInEnum']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/code', $errors);
        $this->assertArrayNotHasKey('/', $errors);
    }

    #[Test]
    public function additional_properties_partial_dedup_preserves_exact_message_format(): void
    {
        // Tighter than `partial_dedup_strips_only_cascade_names`: we pin the
        // *exact* rewritten message so a future refactor that decorates the
        // line (e.g. "extra (cascade-stripped)") fails this test instead of
        // silently changing the user-visible contract.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['code' => 'notInEnum', 'extra' => 'nope']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertSame(
            ['Additional object properties are not allowed: extra'],
            $errors['/'] ?? null,
        );
    }

    #[Test]
    public function additional_properties_dedup_preserves_all_real_sub_errors(): void
    {
        // Issue #159's load-bearing invariant: the dedup must NEVER suppress
        // a real validation error. With two simultaneously-failing declared
        // properties, both `[/message]` and `[/code]` must survive while the
        // cascading `[/]` is dropped. A future refactor that conflated path-
        // walking with error-key matching could pass the single-sub-error
        // tests above while losing one of two co-failing sub-errors.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['message', 'code'],
            'properties' => [
                'message' => ['type' => 'string'],
                'code' => ['type' => 'string', 'enum' => ['allowedCode']],
            ],
            'additionalProperties' => false,
        ]);
        // Both `message` (wrong type, integer) and `code` (wrong enum) fail.
        $data = ObjectConverter::convert(['message' => 42, 'code' => 'notInEnum']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/message', $errors, 'first real sub-error must survive');
        $this->assertArrayHasKey('/code', $errors, 'second real sub-error must survive');
        $this->assertArrayNotHasKey('/', $errors, 'cascade must be dropped');
    }

    #[Test]
    public function additional_properties_dedup_is_no_op_when_root_schema_is_boolean(): void
    {
        // opis accepts `true`/`false` as top-level schemas. The dedup path
        // requires a stdClass root to introspect; the early-return must NOT
        // suppress real errors that come back when the boolean schema rejects
        // every value.
        $errors = (new SchemaValidatorRunner(0))->validate(false, ObjectConverter::convert(['x' => 1]));

        $this->assertNotSame([], $errors, 'real validation error must surface against `false` schema');
    }

    #[Test]
    public function additional_properties_dedup_keeps_message_under_oneof_composition(): void
    {
        // oneOf routes the data through alternate sub-schemas — the cascade's
        // path doesn't resolve through plain `properties.<name>` walking. The
        // safe-degradation contract is "leave the message untouched"; if a
        // future refactor accidentally suppresses oneOf-branch
        // additionalProperties errors, this test catches it.
        $schema = ObjectConverter::convert([
            'oneOf' => [
                [
                    'type' => 'object',
                    'required' => ['kind'],
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['a']],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'required' => ['kind'],
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['b']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        // Real undeclared property — both branches must reject.
        $data = ObjectConverter::convert(['kind' => 'a', 'extra' => 'nope']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $foundExtraReport = false;
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (str_contains($message, 'extra')) {
                    $foundExtraReport = true;

                    break 2;
                }
            }
        }

        $this->assertTrue(
            $foundExtraReport,
            sprintf(
                'oneOf branch must surface its real additional-property error; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_property_name_with_comma(): void
    {
        // Critical regression: the previous string-based implementation used
        // `explode(',', ...)` to parse the rendered message. A real undeclared
        // property literally named `"a,b"` would split into `["a", "b"]`, both
        // would compare-as-declared if the schema declared `a` and `b`, and
        // the cascade would silently swallow the real "a,b" violation.
        //
        // The current implementation reads the raw `args()['properties']`
        // array directly, so `"a,b"` arrives as a single token and is
        // correctly recognised as undeclared.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'string'],
                'b' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['a' => 'ok', 'b' => 'ok', 'a,b' => 'real-extra']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        $this->assertStringContainsString(
            'a,b',
            $errors['/'][0],
            'comma-bearing property name must reach the user as the real additional',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_empty_property_name(): void
    {
        // Critical regression: opis renders `Additional object properties are
        // not allowed: ` (trailing space) when the only undeclared property
        // is the empty-string key. The previous implementation stripped this
        // via `array_filter` on empty strings and dropped the message
        // entirely — a silent loss. The structural implementation reads the
        // empty string directly from `args()['properties']` and treats it as
        // a real (non-declared) value.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['a' => 'ok', '' => 'real-extra']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey(
            '/',
            $errors,
            'empty-string undeclared property must surface as a real additional violation',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_property_name_with_leading_whitespace(): void
    {
        // Critical regression: the previous implementation called `trim()` on
        // each parsed token. A real undeclared property named `' code'` (with
        // a leading space) would compare equal to a declared `'code'` after
        // trimming, silently swallowing the real violation. The structural
        // implementation compares strings exactly.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'code' => ['type' => 'string'],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['code' => 'ok', ' code' => 'real-extra']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/', $errors);
        $this->assertCount(1, $errors['/']);
        // We don't assert on the rendered list format here because opis joins
        // the names with `', '` and a leading-space name would render as
        // `' code'` inside the comma list — visually ambiguous but
        // semantically correct. The presence of any error at `/` is the load-
        // bearing assertion.
    }

    // ============================================================
    // Array-boundary cascade dedup
    //
    // The walker steps through `items` for int segments — both single-
    // schema form (`items: <stdClass>`, the OAS 3.0/3.1 common case)
    // and Draft 07 tuple form (`items: [<stdClass>, ...]`, also the
    // shape OpenApiSchemaConverter lowers OAS 3.1 prefixItems to). A
    // cascade at `[/data/0]` whose listed names are all declared in
    // the items schema must be dropped just like the property-only
    // case.
    // ============================================================

    #[Test]
    public function additional_properties_cascade_is_dropped_through_array_items(): void
    {
        // Cascade at `/data/0` lists `name, plan` — both declared in items'
        // properties — so the whole message is a cascade artifact and must
        // be dropped. Only the real `format` failure survives.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['plan'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'plan' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);
        $data = ObjectConverter::convert([
            'data' => [['name' => 'a', 'plan' => 'NOT-RFC']],
        ]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/data/0/plan', $errors, 'real format sub-error must remain');
        $this->assertArrayNotHasKey(
            '/data/0',
            $errors,
            sprintf(
                'array-boundary cascade must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_cascade_is_dropped_through_nested_array_object_chain(): void
    {
        // Tower-of-cascades load-bearing assertion: schema goes
        // properties → items → properties → deep-property. A single
        // deep `format` failure must NOT produce a cascade line at
        // every intermediate object. All three cascade paths (root,
        // /data/0, /data/0/plan) must collapse to a single real error
        // at /data/0/plan/ts. A partial fix that descended through one
        // array boundary but not recursively would pass the simpler
        // tests above and still fail here.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['plan'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'plan' => [
                                'type' => 'object',
                                'required' => ['ts'],
                                'properties' => [
                                    'ts' => ['type' => 'string', 'format' => 'date-time'],
                                    'level' => ['type' => 'string'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert([
            'data' => [['name' => 'a', 'plan' => ['ts' => 'NOT-RFC', 'level' => 'pro']]],
        ]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/data/0/plan/ts', $errors, 'real format sub-error must remain');
        $this->assertArrayNotHasKey('/data/0/plan', $errors, 'inner-object cascade must drop');
        $this->assertArrayNotHasKey('/data/0', $errors, 'array-element cascade must drop');
        $this->assertArrayNotHasKey('/', $errors, 'root cascade must drop');
    }

    #[Test]
    public function additional_properties_cascade_is_dropped_through_tuple_form_items(): void
    {
        // Draft 07 tuple-form items (also: the lowered shape of OAS 3.1
        // prefixItems via OpenApiSchemaConverter). Each numeric segment
        // must descend into items[<n>], not into a single shared schema.
        // The `code` cascade at `/1` must be dropped because every
        // listed name is declared in the per-index sub-schema.
        $schema = ObjectConverter::convert([
            'type' => 'array',
            'items' => [
                ['type' => 'string'],
                [
                    'type' => 'object',
                    'required' => ['code'],
                    'properties' => [
                        'code' => ['type' => 'string', 'enum' => ['allowedCode']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        $data = ObjectConverter::convert(['ok', ['code' => 'notInEnum']]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/1/code', $errors, 'real enum sub-error must remain');
        $this->assertArrayNotHasKey(
            '/1',
            $errors,
            sprintf(
                'tuple-form cascade must be suppressed; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_degrades_safely_when_items_is_boolean_schema(): void
    {
        // `items: false` causes opis to short-circuit per-element — no
        // cascade ever fires at `/data/<n>`, so this test does not exercise
        // the walker's int-segment branch directly. What it pins is the
        // sibling-path safety contract: a real undeclared property at the
        // root (`extra`) MUST reach the user with the expected
        // additionalProperties message intact, even when the same body has
        // an array under a schema shape the walker cannot meaningfully
        // introspect. A future refactor that broadened the dedup to
        // suppress messages at `/` whenever any int-segmented sub-error
        // appeared in the tree would silently break this — and no other
        // test in the file would catch it.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => false,
                ],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['data' => [1], 'extra' => 'real']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey(
            '/',
            $errors,
            sprintf(
                'real additionalProperties violation at root must surface; got: %s',
                $this->formatErrors($errors),
            ),
        );
        $this->assertSame(
            ['Additional object properties are not allowed: extra'],
            $errors['/'],
            'kept message must name `extra` and only `extra`',
        );
    }

    #[Test]
    public function additional_properties_keeps_real_extras_inside_array_items(): void
    {
        // Bidirectional regression: when an item object actually carries
        // an undeclared property, the additionalProperties error at
        // `/data/0` must SURVIVE intact — only declared-name cascade
        // artifacts are filtered, real extras are preserved as-is.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]);
        $data = ObjectConverter::convert([
            'data' => [['name' => 'a', 'rogue' => 'real-extra']],
        ]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/data/0', $errors);
        $this->assertCount(1, $errors['/data/0']);
        $this->assertStringContainsString('rogue', $errors['/data/0'][0]);
        $this->assertStringNotContainsString(
            'name',
            $errors['/data/0'][0],
            'declared name must not appear in the kept additional-properties message',
        );
    }

    #[Test]
    public function additional_properties_dedup_preserves_message_for_out_of_range_tuple_index(): void
    {
        // Tuple-form `items: [s0, s1]` length is 2; data length is 3. opis
        // applies `additionalProperties: false` against the parent array's
        // *index 2* sub-validation context — the walker's `array_key_exists`
        // guard returns null at the bail (no s2 in the tuple), so any
        // cascade message at `/2` must be preserved. A future refactor that
        // fell through to a default schema or to `additionalItems` could
        // start filtering out names that ARE genuinely additional, which is
        // the false-negative direction this test guards against. We assert
        // by induction: a real undeclared property `rogue` at index 2 must
        // surface with `rogue` in the message regardless of the walker's
        // resolution choice for the out-of-range index.
        $schema = ObjectConverter::convert([
            'type' => 'array',
            'items' => [
                ['type' => 'string'],
                ['type' => 'string'],
            ],
            // Without additionalItems specified, opis treats Draft 07
            // tuple-overflow as allowed; constrain index 2 explicitly via a
            // schema attached to additionalItems so a cascade fires.
            'additionalItems' => [
                'type' => 'object',
                'properties' => [
                    'declared' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ]);
        $data = ObjectConverter::convert(['ok', 'ok', ['declared' => 'x', 'rogue' => 'real-extra']]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $foundRogue = false;
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (str_contains($message, 'rogue')) {
                    $foundRogue = true;

                    break 2;
                }
            }
        }

        $this->assertTrue(
            $foundRogue,
            sprintf(
                'real additional `rogue` at out-of-range tuple index must reach the user; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_preserves_cascade_under_oneof_above_array(): void
    {
        // Composition keyword above an array boundary: `oneOf: [{ type:
        // object, properties: { data: { items: ... } } }, ...]`. The
        // walker's top-of-tree descent doesn't recognise `oneOf`, so each
        // path with `oneOf` between root and `items` resolves to null —
        // the cascade message is preserved. A real undeclared property at
        // a parallel path (`extra`) must still surface as a regular
        // additionalProperties violation without being entangled with the
        // oneOf branch's items walk.
        $schema = ObjectConverter::convert([
            'oneOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                ],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'data' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        $data = ObjectConverter::convert(['data' => [['name' => 'a', 'rogue' => 'real']], 'extra' => 'real']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $foundExtra = false;
        $foundRogue = false;
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (str_contains($message, 'extra')) {
                    $foundExtra = true;
                }
                if (str_contains($message, 'rogue')) {
                    $foundRogue = true;
                }
            }
        }

        $this->assertTrue(
            $foundExtra,
            sprintf(
                'real additional `extra` at root must reach the user under oneOf; got: %s',
                $this->formatErrors($errors),
            ),
        );
        $this->assertTrue(
            $foundRogue,
            sprintf(
                'real additional `rogue` inside an items branch under oneOf must reach the user; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_dispatches_correctly_with_mixed_properties_and_items(): void
    {
        // Pathological-but-legal: an object schema declaring both
        // `properties` and `items`. opis evaluates both keywords against
        // their applicable data shape (object → properties; array → items),
        // and the walker dispatches purely on segment type. This pin
        // protects the dispatch invariant against a future refactor that
        // collapsed the int/string branches into a single keyword lookup.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'list' => [
                    'type' => 'object',
                    // Both keywords present at the same level.
                    'properties' => [
                        'declared' => ['type' => 'string', 'enum' => ['allowed']],
                    ],
                    'items' => ['type' => 'integer'],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        $data = ObjectConverter::convert(['list' => ['declared' => 'notAllowed']]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        // Real enum failure must remain at the property's path.
        $this->assertArrayHasKey(
            '/list/declared',
            $errors,
            sprintf(
                'enum sub-error must surface despite mixed properties+items at parent; got: %s',
                $this->formatErrors($errors),
            ),
        );
        // Cascade at /list (lists `declared` → declared) must be dropped.
        $this->assertArrayNotHasKey(
            '/list',
            $errors,
            sprintf(
                'cascade with declared name must drop even when sibling `items` is present; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_digit_only_property_name(): void
    {
        // PHP coerces all-digits string keys to int when materialised on a
        // stdClass. opis's `foreach ($properties as $name => $prop)` then
        // pushes int(42) onto fullPath() for a property declared as "42".
        // The walker's `is_int($segment)` branch takes over, finds no
        // `items` keyword, and bails to null. Result: the cascade is
        // preserved (no false-negative), but no dedup is applied for
        // digit-only property names. This test pins the no-op contract:
        // a real undeclared property `extra` must still reach the user even
        // when the schema declares a digit-only property whose sub-error
        // would otherwise be a cascade trigger.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                '42' => ['type' => 'string', 'enum' => ['allowed']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['42' => 'notAllowed', 'extra' => 'real']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        // Real enum violation surfaces (even at the int-segment path).
        $foundEnum = false;
        $foundExtra = false;
        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (str_contains($message, 'enum')) {
                    $foundEnum = true;
                }
                if (str_contains($message, 'extra')) {
                    $foundExtra = true;
                }
            }
        }

        $this->assertTrue(
            $foundEnum,
            sprintf(
                'enum violation on digit-only property must surface; got: %s',
                $this->formatErrors($errors),
            ),
        );
        $this->assertTrue(
            $foundExtra,
            sprintf(
                'real additional `extra` must surface even when schema has digit-only declared property; got: %s',
                $this->formatErrors($errors),
            ),
        );
    }

    #[Test]
    public function additional_properties_dedup_with_empty_array_data(): void
    {
        // Empty array under a cascade-capable schema: `data: []` produces
        // no per-element sub-errors, so opis emits no cascade for the
        // items schema. A separate root-level real `extra` must surface
        // unaffected, and there must be no spurious `/data/<n>` entry.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['data' => [], 'extra' => 'real']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        $this->assertArrayHasKey('/', $errors, 'real root cascade must survive');
        $this->assertSame(
            ['Additional object properties are not allowed: extra'],
            $errors['/'],
        );
        // No bogus per-element cascade.
        foreach ($errors as $path => $_) {
            $this->assertStringStartsNotWith(
                '/data/',
                $path,
                sprintf('no /data/<n> entry expected for empty array data; saw [%s]', $path),
            );
        }
    }

    #[Test]
    public function additional_properties_dedup_handles_mixed_boolean_object_tuple(): void
    {
        // Tuple-form `items: [true, <stdClass>]`. The boolean entry at
        // index 0 must NOT be descended into (`instanceof stdClass` filters
        // it), so a cascade at `/0` falls back to the safe path and the
        // real format/enum failure of index 1's sub-schema survives
        // independently. We pin the boolean-slot bail by putting an
        // undeclared property at index 0: it must reach the user.
        $schema = ObjectConverter::convert([
            'type' => 'array',
            'items' => [
                true,
                [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]);
        // Index 0 is permitted by `true`; index 1 has a declared cascade
        // path that should still dedup correctly.
        $data = ObjectConverter::convert([['anything' => 'goes'], ['name' => 'a', 'rogue' => 'real']]);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        // Index 1 cascade must keep the real `rogue` (declared `name` filtered).
        $this->assertArrayHasKey('/1', $errors, 'real additional at index 1 must surface');
        $this->assertCount(1, $errors['/1']);
        $this->assertStringContainsString('rogue', $errors['/1'][0]);
        $this->assertStringNotContainsString(
            'name',
            $errors['/1'][0],
            'declared `name` must be filtered from the tuple-form cascade message',
        );
    }

    #[Test]
    public function additional_properties_dedup_handles_property_name_with_slash(): void
    {
        // Property names containing `/` produce JSON-Pointer-encoded path
        // segments (`a/b` → pointer `/a~1b`) when they appear as data keys.
        // The structural walker uses raw segments from `DataInfo::fullPath()`
        // so the slash-bearing name matches the schema's declaration without
        // any decoding work on our side. The previous implementation walked
        // the pointer string with `explode('/')` and silently failed to
        // resolve such schemas; the message was kept (safe direction), but
        // no actual dedup happened.
        $schema = ObjectConverter::convert([
            'type' => 'object',
            'properties' => [
                'a/b' => ['type' => 'string', 'enum' => ['allowed']],
            ],
            'additionalProperties' => false,
        ]);
        $data = ObjectConverter::convert(['a/b' => 'notAllowed']);

        $errors = (new SchemaValidatorRunner(0))->validate($schema, $data);

        // The enum sub-error fires at the JSON-Pointer-encoded path.
        $this->assertArrayHasKey('/a~1b', $errors, 'enum sub-error must survive at the encoded path');
        $this->assertArrayNotHasKey(
            '/',
            $errors,
            'cascade for the slash-bearing declared name must be dropped',
        );
    }

    #[Test]
    public function prefix_items_with_items_true_matches_prefix_items_without_sibling_under_opis_draft07(): void
    {
        // handlePrefixItems omits `additionalItems` when the sibling is
        // `items: true`, relying on opis Draft 07 treating absent and
        // explicit-true identically. That assumption is opis-runtime-
        // dependent (parallel to the KNOWN_OPIS_FORMATS pin); this test
        // anchors it so a future opis upgrade that distinguishes the two
        // surfaces as a failure here rather than as a silent contract
        // shift in `handlePrefixItems`.
        $tuple = [['type' => 'string'], ['type' => 'integer']];
        $body = ObjectConverter::convert(['hello', 42, 'overflow-1', 'overflow-2']);
        $runner = new SchemaValidatorRunner(0);

        $absentSchema = ObjectConverter::convert(['type' => 'array', 'items' => $tuple]);
        $explicitTrueSchema = ObjectConverter::convert([
            'type' => 'array',
            'items' => $tuple,
            'additionalItems' => true,
        ]);

        $this->assertSame(
            $runner->validate($absentSchema, $body),
            $runner->validate($explicitTrueSchema, $body),
            'absent additionalItems must validate the same as additionalItems: true under opis Draft 07',
        );
    }

    #[Test]
    public function prefix_items_with_sibling_items_rejects_violating_overflow_after_converter_lowering(): void
    {
        // Issue #212 end-to-end regression: prior to the fix,
        // `handlePrefixItems` clobbered the sibling `items` constraint, so a
        // body whose overflow element violated `items: {type: boolean}`
        // would validate green — a silent contract bypass. Run the full
        // converter → validator path and assert the overflow violation
        // surfaces. Without the fix this assertion fails (errors === []).
        $converted = OpenApiSchemaConverter::convert(
            [
                'type' => 'array',
                'prefixItems' => [
                    ['type' => 'string'],
                    ['type' => 'integer'],
                ],
                'items' => ['type' => 'boolean'],
            ],
            OpenApiVersion::V3_1,
        );

        $errors = (new SchemaValidatorRunner(0))->validate(
            ObjectConverter::convert($converted),
            ObjectConverter::convert(['hello', 42, 'oops']),
        );

        $this->assertNotSame(
            [],
            $errors,
            'prefixItems + items: {type: boolean} must reject a string at the overflow index',
        );
    }

    /**
     * @param array<string, string[]> $errors
     */
    private function formatErrors(array $errors): string
    {
        $lines = [];
        foreach ($errors as $path => $messages) {
            foreach ($messages as $message) {
                $lines[] = sprintf('[%s] %s', $path, $message);
            }
        }

        return implode(' | ', $lines);
    }
}
