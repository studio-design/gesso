<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use AssertionError;
use InvalidArgumentException;
use LogicException;
use Opis\JsonSchema\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Validation\Support\NamedError;
use Studio\Gesso\Validation\Support\ValidatorErrorBoundary;
use TypeError;

use function array_map;

class ValidatorErrorBoundaryTest extends TestCase
{
    #[Test]
    public function safely_named_passes_through_return_value_when_callable_succeeds(): void
    {
        $result = ValidatorErrorBoundary::safelyNamed(
            'path',
            'petstore',
            'GET',
            '/pets/{id}',
            static fn(): array => [new NamedError('id', '[path.id] must be integer'), new NamedError('id', '[path.id] must be positive')],
        );

        $this->assertSame(
            ['[path.id] must be integer', '[path.id] must be positive'],
            self::messages($result),
        );
    }

    #[Test]
    public function safely_named_passes_through_empty_array_on_success(): void
    {
        $result = ValidatorErrorBoundary::safelyNamed(
            'query',
            'petstore',
            'GET',
            '/pets',
            static fn(): array => [],
        );

        $this->assertSame([], $result);
    }

    #[Test]
    public function safely_named_converts_runtime_exception_to_error_string(): void
    {
        $result = ValidatorErrorBoundary::safelyNamed(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new RuntimeException('malformed schema');
            },
        );

        $this->assertCount(1, $result);
        $this->assertStringContainsString('[request-body]', $result[0]->message);
        $this->assertStringContainsString('POST', $result[0]->message);
        $this->assertStringContainsString('/pets', $result[0]->message);
        $this->assertStringContainsString("'petstore'", $result[0]->message);
        $this->assertStringContainsString('RuntimeException', $result[0]->message);
        $this->assertStringContainsString('malformed schema', $result[0]->message);
    }

    #[Test]
    public function safely_named_emits_fully_qualified_exception_class_name_for_namespaced_exceptions(): void
    {
        // Opis SchemaException subclasses extend RuntimeException, so the narrow
        // catch covers them. The assertion on the full FQN guards against a future
        // refactor using basename (e.g. `basename($e::class)`) that would lose
        // namespace context — critical for distinguishing opis exceptions from
        // similarly-named exceptions elsewhere.
        $result = ValidatorErrorBoundary::safelyNamed(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new ParseException('malformed schema: bad $ref');
            },
        );

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Opis\\JsonSchema\\Exceptions\\ParseException', $result[0]->message);
        $this->assertStringContainsString('malformed schema: bad $ref', $result[0]->message);
    }

    #[Test]
    public function safely_named_pins_exact_error_string_format(): void
    {
        // Guard against field-order / separator / label drift that
        // assertStringContainsString would silently accept. Downstream log
        // scrapers or CI summary formatters depend on this exact shape.
        $result = ValidatorErrorBoundary::safelyNamed(
            'header',
            'my-spec',
            'PATCH',
            '/v1/users/{id}',
            static function (): array {
                throw new RuntimeException('boom');
            },
        );

        $this->assertSame(
            ["[header] PATCH /v1/users/{id} in 'my-spec' spec: RuntimeException threw: boom"],
            self::messages($result),
        );
        $this->assertNull($result[0]->name);
    }

    #[Test]
    public function safely_named_appends_previous_exception_when_present(): void
    {
        // opis wraps lower-level errors via getPrevious(); with stack traces
        // discarded, the previous class + message is the most actionable piece
        // of root-cause signal left.
        $previous = new RuntimeException('underlying PCRE error: No ending delimiter');
        $result = ValidatorErrorBoundary::safelyNamed(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function () use ($previous): array {
                throw new RuntimeException('pattern keyword rejected', 0, $previous);
            },
        );

        $this->assertSame(
            ["[request-body] POST /pets in 'petstore' spec: RuntimeException threw: pattern keyword rejected"
                . ' (caused by RuntimeException: underlying PCRE error: No ending delimiter)'],
            self::messages($result),
        );
    }

    #[Test]
    public function safely_named_omits_previous_suffix_when_no_chain(): void
    {
        // Symmetric pin: an exception without getPrevious() must NOT produce
        // a dangling "(caused by ...)" suffix.
        $result = ValidatorErrorBoundary::safelyNamed(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new RuntimeException('lone error');
            },
        );

        $this->assertStringNotContainsString('caused by', $result[0]->message);
    }

    #[Test]
    public function safely_named_rethrows_type_error(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('programmer bug');

        ValidatorErrorBoundary::safelyNamed(
            'path',
            'petstore',
            'GET',
            '/pets',
            static function (): array {
                throw new TypeError('programmer bug');
            },
        );
    }

    #[Test]
    public function safely_named_rethrows_assertion_error(): void
    {
        $this->expectException(AssertionError::class);

        ValidatorErrorBoundary::safelyNamed(
            'security',
            'petstore',
            'GET',
            '/pets',
            static function (): array {
                throw new AssertionError('invariant broken');
            },
        );
    }

    #[Test]
    public function safely_named_rethrows_invalid_argument_exception(): void
    {
        // InvalidArgumentException extends LogicException extends Exception — it is
        // NOT a RuntimeException, so the narrow catch lets it bubble. This mirrors
        // the \Error policy: LogicException family signals programmer bugs (e.g.
        // opis's own `throw new InvalidArgumentException("Invalid schema")`), and
        // silently downgrading those to a validation error would defeat the whole
        // point of the per-sub-validator boundary.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad input');

        ValidatorErrorBoundary::safelyNamed(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new InvalidArgumentException('bad input');
            },
        );
    }

    #[Test]
    public function safely_named_rethrows_logic_exception(): void
    {
        // Parent of InvalidArgumentException: pins the broader LogicException
        // family policy rather than relying on the InvalidArgumentException
        // concrete case alone.
        $this->expectException(LogicException::class);

        ValidatorErrorBoundary::safelyNamed(
            'request-body',
            'petstore',
            'POST',
            '/pets',
            static function (): array {
                throw new LogicException('impossible state');
            },
        );
    }

    /**
     * @param list<NamedError> $errors
     *
     * @return list<string>
     */
    private static function messages(array $errors): array
    {
        return array_map(static fn(NamedError $error): string => $error->message, $errors);
    }
}
