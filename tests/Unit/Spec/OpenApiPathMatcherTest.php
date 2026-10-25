<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Studio\Gesso\Spec\OpenApiPathMatcher;

use function array_fill;
use function implode;
use function sprintf;

class OpenApiPathMatcherTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function provideMatches_paths_without_strip_prefixCases(): iterable
    {
        return [
            'exact match' => [
                '/v2/account',
                '/v2/account',
            ],
            'parameterized path' => [
                '/v2/projects/abc123',
                '/v2/projects/{project_id}',
            ],
            'multiple parameters' => [
                '/v2/projects/abc123/assets/def456',
                '/v2/projects/{project_id}/assets/{asset_id}',
            ],
            'collection path' => [
                '/v2/projects',
                '/v2/projects',
            ],
            'nested collection' => [
                '/v2/projects/abc123/assets',
                '/v2/projects/{project_id}/assets',
            ],
            'hyphenated path' => [
                '/v2/add-on/plans',
                '/v2/add-on/plans',
            ],
            'trailing slash stripped' => [
                '/v2/account/',
                '/v2/account',
            ],
            'no match returns null' => [
                '/v2/unknown/path',
                null,
            ],
            'workspace members' => [
                '/v2/workspace/ws123/members',
                '/v2/workspace/{workspace_id}/members',
            ],
        ];
    }

    /** @return array<string, array{string, ?string}> */
    public static function provideMatches_paths_with_strip_prefixCases(): iterable
    {
        return [
            'with /api prefix' => [
                '/api/v2/account',
                '/v2/account',
            ],
            'without prefix' => [
                '/v2/account',
                '/v2/account',
            ],
            'parameterized with prefix' => [
                '/api/v2/projects/abc123',
                '/v2/projects/{project_id}',
            ],
            'hyphenated with prefix' => [
                '/api/v2/add-on/plans',
                '/v2/add-on/plans',
            ],
        ];
    }

    /** @return iterable<string, array{string, ?array{path: string, variables: array<string, string>}}> */
    public static function provideMixed_literal_and_parameter_segmentsCases(): iterable
    {
        yield 'literal prefix' => ['/report.json', ['path' => '/report.{format}', 'variables' => ['format' => 'json']]];
        yield 'two parameters around a literal' => ['/v/2-10', ['path' => '/v/{a}-{b}', 'variables' => ['a' => '2', 'b' => '10']]];
        yield 'greedy split on repeated delimiter' => ['/files/archive.tar.gz', ['path' => '/files/{name}.{ext}', 'variables' => ['name' => 'archive.tar', 'ext' => 'gz']]];
        yield 'encoded value stays raw' => ['/files/my%20doc.txt', ['path' => '/files/{name}.{ext}', 'variables' => ['name' => 'my%20doc', 'ext' => 'txt']]];
        yield 'encoded slash stays inside the value' => ['/files/a%2Fb.txt', ['path' => '/files/{name}.{ext}', 'variables' => ['name' => 'a%2Fb', 'ext' => 'txt']]];
        yield 'parameter then literal suffix' => ['/users/7/avatar.png', ['path' => '/users/{id}/avatar.png', 'variables' => ['id' => '7']]];
        yield 'concrete path beats a mixed template' => ['/report.pdf', ['path' => '/report.pdf', 'variables' => []]];
        yield 'mixed template beats a whole-segment template' => ['/report.csv', ['path' => '/report.{format}', 'variables' => ['format' => 'csv']]];
        yield 'whole-segment template still catches the rest' => ['/other', ['path' => '/{file}', 'variables' => ['file' => 'other']]];
        yield 'empty value does not match the expression' => ['/report.', ['path' => '/{file}', 'variables' => ['file' => 'report.']]];
        yield 'slash in value does not match' => ['/files/a/b.txt', null];
        yield 'missing literal does not match' => ['/files/readme', null];
        yield 'two-param segment needs its literal' => ['/v/210', null];
    }

    #[Test]
    #[DataProvider('provideMatches_paths_without_strip_prefixCases')]
    public function matches_paths_without_strip_prefix(string $requestPath, ?string $expected): void
    {
        $matcher = self::createMatcher();
        $this->assertSame($expected, $matcher->match($requestPath));
    }

    #[Test]
    public function specific_path_prioritized_over_parameterized(): void
    {
        $matcher = new OpenApiPathMatcher([
            '/v2/projects/{project_id}',
            '/v2/projects/templates',
        ]);

        $this->assertSame('/v2/projects/templates', $matcher->match('/v2/projects/templates'));
        $this->assertSame('/v2/projects/{project_id}', $matcher->match('/v2/projects/abc123'));
    }

    #[Test]
    public function equivalent_literal_paths_preserve_declaration_order(): void
    {
        $matcher = new OpenApiPathMatcher([
            '/v2/account/',
            '/v2/account',
        ]);

        $this->assertSame('/v2/account/', $matcher->match('/v2/account'));
    }

    #[Test]
    public function equally_specific_templates_preserve_declaration_order(): void
    {
        $matcher = new OpenApiPathMatcher([
            '/{first}/fixed',
            '/fixed/{second}',
        ]);

        $this->assertSame('/{first}/fixed', $matcher->match('/fixed/fixed'));
    }

    #[Test]
    public function combined_template_patterns_preserve_order_across_bounded_chunks(): void
    {
        $paths = [];
        for ($i = 0; $i < 40; $i++) {
            $paths[] = sprintf('/{parameter%d}/fixed', $i);
        }
        $matcher = new OpenApiPathMatcher($paths);

        $this->assertSame('/{parameter0}/fixed', $matcher->match('/value/fixed'));
    }

    #[Test]
    public function combined_template_patterns_extract_variables_from_later_chunks(): void
    {
        $paths = [];
        for ($i = 0; $i < 40; $i++) {
            $paths[] = sprintf('/resource%d/{parameter%d}', $i, $i);
        }
        $matcher = new OpenApiPathMatcher($paths);

        $this->assertSame(
            ['path' => '/resource35/{parameter35}', 'variables' => ['parameter35' => 'value']],
            $matcher->matchWithVariables('/resource35/value'),
        );
    }

    #[Test]
    public function combined_patterns_split_before_the_pcre_named_capture_limit(): void
    {
        $paths = [];
        for ($i = 0; $i < 32; $i++) {
            $paths[] = self::pathWithParameters("/resource{$i}", "parameter{$i}_", 400);
        }
        $matcher = new OpenApiPathMatcher($paths);
        $requestPath = '/resource31/' . implode('/', array_fill(0, 400, 'value'));

        $matched = $matcher->matchWithVariables($requestPath);

        $this->assertNotNull($matched);
        $this->assertSame($paths[31], $matched['path']);
        $this->assertCount(400, $matched['variables']);
        $this->assertSame('value', $matched['variables']['parameter31_399']);
    }

    #[Test]
    public function a_single_template_above_the_pcre_capture_limit_is_rejected_explicitly(): void
    {
        $path = self::pathWithParameters('/large', 'parameter', 10_001);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares 10001 placeholders; a single template may declare at most 10000');

        new OpenApiPathMatcher([$path]);
    }

    #[Test]
    public function a_single_template_above_the_jit_capture_budget_disables_jit(): void
    {
        $path = self::pathWithParameters('/large', 'parameter', 401);
        $matcher = new OpenApiPathMatcher([$path]);
        $compiled = (new ReflectionProperty($matcher, 'compiledPathsBySegmentCount'))->getValue($matcher);

        $this->assertStringStartsWith('#(*NO_JIT)', $compiled[402][0]['pattern']);

        $requestPath = '/large/' . implode('/', array_fill(0, 401, 'x'));
        $matched = $matcher->matchWithVariables($requestPath);
        $this->assertNotNull($matched);
        $this->assertCount(401, $matched['variables']);
    }

    #[Test]
    #[DataProvider('provideMatches_paths_with_strip_prefixCases')]
    public function matches_paths_with_strip_prefix(string $requestPath, ?string $expected): void
    {
        $matcher = new OpenApiPathMatcher(
            [
                '/v2/account',
                '/v2/projects/{project_id}',
                '/v2/add-on/plans',
            ],
            ['/api'],
        );

        $this->assertSame($expected, $matcher->match($requestPath));
    }

    #[Test]
    public function multiple_strip_prefixes_only_first_match_applied(): void
    {
        $matcher = new OpenApiPathMatcher(
            ['/v2/account'],
            ['/api', '/internal'],
        );

        $this->assertSame('/v2/account', $matcher->match('/api/v2/account'));
        $this->assertSame('/v2/account', $matcher->match('/internal/v2/account'));
    }

    #[Test]
    public function empty_strip_prefixes_no_stripping(): void
    {
        $matcher = new OpenApiPathMatcher(
            ['/v2/account'],
            [],
        );

        $this->assertSame('/v2/account', $matcher->match('/v2/account'));
        $this->assertNull($matcher->match('/api/v2/account'));
    }

    #[Test]
    public function match_with_variables_extracts_single_parameter(): void
    {
        $matcher = self::createMatcher();

        $this->assertSame(
            ['path' => '/v2/projects/{project_id}', 'variables' => ['project_id' => 'abc123']],
            $matcher->matchWithVariables('/v2/projects/abc123'),
        );
    }

    #[Test]
    public function match_with_variables_extracts_multiple_parameters(): void
    {
        $matcher = self::createMatcher();

        $this->assertSame(
            [
                'path' => '/v2/projects/{project_id}/assets/{asset_id}',
                'variables' => ['project_id' => 'abc123', 'asset_id' => 'def456'],
            ],
            $matcher->matchWithVariables('/v2/projects/abc123/assets/def456'),
        );
    }

    #[Test]
    public function match_with_variables_returns_empty_variables_for_literal_path(): void
    {
        $matcher = self::createMatcher();

        $this->assertSame(
            ['path' => '/v2/account', 'variables' => []],
            $matcher->matchWithVariables('/v2/account'),
        );
    }

    #[Test]
    public function match_with_variables_returns_null_when_no_match(): void
    {
        $matcher = self::createMatcher();

        $this->assertNull($matcher->matchWithVariables('/v2/unknown/path'));
    }

    #[Test]
    public function parameterized_paths_require_one_leading_slash(): void
    {
        $matcher = new OpenApiPathMatcher(['/v2/projects/{project_id}']);

        $this->assertNull($matcher->matchWithVariables('v2/projects/abc123'));
        $this->assertNull($matcher->matchWithVariables('//v2/projects/abc123'));
    }

    #[Test]
    public function parameterized_paths_reject_empty_segments(): void
    {
        $matcher = new OpenApiPathMatcher(['/v2/projects/{project_id}/assets']);

        $this->assertNull($matcher->matchWithVariables('/v2/projects//assets'));
    }

    #[Test]
    public function match_with_variables_preserves_percent_encoded_value(): void
    {
        $matcher = new OpenApiPathMatcher(['/v2/orders/{orderId}']);

        $this->assertSame(
            ['path' => '/v2/orders/{orderId}', 'variables' => ['orderId' => 'a1b2%2D3c4d']],
            $matcher->matchWithVariables('/v2/orders/a1b2%2D3c4d'),
        );
    }

    #[Test]
    public function match_with_variables_honors_strip_prefix(): void
    {
        $matcher = new OpenApiPathMatcher(
            ['/v2/projects/{project_id}'],
            ['/api'],
        );

        $this->assertSame(
            ['path' => '/v2/projects/{project_id}', 'variables' => ['project_id' => 'abc123']],
            $matcher->matchWithVariables('/api/v2/projects/abc123'),
        );
    }

    #[Test]
    public function normalize_request_path_strips_configured_prefix(): void
    {
        $matcher = new OpenApiPathMatcher(['/v2/account'], ['/api']);

        $this->assertSame(
            ['path' => '/v2/account', 'strippedPrefix' => '/api'],
            $matcher->normalizeRequestPath('/api/v2/account'),
        );
    }

    #[Test]
    public function normalize_request_path_returns_null_prefix_when_none_matches(): void
    {
        $matcher = new OpenApiPathMatcher(['/v2/account'], ['/api']);

        $this->assertSame(
            ['path' => '/v2/account', 'strippedPrefix' => null],
            $matcher->normalizeRequestPath('/v2/account'),
        );
    }

    #[Test]
    public function normalize_request_path_trims_trailing_slash(): void
    {
        $matcher = new OpenApiPathMatcher(['/v2/account']);

        $this->assertSame(
            ['path' => '/v2/account', 'strippedPrefix' => null],
            $matcher->normalizeRequestPath('/v2/account/'),
        );
    }

    #[Test]
    public function normalize_request_path_preserves_root_slash(): void
    {
        $matcher = new OpenApiPathMatcher(['/']);

        // The trailing-slash trim must not collapse the root path itself,
        // otherwise a request to `/` would normalize to an empty string and
        // never match the literal root entry.
        $this->assertSame(
            ['path' => '/', 'strippedPrefix' => null],
            $matcher->normalizeRequestPath('/'),
        );
    }

    #[Test]
    public function normalize_request_path_only_strips_first_matching_prefix(): void
    {
        // Mirrors the behaviour of matchWithVariables(): once a prefix
        // strips, subsequent prefixes are not considered. Ensures
        // `strippedPrefix` reports the actually-applied prefix rather than
        // the last entry in the array.
        $matcher = new OpenApiPathMatcher(['/v2/account'], ['/api', '/internal']);

        $this->assertSame(
            ['path' => '/v2/account', 'strippedPrefix' => '/api'],
            $matcher->normalizeRequestPath('/api/v2/account'),
        );
        $this->assertSame(
            ['path' => '/v2/account', 'strippedPrefix' => '/internal'],
            $matcher->normalizeRequestPath('/internal/v2/account'),
        );
    }

    #[Test]
    public function constructor_rejects_duplicate_placeholder_names(): void
    {
        // OpenAPI forbids the same placeholder name appearing twice in one template.
        // Silently overwriting earlier captures would let one of the two segments bypass
        // contract validation entirely (direction-dependent silent pass), so the matcher
        // refuses to compile such a template.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate path placeholder name 'id' in spec path '/a/{id}/b/{id}'");

        new OpenApiPathMatcher(['/a/{id}/b/{id}']);
    }

    /** @param ?array{path: string, variables: array<string, string>} $expected */
    #[DataProvider('provideMixed_literal_and_parameter_segmentsCases')]
    #[Test]
    public function mixed_literal_and_parameter_segments(string $requestPath, ?array $expected): void
    {
        // OpenAPI 3.2 §4.8.2: path-segment = 1*( path-literal / template-expression ).
        // Declared whole-segment first to prove the specificity sort, not order, wins.
        $matcher = new OpenApiPathMatcher([
            '/{file}',
            '/report.{format}',
            '/report.pdf',
            '/v/{a}-{b}',
            '/files/{name}.{ext}',
            '/users/{id}/avatar.png',
        ]);

        $this->assertSame($expected, $matcher->matchWithVariables($requestPath));
    }

    #[Test]
    public function constructor_rejects_adjacent_placeholders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Adjacent path placeholders with no literal between them in spec path '/v/{a}{b}'");

        new OpenApiPathMatcher(['/v/{a}{b}']);
    }

    #[Test]
    public function constructor_rejects_duplicate_placeholder_names_within_one_segment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate path placeholder name 'id' in spec path '/v/{id}-{id}'");

        new OpenApiPathMatcher(['/v/{id}-{id}']);
    }

    private static function createMatcher(): OpenApiPathMatcher
    {
        return new OpenApiPathMatcher([
            '/v2/account',
            '/v2/projects',
            '/v2/projects/{project_id}',
            '/v2/projects/{project_id}/assets',
            '/v2/projects/{project_id}/assets/{asset_id}',
            '/v2/plans',
            '/v2/workspace/{workspace_id}/members',
            '/v2/add-on/plans',
        ]);
    }

    private static function pathWithParameters(string $prefix, string $parameterPrefix, int $count): string
    {
        $segments = [$prefix];
        for ($i = 0; $i < $count; $i++) {
            $segments[] = sprintf('{%s%d}', $parameterPrefix, $i);
        }

        return implode('/', $segments);
    }
}
