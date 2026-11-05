<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_keys;

/**
 * ADR 0008: a request under a base path the spec's servers declare matches
 * without strip_prefixes, and is recorded against the template.
 */
final class ServerBasePathMatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_request_under_the_server_base_path_matches_and_records_the_template(): void
    {
        $response = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());

        $this->assertTrue($response->validate('server-base-paths', 'GET', '/api/pets', 200, [], 'application/json')->isValid());
        $this->assertFalse($response->validate('server-base-paths', 'GET', '/api/pets', 200, 'not an array', 'application/json')->isValid());
        $this->assertTrue((new OpenApiRequestValidator())->validate('server-base-paths', 'GET', '/api/pets', [], [], null)->isValid());
        $this->assertSame(['GET /pets'], array_keys(OpenApiCoverageTracker::getCovered()['server-base-paths'] ?? []));
    }

    #[Test]
    public function a_request_without_the_base_path_still_matches(): void
    {
        $response = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());

        $this->assertTrue($response->validate('server-base-paths', 'GET', '/pets', 200, [], 'application/json')->isValid());
    }

    #[Test]
    public function a_path_level_server_replaces_the_root_one(): void
    {
        $response = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());

        $this->assertTrue($response->validate('server-base-paths', 'GET', '/internal/admin/stats', 200, null)->isValid());
        $result = $response->validate('server-base-paths', 'GET', '/api/admin/stats', 200, null);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('but the servers of /admin/stats do not declare it', $result->errors()[0]);
    }

    #[Test]
    public function configured_strip_prefixes_turn_server_base_paths_off(): void
    {
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs', ['/gateway']);
        $response = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());

        $this->assertTrue($response->validate('server-base-paths', 'GET', '/gateway/pets', 200, [], 'application/json')->isValid());
        $result = $response->validate('server-base-paths', 'GET', '/api/pets', 200, [], 'application/json');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("add '/api' to it in gesso.php", $result->errors()[0]);
    }
}
