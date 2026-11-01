<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\FailureCatalogue;
use Studio\Gesso\ValidationIssue;

use function dirname;
use function file_get_contents;
use function json_decode;

final class FailureCatalogueTest extends TestCase
{
    #[Test]
    public function the_generated_page_is_up_to_date(): void
    {
        $this->assertSame(
            FailureCatalogue::markdown(),
            file_get_contents(dirname(__DIR__, 3) . '/docs/failure-codes.md'),
            'docs/failure-codes.md is stale. Run: php scripts/generate-failure-codes-doc.php',
        );
    }

    #[Test]
    public function every_entry_is_complete(): void
    {
        foreach (FailureCatalogue::codes() as $code) {
            $this->assertMatchesRegularExpression('/^GSO[0-5]\d\d$/', $code);
            $entry = FailureCatalogue::find($code);
            $this->assertNotNull($entry);
            $this->assertNotSame('', $entry['title'], $code);
            $this->assertNotSame('', $entry['summary'], $code);
            $this->assertNotSame([], $entry['causes'], $code);
            $this->assertNotSame([], $entry['fix'], $code);
            $this->assertStringStartsWith('Do not', $entry['do_not'], $code);
        }
    }

    /**
     * Codes are a compatibility surface: a released code is never removed,
     * and a category / keyword pair never moves to another code.
     */
    #[Test]
    public function the_frozen_v3_inventory_still_holds(): void
    {
        /** @var array{codes: list<string>, issues: array<string, array<string, string>>, doctor: array<string, string>} $frozen */
        $frozen = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/compatibility/v3-failure-codes.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($frozen['codes'] as $code) {
            $this->assertNotNull(FailureCatalogue::find($code), "{$code} was removed");
        }
        foreach ($frozen['issues'] as $category => $keywords) {
            foreach ($keywords as $keyword => $code) {
                $this->assertSame($code, FailureCatalogue::forIssue($category, $keyword === '' ? null : $keyword), "{$category} {$keyword}");
            }
        }
        foreach ($frozen['doctor'] as $category => $code) {
            $this->assertSame($code, FailureCatalogue::forDoctor($category), $category);
        }
    }

    #[Test]
    public function a_keyword_without_its_own_code_falls_back_to_the_category(): void
    {
        $this->assertSame('GSO212', (new ValidationIssue('response.body', 'm', keyword: 'type'))->code());
        $this->assertSame('GSO210', (new ValidationIssue('response.body', 'm', keyword: 'minLength'))->code());
        $this->assertSame('GSO210', (new ValidationIssue('response.body', 'm'))->code());
        $this->assertSame('GSO105', (new ValidationIssue('request.parameter.query', 'm', keyword: 'required'))->code());
        $this->assertNull((new ValidationIssue('custom.category', 'm'))->code());
    }

    #[Test]
    public function suggests_close_codes_for_an_unknown_one(): void
    {
        $this->assertSame('GSO212', FailureCatalogue::suggest('212')[0]);
        $this->assertSame([], FailureCatalogue::suggest('nonsense'));
    }
}
