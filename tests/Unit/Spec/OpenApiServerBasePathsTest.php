<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiServerBasePaths;

use function array_keys;

/** ADR 0008: which base paths the spec's servers declare, and what they match. */
final class OpenApiServerBasePathsTest extends TestCase
{
    #[Test]
    public function operation_and_path_item_servers_replace_the_level_above(): void
    {
        $this->assertSame(
            ['/internal' => ['/stats', '/users'], '/api' => ['/pets', '/users']],
            OpenApiServerBasePaths::fromSpec([
                'servers' => [['url' => 'https://example.com/api/']],
                'paths' => [
                    '/pets' => ['get' => []],
                    '/stats' => ['servers' => [['url' => '/internal']], 'get' => []],
                    '/users' => ['get' => [], 'delete' => ['servers' => [['url' => '/internal']]]],
                ],
            ]),
        );
    }

    #[Test]
    public function reads_variable_defaults_and_skips_urls_without_a_base_path(): void
    {
        $this->assertSame(['/v1'], array_keys(OpenApiServerBasePaths::fromSpec([
            'servers' => [
                ['url' => 'https://{env}.example.com/{version}', 'variables' => ['env' => ['default' => 'prod'], 'version' => ['default' => 'v1']]],
                ['url' => 'https://example.com'],
                ['url' => '/'],
                ['url' => '/{undeclared}'],
                ['description' => 'no url'],
                'not-an-object',
            ],
            'paths' => ['/pets' => ['get' => []]],
        ])));
        $this->assertSame([], OpenApiServerBasePaths::fromSpec(['servers' => '/api', 'paths' => ['/pets' => ['get' => []]]]));
    }

    #[Test]
    public function the_path_as_written_wins_over_a_base_path(): void
    {
        $matcher = $this->matcher(['/pets', '/api/pets'], [['url' => '/api']]);

        $this->assertSame('/api/pets', $matcher->match('/api/pets'));
        $this->assertSame('/pets', $matcher->match('/pets'));
    }

    #[Test]
    public function a_base_path_is_removed_only_at_a_segment_boundary(): void
    {
        $matcher = $this->matcher(['/pets', '/', '/2/pets'], [['url' => '/api']]);

        $this->assertSame('/pets', $matcher->match('/api/pets'));
        $this->assertSame('/', $matcher->match('/api'));
        $this->assertSame('/', $matcher->match('/api/'));
        $this->assertNull($matcher->match('/api2/pets'));
    }

    #[Test]
    public function every_declared_base_path_is_tried(): void
    {
        $matcher = $this->matcher(['/pets'], [['url' => '/v1'], ['url' => '/v2']]);

        $this->assertSame('/pets', $matcher->match('/v1/pets'));
        $this->assertSame('/pets', $matcher->match('/v2/pets'));
    }

    #[Test]
    public function two_base_paths_matching_different_paths_match_neither(): void
    {
        $matcher = $this->matcher(['/pets', '/v1/pets'], [['url' => '/api'], ['url' => '/api/v1']]);

        $this->assertNull($matcher->match('/api/v1/pets'));
    }

    #[Test]
    public function configured_strip_prefixes_turn_base_paths_off(): void
    {
        $spec = ['servers' => [['url' => '/api']], 'paths' => ['/pets' => ['get' => []]]];
        $matcher = new OpenApiPathMatcher(['/pets'], ['/gateway'], OpenApiServerBasePaths::fromSpec($spec));

        $this->assertNull($matcher->match('/api/pets'));
        $this->assertSame('/pets', $matcher->match('/gateway/pets'));
    }

    /**
     * @param list<string> $templates
     * @param list<array<string, mixed>> $servers
     */
    private function matcher(array $templates, array $servers): OpenApiPathMatcher
    {
        $paths = [];
        foreach ($templates as $template) {
            $paths[$template] = ['get' => []];
        }

        return new OpenApiPathMatcher($templates, [], OpenApiServerBasePaths::fromSpec(['servers' => $servers, 'paths' => $paths]));
    }
}
