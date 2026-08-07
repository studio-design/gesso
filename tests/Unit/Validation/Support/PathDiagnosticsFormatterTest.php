<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Validation\Support\PathDiagnosticsFormatter;

use function implode;

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
     * A root path item is reachable only through the trailing slash: the
     * matcher strips the prefix first and trims the trailing slash second, so
     * `strip_prefixes=['/api']` turns `/api/` into `/` and `/api` into the
     * empty string. The hint has to agree with both halves of that.
     */
    #[Test]
    public function names_the_base_path_when_removing_it_leaves_the_root_path(): void
    {
        $message = $this->pathNotFound('/api/', [['url' => '/api']], ['/']);

        $this->assertStringContainsString(
            "servers[0].url declares base path '/api'; '/' matches after removing it.",
            $message,
        );
    }

    #[Test]
    public function stays_silent_when_removing_the_base_path_leaves_nothing_to_match(): void
    {
        $message = $this->pathNotFound('/api', [['url' => '/api']], ['/']);

        $this->assertStringNotContainsString('declares base path', $message);
    }

    /**
     * `default` is REQUIRED on a Server Variable Object and is what "SHALL be
     * sent if an alternate value is not supplied", so a templated base path has
     * one concrete reading and the hint uses it.
     *
     * @see https://spec.openapis.org/oas/v3.0.4.html#server-variable-object
     * @see https://spec.openapis.org/oas/v3.2.0.html#server-variable-object
     */
    #[Test]
    public function substitutes_server_variable_defaults_before_taking_the_base_path(): void
    {
        $message = $this->pathNotFound('/api/v1/pets', [[
            'url' => '/api/{version}',
            'variables' => ['version' => ['default' => 'v1', 'enum' => ['v1', 'v2']]],
        ]]);

        $this->assertStringContainsString(
            "servers[0].url declares base path '/api/v1'; '/pets' matches after removing it.",
            $message,
        );
    }

    #[Test]
    public function substitutes_defaults_in_the_host_without_disturbing_the_base_path(): void
    {
        $message = $this->pathNotFound('/v1/pets', [[
            'url' => 'https://{env}.example.com/v1',
            'variables' => ['env' => ['default' => 'staging']],
        ]]);

        $this->assertStringContainsString("servers[0].url declares base path '/v1'", $message);
    }

    /**
     * A variable the document leaves without a usable default stays in braces
     * and cannot literally prefix a request path, so it drops out rather than
     * being guessed at.
     */
    #[Test]
    public function stays_silent_when_a_server_variable_has_no_usable_default(): void
    {
        $this->assertStringNotContainsString(
            'declares base path',
            $this->pathNotFound('/api/pets', [['url' => 'https://example.com/{basePath}']]),
        );
        $this->assertStringNotContainsString(
            'declares base path',
            $this->pathNotFound('/api/pets', [[
                'url' => '/{basePath}',
                'variables' => ['basePath' => ['enum' => ['api']]],
            ]]),
        );
        $this->assertStringNotContainsString(
            'declares base path',
            $this->pathNotFound('/api/pets', [[
                'url' => '/{basePath}',
                'variables' => ['other' => ['default' => 'api']],
            ]]),
        );
    }

    /**
     * The matcher removes at most one prefix per request, so a hint that only
     * reached a spec path by letting an already-configured prefix come off a
     * second time would advertise a configuration that cannot be built.
     */
    #[Test]
    public function stays_silent_when_matching_would_take_a_second_prefix_removal(): void
    {
        $message = $this->pathNotFound(
            '/api/v1/pets',
            [['url' => '/api']],
            stripPrefixes: ['/v1'],
        );

        $this->assertStringNotContainsString('declares base path', $message);

        // Silence is the correct answer: adding '/api' cannot work in either
        // order, because only one prefix is ever removed.
        foreach ([['/v1', '/api'], ['/api', '/v1']] as $configured) {
            $this->assertNull(
                (new OpenApiPathMatcher(['/pets'], $configured))->match('/api/v1/pets'),
                'strip_prefixes=' . implode(',', $configured),
            );
        }
    }

    /**
     * When a configured prefix already matched, it and the server base path both
     * start at offset 0 and one contains the other, so which wins is a question
     * of list order — advice a one-line hint cannot give.
     */
    #[Test]
    public function stays_silent_when_a_configured_prefix_already_matched_the_request_path(): void
    {
        $message = $this->pathNotFound(
            '/api/pets',
            [['url' => '/api']],
            stripPrefixes: ['/a'],
        );

        $this->assertStringContainsString("searched as: pi/pets (after stripping prefix '/a')", $message);
        $this->assertStringNotContainsString('declares base path', $message);
    }

    #[Test]
    public function still_fires_when_the_configured_prefixes_do_not_match_the_request_path(): void
    {
        $message = $this->pathNotFound(
            '/api/pets',
            [['url' => '/api']],
            stripPrefixes: ['/gateway'],
        );

        $this->assertStringContainsString("servers[0].url declares base path '/api'", $message);

        // And the advice works: nothing configured matches the raw path, so the
        // appended prefix is the one that wins.
        $this->assertSame(
            '/pets',
            (new OpenApiPathMatcher(['/pets'], ['/gateway', '/api']))->match('/api/pets'),
        );
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
     * @param list<string> $specPaths
     * @param list<string> $stripPrefixes
     */
    private function pathNotFound(
        string $requestPath,
        array|string $servers,
        array $specPaths = ['/pets'],
        array $stripPrefixes = [],
    ): string {
        $paths = [];
        foreach ($specPaths as $specPath) {
            $paths[$specPath] = ['get' => ['responses' => ['200' => ['description' => 'ok']]]];
        }

        return PathDiagnosticsFormatter::pathNotFound(
            'petstore',
            'get',
            $requestPath,
            new OpenApiPathMatcher($specPaths, $stripPrefixes),
            ['servers' => $servers, 'paths' => $paths],
        );
    }
}
