<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Validation\Support\PathDiagnosticsFormatter;

/**
 * The server-base-path hint pinned by ADR 0006: `servers[].url` never
 * participates in matching, so a failed match has to name the prefix the spec
 * already declared instead of leaving the reader to derive it.
 */
class PathDiagnosticsFormatterTest extends TestCase
{
    #[Test]
    public function names_the_declared_server_base_path_when_removing_it_would_match(): void
    {
        $message = $this->pathNotFound('/api/pets', [['url' => '/api']]);

        $this->assertStringContainsString(
            "servers[0].url declares base path '/api'; '/pets' matches after removing it.",
            $message,
        );
        $this->assertStringContainsString(
            "Gesso does not strip server base paths automatically — add '/api' to strip_prefixes.",
            $message,
        );
    }

    #[Test]
    public function takes_the_path_component_of_an_absolute_server_url(): void
    {
        $message = $this->pathNotFound('/v1/pets', [['url' => 'https://api.example.com/v1/']]);

        $this->assertStringContainsString(
            "servers[0].url declares base path '/v1'; '/pets' matches after removing it.",
            $message,
        );
    }

    #[Test]
    public function reports_the_first_declared_server_whose_removal_matches(): void
    {
        $message = $this->pathNotFound('/v2/pets', [
            ['url' => '/v1'],
            ['url' => 'https://api.example.com'],
            ['url' => '/v2'],
        ]);

        $this->assertStringContainsString("servers[2].url declares base path '/v2'", $message);
    }

    #[Test]
    public function stays_silent_when_removing_the_base_path_still_does_not_match(): void
    {
        $message = $this->pathNotFound('/api/unknown', [['url' => '/api']]);

        $this->assertStringNotContainsString('declares base path', $message);
        $this->assertStringContainsString('closest spec paths:', $message);
    }

    #[Test]
    public function stays_silent_when_the_server_declares_no_base_path(): void
    {
        $message = $this->pathNotFound('/api/pets', [['url' => 'https://api.example.com']]);

        $this->assertStringNotContainsString('declares base path', $message);
    }

    /**
     * Server variables are never resolved: a path still holding `{variable}`
     * segments cannot literally prefix a request path, so it drops out without
     * a special case rather than guessing a value.
     */
    #[Test]
    public function stays_silent_when_the_base_path_holds_unresolved_variables(): void
    {
        $message = $this->pathNotFound('/api/pets', [['url' => 'https://example.com/{basePath}']]);

        $this->assertStringNotContainsString('declares base path', $message);
    }

    #[Test]
    public function tolerates_servers_that_are_not_a_list_of_objects_with_urls(): void
    {
        $this->assertStringNotContainsString(
            'declares base path',
            $this->pathNotFound('/api/pets', 'https://api.example.com/api'),
        );
        $this->assertStringNotContainsString(
            'declares base path',
            $this->pathNotFound('/api/pets', [['description' => 'no url'], 'not-an-object', ['url' => 42]]),
        );
    }

    /**
     * @param list<mixed>|string $servers
     */
    private function pathNotFound(string $requestPath, array|string $servers): string
    {
        $spec = [
            'servers' => $servers,
            'paths' => ['/pets' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
        ];

        return PathDiagnosticsFormatter::pathNotFound(
            'petstore',
            'get',
            $requestPath,
            new OpenApiPathMatcher(['/pets']),
            $spec,
        );
    }
}
