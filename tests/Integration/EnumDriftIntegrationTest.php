<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\EnumDriftException;
use Studio\Gesso\Schema\EnumDriftAsserter;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Integration\Schema\Fixture\IntegrationNotificationCodeEnum;
use Studio\Gesso\Tests\Integration\Schema\Fixture\IntegrationPetStatusEnum;

/**
 * End-to-end exercise: a bundle whose `components.schemas` entries `$ref`
 * per-enum `_shared/components/schemas/enums/*.json` source files, two
 * attributed enums (one clean, one with bidirectional drift mimicking the
 * headline "31 silent cases" example from issue #165), driven through
 * `EnumDriftAsserter::assertNoDrift`.
 *
 * If this test goes red, a layer between the attribute, the loader's
 * `$ref` resolution, the component index, and the pure-function detector
 * has regressed — the unit tests cover each in isolation but cannot catch
 * wiring breakage.
 */
class EnumDriftIntegrationTest extends TestCase
{
    private const SPEC_BASE_PATH = __DIR__ . '/../fixtures/specs';
    private const SPECS = ['enum-drift/shared'];

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(self::SPEC_BASE_PATH);
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function clean_enum_passes_with_realistic_bundle_layout(): void
    {
        EnumDriftAsserter::assertNoDrift([IntegrationPetStatusEnum::class], specNames: self::SPECS);

        $reports = EnumDriftAsserter::detectAll([IntegrationPetStatusEnum::class], specNames: self::SPECS);
        $this->assertCount(1, $reports);
        $this->assertFalse($reports[0]->hasDrift());
    }

    #[Test]
    public function bidirectional_drift_is_reported_in_both_directions(): void
    {
        try {
            EnumDriftAsserter::assertNoDrift([IntegrationNotificationCodeEnum::class], specNames: self::SPECS);
            $this->fail('expected EnumDriftException');
        } catch (EnumDriftException $e) {
            $this->assertCount(1, $e->reports);
            $report = $e->reports[0];

            $this->assertSame(['betaFeature'], $report->phpOnly);
            $this->assertSame(['deprecated'], $report->specOnly);

            $msg = $e->getMessage();
            $this->assertStringContainsString('PHP-only (1): "betaFeature"', $msg);
            $this->assertStringContainsString('Spec-only (1): "deprecated"', $msg);
            $this->assertStringContainsString('#/components/schemas/NotificationCodeEnum', $msg);
        }
    }

    #[Test]
    public function detect_all_returns_one_report_per_enum_in_input_order(): void
    {
        $reports = EnumDriftAsserter::detectAll([
            IntegrationPetStatusEnum::class,
            IntegrationNotificationCodeEnum::class,
        ], specNames: self::SPECS);

        $this->assertCount(2, $reports);
        $this->assertSame(IntegrationPetStatusEnum::class, $reports[0]->enumFqcn);
        $this->assertFalse($reports[0]->hasDrift());
        $this->assertSame(IntegrationNotificationCodeEnum::class, $reports[1]->enumFqcn);
        $this->assertTrue($reports[1]->hasDrift());
    }
}
