<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Spec\OpenApiPathSuggester;

use function array_map;
use function sort;

class OpenApiPathSuggesterTest extends TestCase
{
    #[Test]
    public function suggest_returns_empty_array_for_spec_without_paths(): void
    {
        $this->assertSame([], OpenApiPathSuggester::suggest([], '/v1/pets'));
        $this->assertSame([], OpenApiPathSuggester::suggest(['paths' => []], '/v1/pets'));
    }

    #[Test]
    public function suggest_returns_empty_array_for_non_array_paths_value(): void
    {
        // A malformed spec where `paths` decoded to a non-mapping (null, scalar)
        // would TypeError on `foreach` if not guarded. The diagnostic helper
        // must never compound a primary failure with a TypeError of its own —
        // the user is already debugging an unmatched path.
        $this->assertSame([], OpenApiPathSuggester::suggest(['paths' => null], '/v1/x'));
        $this->assertSame([], OpenApiPathSuggester::suggest(['paths' => 'oops'], '/v1/x'));
        $this->assertSame([], OpenApiPathSuggester::suggest(['paths' => 42], '/v1/x'));
    }

    #[Test]
    public function methods_for_path_returns_empty_array_for_non_array_paths_value(): void
    {
        $this->assertSame([], OpenApiPathSuggester::methodsForPath(['paths' => null], '/v1/x'));
        $this->assertSame([], OpenApiPathSuggester::methodsForPath(['paths' => 'oops'], '/v1/x'));
        $this->assertSame([], OpenApiPathSuggester::methodsForPath(['paths' => 42], '/v1/x'));
    }

    #[Test]
    public function suggest_filters_non_operation_keys_at_path_item_level(): void
    {
        // OpenAPI 3.x permits `parameters`, `summary`, `description`, `servers`,
        // `$ref` at the path-item level alongside operation methods. The
        // suggester must not surface these as suggestions or it would render
        // entries like "PARAMETERS /v1/pets" — meaningless and misleading.
        $spec = [
            'paths' => [
                '/v1/pets' => [
                    'parameters' => [],
                    'summary' => 'Pets',
                    'description' => 'pet ops',
                    'servers' => [],
                    'get' => ['responses' => []],
                    'post' => ['responses' => []],
                ],
            ],
        ];

        $result = OpenApiPathSuggester::suggest($spec, '/v1/pets', 10);

        $this->assertSame(
            [
                ['method' => 'GET', 'path' => '/v1/pets'],
                ['method' => 'POST', 'path' => '/v1/pets'],
            ],
            $result,
        );
    }

    #[Test]
    public function suggest_recognises_all_openapi_path_item_methods(): void
    {
        // OpenAPI 3.2 adds QUERY to the eight operation keys from 3.0/3.1.
        // Any future broadening of HttpMethod for request validation must not
        // silently drop one of these from suggestions.
        $methods = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace', 'query'];
        $pathItem = [];
        foreach ($methods as $m) {
            $pathItem[$m] = ['responses' => []];
        }
        $spec = ['paths' => ['/v1/op' => $pathItem]];

        $result = OpenApiPathSuggester::suggest($spec, '/v1/op', 10);
        $resultMethods = array_map(static fn(array $r): string => $r['method'], $result);
        sort($resultMethods);

        $this->assertSame(
            ['DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'QUERY', 'TRACE'],
            $resultMethods,
        );
    }

    #[Test]
    public function suggest_and_methods_include_additional_operations(): void
    {
        $spec = [
            'paths' => [
                '/v1/pets' => [
                    'get' => ['responses' => []],
                    'additionalOperations' => [
                        'COPY' => ['responses' => []],
                    ],
                ],
            ],
        ];

        $suggestions = OpenApiPathSuggester::suggest($spec, '/v1/pets', 10);

        $this->assertContains(['method' => 'COPY', 'path' => '/v1/pets'], $suggestions);
        $this->assertSame(['COPY', 'GET'], OpenApiPathSuggester::methodsForPath($spec, '/v1/pets'));
    }

    #[Test]
    public function suggest_prioritises_segment_count_match_over_levenshtein_distance(): void
    {
        // /a/b/c/d/e is character-wise close to /a/b/c (small Levenshtein) but
        // segment-count match should win — `/x/y/z/q/r` shares the segment
        // count even though no segment text matches.
        $spec = [
            'paths' => [
                '/a/b/c' => ['get' => ['responses' => []]],
                '/x/y/z/q/r' => ['get' => ['responses' => []]],
            ],
        ];

        $result = OpenApiPathSuggester::suggest($spec, '/a/b/c/d/e', 1);

        $this->assertSame(
            [['method' => 'GET', 'path' => '/x/y/z/q/r']],
            $result,
        );
    }

    #[Test]
    public function suggest_uses_common_prefix_segments_as_secondary_sort(): void
    {
        // All three candidates share segment count (3). Common prefix segment
        // count breaks the tie — /v2/admin/users shares two segments with the
        // request, /v2/users/list shares one, /unrelated/x/y shares zero.
        $spec = [
            'paths' => [
                '/unrelated/x/y' => ['get' => ['responses' => []]],
                '/v2/users/list' => ['get' => ['responses' => []]],
                '/v2/admin/users' => ['get' => ['responses' => []]],
            ],
        ];

        $result = OpenApiPathSuggester::suggest($spec, '/v2/admin/list', 3);

        $this->assertSame(
            [
                ['method' => 'GET', 'path' => '/v2/admin/users'],
                ['method' => 'GET', 'path' => '/v2/users/list'],
                ['method' => 'GET', 'path' => '/unrelated/x/y'],
            ],
            $result,
        );
    }

    #[Test]
    public function suggest_uses_levenshtein_on_tail_after_common_prefix(): void
    {
        // Both candidates share segment count (3) and common prefix length (2:
        // /v2/admin). The differing tail (`users` vs `userrs`) is what
        // distinguishes them — Levenshtein on the post-prefix substring puts
        // `userrs` (distance 1 from `usrs`) above `groups` (distance 5).
        $spec = [
            'paths' => [
                '/v2/admin/groups' => ['get' => ['responses' => []]],
                '/v2/admin/userrs' => ['get' => ['responses' => []]],
            ],
        ];

        $result = OpenApiPathSuggester::suggest($spec, '/v2/admin/usrs', 1);

        $this->assertSame(
            [['method' => 'GET', 'path' => '/v2/admin/userrs']],
            $result,
        );
    }

    #[Test]
    public function suggest_falls_back_to_alphabetical_when_all_scores_tie(): void
    {
        // All three candidates share segment count, common prefix length, and
        // a single-character differing tail — every numeric scoring tier ties.
        // Alphabetical fallback keeps the suggestion list stable across PHP
        // hash randomisation.
        $spec = [
            'paths' => [
                '/v1/c' => ['get' => ['responses' => []]],
                '/v1/a' => ['get' => ['responses' => []]],
                '/v1/b' => ['get' => ['responses' => []]],
            ],
        ];

        $result = OpenApiPathSuggester::suggest($spec, '/v1/x', 3);

        $this->assertSame(
            [
                ['method' => 'GET', 'path' => '/v1/a'],
                ['method' => 'GET', 'path' => '/v1/b'],
                ['method' => 'GET', 'path' => '/v1/c'],
            ],
            $result,
        );
    }

    #[Test]
    public function suggest_includes_multiple_methods_for_the_same_top_path(): void
    {
        // Mirrors the issue's example: when one path scores best and has
        // multiple operations, both operations should occupy slots in the
        // top-N output rather than being deduplicated to the path alone.
        $spec = [
            'paths' => [
                '/v2/admin/early_accesses' => [
                    'get' => ['responses' => []],
                    'post' => ['responses' => []],
                ],
                '/v2/admin/users/{user_id}' => [
                    'get' => ['responses' => []],
                ],
                '/unrelated' => ['get' => ['responses' => []]],
            ],
        ];

        $result = OpenApiPathSuggester::suggest($spec, '/v2/admin/early-access', 3);

        $this->assertSame(
            [
                ['method' => 'GET', 'path' => '/v2/admin/early_accesses'],
                ['method' => 'POST', 'path' => '/v2/admin/early_accesses'],
                ['method' => 'GET', 'path' => '/v2/admin/users/{user_id}'],
            ],
            $result,
        );
    }

    #[Test]
    public function suggest_respects_limit_argument(): void
    {
        $spec = [
            'paths' => [
                '/a' => ['get' => ['responses' => []]],
                '/b' => ['get' => ['responses' => []]],
                '/c' => ['get' => ['responses' => []]],
                '/d' => ['get' => ['responses' => []]],
            ],
        ];

        $this->assertCount(2, OpenApiPathSuggester::suggest($spec, '/x', 2));
        $this->assertCount(4, OpenApiPathSuggester::suggest($spec, '/x', 10));
    }

    #[Test]
    public function suggest_returns_empty_array_for_non_positive_limit(): void
    {
        // PHP's array_slice treats negative offsets as "count from the end",
        // so passing a negative $limit would produce a surprising non-empty
        // result rather than the expected "no suggestions". Pin the
        // documented behaviour: zero or negative limit ⇒ no suggestions.
        $spec = [
            'paths' => [
                '/a' => ['get' => ['responses' => []]],
                '/b' => ['get' => ['responses' => []]],
            ],
        ];

        $this->assertSame([], OpenApiPathSuggester::suggest($spec, '/x', 0));
        $this->assertSame([], OpenApiPathSuggester::suggest($spec, '/x', -1));
    }

    #[Test]
    public function suggest_ignores_non_openapi_method_keys(): void
    {
        // OpenAPI 3.x defines exactly 8 path-item operation methods. HTTP
        // verbs that exist outside that set (`connect`, `link`, `unlink`,
        // arbitrary spec-author typos like `gett`) must not leak into
        // suggestion output even when they sit alongside legitimate
        // operations. Locks the path-item method allowlist against
        // accidental expansion.
        $spec = [
            'paths' => [
                '/v1/op' => [
                    'connect' => ['responses' => []],
                    'link' => ['responses' => []],
                    'gett' => ['responses' => []],
                    'get' => ['responses' => []],
                ],
            ],
        ];

        $this->assertSame(
            [['method' => 'GET', 'path' => '/v1/op']],
            OpenApiPathSuggester::suggest($spec, '/v1/op', 10),
        );
        $this->assertSame(
            ['GET'],
            OpenApiPathSuggester::methodsForPath($spec, '/v1/op'),
        );
    }

    #[Test]
    public function suggest_ignores_non_array_path_item_entries(): void
    {
        // A spec with an unresolved $ref or a malformed entry (e.g. scalar
        // instead of object) should be skipped silently rather than tripping
        // a TypeError. Suggestions are diagnostic, not authoritative — they
        // should never make a failing test fail twice.
        $spec = [
            'paths' => [
                '/v1/broken' => 'oops',
                '/v1/ok' => ['get' => ['responses' => []]],
            ],
        ];

        $this->assertSame(
            [['method' => 'GET', 'path' => '/v1/ok']],
            OpenApiPathSuggester::suggest($spec, '/v1/x', 10),
        );
    }

    #[Test]
    public function methods_for_path_returns_uppercase_sorted_methods(): void
    {
        $spec = [
            'paths' => [
                '/v1/pets' => [
                    'parameters' => [],
                    'post' => ['responses' => []],
                    'get' => ['responses' => []],
                    'delete' => ['responses' => []],
                ],
            ],
        ];

        $this->assertSame(
            ['DELETE', 'GET', 'POST'],
            OpenApiPathSuggester::methodsForPath($spec, '/v1/pets'),
        );
    }

    #[Test]
    public function methods_for_path_returns_empty_when_path_missing(): void
    {
        $this->assertSame(
            [],
            OpenApiPathSuggester::methodsForPath(['paths' => []], '/v1/missing'),
        );
        $this->assertSame(
            [],
            OpenApiPathSuggester::methodsForPath([], '/v1/missing'),
        );
    }

    #[Test]
    public function methods_for_path_returns_empty_when_only_non_method_keys_present(): void
    {
        // Edge case: a path item with only `parameters` and no operations is
        // technically valid OpenAPI (e.g. shared parameter definitions). The
        // helper must distinguish "no methods defined" from "lookup missed".
        $spec = [
            'paths' => [
                '/v1/orphan' => [
                    'parameters' => [],
                    'summary' => 'unused',
                ],
            ],
        ];

        $this->assertSame(
            [],
            OpenApiPathSuggester::methodsForPath($spec, '/v1/orphan'),
        );
    }
}
