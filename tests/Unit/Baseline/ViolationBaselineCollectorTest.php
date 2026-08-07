<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\ValidationIssue;

use function array_map;

class ViolationBaselineCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        ViolationBaselineCollector::resetCurrent();
        ViolationBaselineEnforcer::resetCurrent();
        parent::tearDown();
    }

    #[Test]
    public function no_collector_is_installed_by_default(): void
    {
        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function the_undeclared_content_type_note_is_not_recorded(): void
    {
        // Issue #435: the note is context for the body errors, not a
        // violation. Recording it would write an entry the enforcer never
        // hits, which the stale-entry gate then reports on every run.
        $collector = new ViolationBaselineCollector();
        $result = OpenApiValidationResult::failure(
            ['[/] The required properties (data) are missing', "Note: response Content-Type 'application/problem+json' is not defined …"],
            '/v1/pets',
            '200',
            'application/json',
            [
                new ValidationIssue('response.body', '[/] The required properties (data) are missing', instancePath: '', keyword: 'required', method: 'GET', path: '/v1/pets', statusCode: '200', contentType: 'application/json'),
                new ValidationIssue('response.content_type', "Note: response Content-Type 'application/problem+json' is not defined …", method: 'GET', path: '/v1/pets', statusCode: '200', contentType: 'application/json'),
            ],
        );

        $collector->recordResult('front', $result, 'GET', '/v1/pets');

        $categories = array_map(
            static fn(ViolationFingerprint $fingerprint): string => $fingerprint->category,
            $collector->baseline()->sorted(),
        );
        $this->assertSame(['response.body'], $categories);
    }

    #[Test]
    public function set_current_installs_and_reset_removes_the_collector(): void
    {
        $collector = new ViolationBaselineCollector();
        ViolationBaselineCollector::setCurrent($collector);

        $this->assertSame($collector, ViolationBaselineCollector::current());

        ViolationBaselineCollector::resetCurrent();

        $this->assertNull(ViolationBaselineCollector::current());
    }

    #[Test]
    public function record_accumulates_deduplicated_fingerprints(): void
    {
        $collector = new ViolationBaselineCollector();
        $fingerprint = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $collector->record($fingerprint);
        $collector->record($fingerprint);

        $this->assertSame(1, $collector->baseline()->count());
        $this->assertTrue($collector->baseline()->contains($fingerprint));
    }

    #[Test]
    public function uncap_lifts_the_cap_only_while_a_collector_or_enforcer_is_installed(): void
    {
        $this->assertSame(20, ViolationBaselineCollector::uncap(20));

        ViolationBaselineCollector::setCurrent(new ViolationBaselineCollector());
        $this->assertSame(0, ViolationBaselineCollector::uncap(20));
        ViolationBaselineCollector::resetCurrent();

        // Enforcement needs the full error list too: a truncated list could
        // hide a new violation behind baselined ones and suppress it.
        ViolationBaselineEnforcer::setCurrent(new ViolationBaselineEnforcer(new ViolationBaseline()));
        $this->assertSame(0, ViolationBaselineCollector::uncap(20));
    }
}
