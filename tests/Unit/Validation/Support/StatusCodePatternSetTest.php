<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Validation\Support\StatusCodePatternSet;

class StatusCodePatternSetTest extends TestCase
{
    #[Test]
    public function empty_set_matches_nothing_and_reports_empty(): void
    {
        $set = new StatusCodePatternSet([], 'someConfig');

        $this->assertTrue($set->isEmpty());
        $this->assertNull($set->match('200'));
    }

    #[Test]
    public function literal_pattern_matches_exact_status(): void
    {
        $set = new StatusCodePatternSet(['422'], 'someConfig');

        $this->assertSame('422', $set->match('422'));
        $this->assertNull($set->match('400'));
        $this->assertNull($set->match('4220'));
        $this->assertNull($set->match('042'));
    }

    #[Test]
    public function regex_pattern_matches_status_range(): void
    {
        $set = new StatusCodePatternSet(['5\d\d'], 'someConfig');

        $this->assertSame('5\d\d', $set->match('500'));
        $this->assertSame('5\d\d', $set->match('599'));
        $this->assertNull($set->match('499'));
        $this->assertNull($set->match('600'));
    }

    #[Test]
    public function match_returns_first_matching_pattern_in_insertion_order(): void
    {
        // Both `4\d\d` and `422` match status 422; insertion order pins
        // which raw pattern is echoed back. The skipReason output relies on
        // this — the user-typed pattern shows up verbatim in diagnostics.
        $set = new StatusCodePatternSet(['4\d\d', '422'], 'someConfig');

        $this->assertSame('4\d\d', $set->match('422'));
    }

    #[Test]
    public function match_returns_string_even_for_numeric_string_pattern(): void
    {
        // PHP coerces numeric-string array keys ("500") to int when used as
        // array keys. The wrapper casts back to string so the documented
        // `?string` return type is honoured.
        $set = new StatusCodePatternSet(['500'], 'someConfig');

        $matched = $set->match('500');
        $this->assertIsString($matched);
        $this->assertSame('500', $matched);
    }

    #[Test]
    public function constructor_rejects_empty_string_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skipResponseCodes[0] must not be an empty string.');

        new StatusCodePatternSet([''], 'skipResponseCodes');
    }

    #[Test]
    public function constructor_rejects_unparseable_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/skipResponseCodes\[0\] is not a valid regex pattern "\(unclosed":/');

        new StatusCodePatternSet(['(unclosed'], 'skipResponseCodes');
    }

    #[Test]
    public function configurable_label_appears_in_error_messages(): void
    {
        // `$configLabel` is plumbed through error messages so users see
        // which config key (response- vs request-side) needs fixing.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('skipRequestValidationResponseCodes[1] must not be an empty string.');

        new StatusCodePatternSet(['422', ''], 'skipRequestValidationResponseCodes');
    }
}
