<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation;

use const E_USER_WARNING;
use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\RemovesDirectories;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

use function array_column;
use function count;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The validators remember a converted schema by where it sits in the spec
 * (issue #724). These tests go through the public validators, so they pin
 * what the location is made of, not how the runner stores it.
 */
class SchemaLocationCacheTest extends TestCase
{
    use RemovesDirectories;

    /** One unknown `format` per schema: the converter warns about each when, and only when, it converts that schema. */
    private const FORMATS = ['at-header', 'at-path', 'at-query', 'at-request-body', 'at-response-body', 'at-response-header'];
    private string $specDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->specDir = sys_get_temp_dir() . '/gesso-schema-location-' . uniqid('', true);
        mkdir($this->specDir);
        OpenApiSpecLoader::reset();
        DiscriminatorEnforcement::reset();
        OpenApiSchemaConverter::resetWarningStateForTesting();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        DiscriminatorEnforcement::reset();
        OpenApiSchemaConverter::resetWarningStateForTesting();
        $this->removeDir($this->specDir);
        parent::tearDown();
    }

    #[Test]
    public function a_second_validation_of_the_same_request_and_response_does_not_convert_again(): void
    {
        $this->useLocatedSpec(marked: true);

        $this->assertSame(self::FORMATS, $this->formatsConvertedWhileValidating());

        // The converter warns once per process; forgetting that is what lets
        // a second conversion show.
        OpenApiSchemaConverter::resetWarningStateForTesting();
        $this->assertSame([], $this->formatsConvertedWhileValidating());

        // Dropping the cached specs drops what was derived from them.
        OpenApiSpecLoader::clearCache();
        OpenApiSchemaConverter::resetWarningStateForTesting();
        $this->assertSame(self::FORMATS, $this->formatsConvertedWhileValidating());
    }

    #[Test]
    public function schemas_that_share_a_name_keep_their_own_location(): void
    {
        $this->useLocatedSpec(marked: false);
        $requests = new OpenApiRequestValidator();
        $responses = new OpenApiResponseValidator(new StrictRequiredTracker());

        // Twice, so the second round is answered from the remembered locations.
        for ($round = 1; $round <= 2; $round++) {
            // `id` is a string for GET and an integer for PUT.
            $this->assertTrue($requests->validateWithoutRecording('located', 'GET', '/items/abc', [], [], null)->isValid(), "round {$round}");
            $this->assertFalse($requests->validateWithoutRecording('located', 'PUT', '/items/abc', [], [], ['name' => 'a'], 'application/json')->isValid(), "round {$round}");

            // `X-Count` is any string on 200 and `none` on 404; the bodies differ too.
            $this->assertTrue($responses->validateWithoutRecording('located', 'GET', '/items/abc', 200, ['name' => 'a'], 'application/json', ['X-Count' => '1'])->isValid(), "round {$round}");
            $this->assertFalse($responses->validateWithoutRecording('located', 'GET', '/items/abc', 404, ['error' => 'gone'], 'application/json', ['X-Count' => '1'])->isValid(), "round {$round}");
            $this->assertFalse($responses->validateWithoutRecording('located', 'GET', '/items/abc', 404, ['name' => 'a'], 'application/json', ['X-Count' => 'none'])->isValid(), "round {$round}");

            // One response, two media types.
            $this->assertFalse($responses->validateWithoutRecording('located', 'GET', '/items/abc', 404, ['error' => 'gone'], 'application/problem+json', ['X-Count' => 'none'])->isValid(), "round {$round}");
            $this->assertTrue($responses->validateWithoutRecording('located', 'GET', '/items/abc', 404, ['error' => 'gone'], 'application/json', ['X-Count' => 'none'])->isValid(), "round {$round}");

            // One request body, two media types.
            $this->assertTrue($requests->validateWithoutRecording('located', 'PUT', '/items/5', [], [], ['name' => 'a'], 'application/json')->isValid(), "round {$round}");
            $this->assertFalse($requests->validateWithoutRecording('located', 'PUT', '/items/5', [], [], 'name=a', 'application/x-www-form-urlencoded')->isValid(), "round {$round}");

            // One method and path, a request body and a response body.
            $this->assertTrue($requests->validateWithoutRecording('located', 'PUT', '/items/5', [], [], [], 'application/json')->isValid(), "round {$round}");
            $this->assertFalse($responses->validateWithoutRecording('located', 'PUT', '/items/5', 200, [], 'application/json')->isValid(), "round {$round}");
        }
    }

    #[Test]
    public function toggling_discriminator_enforcement_between_two_validations_yields_different_schema_objects(): void
    {
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $requests = new OpenApiRequestValidator();
        $responses = new OpenApiResponseValidator(new StrictRequiredTracker());
        // Claims to be an RSA key and carries the EC fields: only the lowered
        // discriminator mapping rejects it.
        $mislabelled = ['kty' => 'RSA', 'crv' => 'P-256', 'x' => 'a', 'y' => 'b'];
        $accepted = static fn(): array => [
            $requests->validateWithoutRecording('jwks', 'POST', '/v1/jwks', [], [], $mislabelled, 'application/json')->isValid(),
            $responses->validateWithoutRecording('jwks', 'GET', '/v1/jwks', 200, $mislabelled, 'application/json')->isValid(),
        ];

        $this->assertSame([false, false], $accepted());
        $enforced = self::sharedSchemaObjects();

        DiscriminatorEnforcement::configure(false);
        $this->assertSame([true, true], $accepted());
        $this->assertCount(2 * count($enforced), self::sharedSchemaObjects(), 'The schemas converted without enforcement must be separate objects.');

        // Back on, the schemas remembered under enforcement answer again.
        DiscriminatorEnforcement::configure(true);
        $this->assertSame([false, false], $accepted());
        $this->assertCount(2 * count($enforced), self::sharedSchemaObjects());
    }

    /**
     * @return list<object>
     */
    private static function sharedSchemaObjects(): array
    {
        /** @var array<string, array{object, object, int}> $shared */
        $shared = (new ReflectionProperty(SchemaValidatorRunner::class, 'shared'))->getValue();

        return array_column($shared, 1);
    }

    /**
     * Load the `located` spec: `GET` and `PUT /items/{id}`, whose schemas
     * each carry their own unknown `format` when `$marked`.
     */
    private function useLocatedSpec(bool $marked): void
    {
        $string = static fn(string $format): array => ['type' => 'string'] + ($marked ? ['format' => $format] : []);
        $json = static fn(array $schema): array => ['application/json' => ['schema' => $schema]];
        $named = static fn(string $format): array => ['type' => 'object', 'properties' => ['name' => $string($format)]];
        file_put_contents($this->specDir . '/located.json', json_encode([
            'openapi' => '3.0.3',
            'info' => ['title' => 'located', 'version' => '1'],
            'paths' => [
                '/items/{id}' => [
                    'get' => [
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => $string('at-path')],
                            ['name' => 'q', 'in' => 'query', 'schema' => $string('at-query')],
                            ['name' => 'X-Trace', 'in' => 'header', 'schema' => $string('at-header')],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'headers' => ['X-Count' => ['schema' => $string('at-response-header')]],
                                'content' => $json($named('at-response-body')),
                            ],
                            '404' => [
                                'description' => 'Not found',
                                'headers' => ['X-Count' => ['schema' => ['type' => 'string', 'enum' => ['none']]]],
                                'content' => $json(['type' => 'object', 'required' => ['error']])
                                    + ['application/problem+json' => ['schema' => ['type' => 'object', 'required' => ['title']]]],
                            ],
                        ],
                    ],
                    'put' => [
                        // The same parameter name as GET, with another schema.
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                        'requestBody' => ['content' => $json($named('at-request-body'))
                            + ['application/x-www-form-urlencoded' => ['schema' => ['type' => 'object', 'required' => ['title']]]]],
                        'responses' => ['200' => ['description' => 'OK', 'content' => $json(['type' => 'object', 'required' => ['name']])]],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        OpenApiSpecLoader::configure($this->specDir);
    }

    /**
     * One request with a path, a query and a header parameter and a body, and
     * one response with a header and a body.
     *
     * @return list<string> the formats the converter warned about, sorted
     */
    private function formatsConvertedWhileValidating(): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $requests = new OpenApiRequestValidator();
            $this->assertTrue($requests->validateWithoutRecording('located', 'GET', '/items/abc', ['q' => 'x'], ['X-Trace' => 't'], null)->isValid());
            $this->assertTrue($requests->validateWithoutRecording('located', 'PUT', '/items/5', [], [], ['name' => 'a'], 'application/json')->isValid());
            $this->assertTrue((new OpenApiResponseValidator(new StrictRequiredTracker()))->validateWithoutRecording(
                'located',
                'GET',
                '/items/abc',
                200,
                ['name' => 'a'],
                'application/json',
                ['X-Count' => '1'],
            )->isValid());
        } finally {
            restore_error_handler();
        }

        $formats = [];
        foreach ($warnings as $warning) {
            $this->assertSame(1, preg_match("/format '([^']+)'/", $warning, $match), $warning);
            $formats[] = $match[1];
        }
        sort($formats);

        return $formats;
    }
}
